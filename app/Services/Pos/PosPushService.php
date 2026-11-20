<?php

namespace App\Services\Pos;

use App\Models\Tenant\TenantInventoryItem;
use App\Models\Tenant\TenantOrder;
use App\Models\Tenant\TenantPosConnection;
use App\Models\Tenant\TenantSale;
use App\Models\Tenant\TenantSaleItem;
use App\Services\Pos\Adapters\PosAdapterRegistry;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * MARKER-CONNECTED-POS-PUSH: Intake → Connected POS.
 *
 * Every send is a row in tenant_pos_pushes, keyed by what it is for, so a retry
 * or a double event can never record a sale twice. Sends run in queued jobs and
 * report failures to Issues; the Connected POS tab lists them with Retry.
 *
 * Ownership rules:
 *  - Price belongs to the Connected POS. Intake only sends a price when it creates an item.
 *  - Name, SKU, barcode and description are shared. A change goes through when the
 *    other side has not also changed that field since they last agreed (the
 *    snapshot); when both have, nothing is overwritten and the item waits under
 *    Needs review for the shop to choose.
 */
class PosPushService
{
    /** Fields both sides may edit: Intake column => normalised POS key. */
    public const SHARED = ['name' => 'name', 'sku' => 'sku', 'catalog_upc' => 'upc', 'description' => 'description'];

    public function __construct(private PosAdapterRegistry $registry, private PosConnectionService $conns) {}

    public static function connectionFor(string $tenantId): ?TenantPosConnection
    {
        return TenantPosConnection::where('tenant_id', $tenantId)->where('status', 'connected')->first();
    }

    /**
     * Queue a send once; returns the push row (existing one when already queued or done).
     *
     * MARKER-POS-DRAIN: a send is only a row. One paced worker per shop (PosDrainJob)
     * works through that shop's rows — never one queue job per send, which flooded the
     * shared queue and stalled everything behind it.
     */
    public function queue(string $tenantId, string $kind, string $refId, string $label, bool $kick = true): object
    {
        $row = DB::table('tenant_pos_pushes')->where('tenant_id', $tenantId)->where('kind', $kind)->where('ref_id', $refId)->first();
        if (! $row) {
            $id = DB::table('tenant_pos_pushes')->insertGetId([
                'tenant_id' => $tenantId, 'kind' => $kind, 'ref_id' => $refId, 'label' => Str::limit($label, 195),
                'status' => 'pending', 'created_at' => now(), 'updated_at' => now(),
            ]);
            $row = DB::table('tenant_pos_pushes')->find($id);
        } elseif (($kind === 'item_update' && $row->status !== 'pending') || in_array($row->status, ['failed', 'skipped'], true)) {
            // an item can change many times, and a failed send asked for again gets another go
            DB::table('tenant_pos_pushes')->where('id', $row->id)->update(['status' => 'pending', 'attempts' => 0, 'last_error' => null, 'updated_at' => now()]);
            $row->status = 'pending';
        }
        if ($kick && $row->status === 'pending') {
            self::kick($tenantId, $kind === 'refund' ? 15 : 2);
        }

        return $row;
    }

    /** Make sure this shop's worker is coming (at most one waiting at a time). */
    public static function kick(string $tenantId, int $delaySeconds = 2): void
    {
        if (\Illuminate\Support\Facades\Cache::add('pos_drain_queued:' . $tenantId, 1, 120)) {
            \App\Jobs\PosDrainJob::dispatch($tenantId)->afterCommit()->delay(now()->addSeconds($delaySeconds));
        }
    }

    /**
     * Work through this shop's waiting sends for up to ~90 seconds: orders first,
     * then edits, then new items. A send that fails waits longer before each try
     * (1, then 5 minutes) and is marked Failed after 3 tries.
     *
     * @return int|null seconds until the next waiting send is due (0 = now), or null when nothing waits
     */
    public function drain(string $tenantId): ?int
    {
        $until = microtime(true) + 90;
        while (microtime(true) < $until) {
            $row = DB::table('tenant_pos_pushes')->where('tenant_id', $tenantId)->where('status', 'pending')
                ->where(fn ($q) => $q->where('attempts', 0)
                    ->orWhere(fn ($q) => $q->where('attempts', 1)->where('updated_at', '<', now()->subMinute()))
                    ->orWhere(fn ($q) => $q->where('attempts', '>=', 2)->where('updated_at', '<', now()->subMinutes(5))))
                ->orderByRaw("CASE kind WHEN 'sale' THEN 0 WHEN 'refund' THEN 1 WHEN 'item_update' THEN 2 ELSE 3 END")->orderBy('id')
                ->first();
            if (! $row) {
                break;
            }
            try {
                $this->run((int) $row->id);
            } catch (\Throwable $e) {
                $attempts = (int) DB::table('tenant_pos_pushes')->where('id', $row->id)->value('attempts');
                if ($attempts >= 3) {
                    $this->mark((int) $row->id, 'failed', null, $e->getMessage());
                    // MARKER-POS-ITEM-FIELDS: every failed order or refund is reported; item failures once
                    // per shop per kind of error per hour, with how many were held back
                    $sig = 'pos_alert:' . $tenantId . ':' . md5(preg_replace('/[0-9]+/', '#', $e->getMessage()));
                    if (in_array($row->kind, ['sale', 'refund'], true) || \Illuminate\Support\Facades\Cache::add($sig, 1, 3600)) {
                        $held = (int) \Illuminate\Support\Facades\Cache::pull($sig . ':held', 0);
                        \App\Support\JobFailureReporter::report(\App\Jobs\PosDrainJob::class, 'Connected POS send failed: ' . $row->label . ($held ? " (+{$held} more like it this hour)" : ''), $e, ['push' => $row->id], $tenantId);
                    } else {
                        \Illuminate\Support\Facades\Cache::increment($sig . ':held');
                    }
                } else {
                    DB::table('tenant_pos_pushes')->where('id', $row->id)->update(['last_error' => Str::limit($e->getMessage(), 900), 'updated_at' => now()]);
                }
            }
        }

        // MARKER-POS-DRAIN: when what's left is pausing after a failure, sleep until the first is due
        // instead of waking every few seconds to find nothing to do
        $due = null;
        foreach (DB::table('tenant_pos_pushes')->where('tenant_id', $tenantId)->where('status', 'pending')->get(['attempts', 'updated_at']) as $p) {
            $wait = (int) $p->attempts === 0 ? 0 : max(0, \Illuminate\Support\Carbon::parse($p->updated_at)->addMinutes((int) $p->attempts === 1 ? 1 : 5)->diffInSeconds(now(), false) * -1);
            $due = $due === null ? (int) $wait : min($due, (int) $wait);
            if ($due === 0) {
                break;
            }
        }

        return $due;
    }

    /** Run one send. Throws on failure (the drain records it). */
    public function run(int $pushId): void
    {
        $push = DB::table('tenant_pos_pushes')->find($pushId);
        if (! $push || $push->status !== 'pending') {
            return; // done, or stopped by the shop before its turn (MARKER-POS-STOP-SENDS)
        }
        $conn = self::connectionFor($push->tenant_id);
        if (! $conn) {
            $this->mark($pushId, 'skipped', null, 'No Connected POS is connected.');
            return;
        }
        DB::table('tenant_pos_pushes')->where('id', $pushId)->update(['attempts' => DB::raw('attempts + 1'), 'updated_at' => now()]);

        $external = match ($push->kind) {
            'sale'        => $this->sendSale($conn, TenantOrder::where('tenant_id', $conn->tenant_id)->findOrFail($push->ref_id)),
            'refund'      => $this->sendRefund($conn, TenantSale::where('tenant_id', $conn->tenant_id)->findOrFail($push->ref_id)),
            'item_create' => $this->ensureItem($conn, TenantInventoryItem::where('tenant_id', $conn->tenant_id)->findOrFail($push->ref_id)),
            'item_update' => $this->sendItemEdits($conn, TenantInventoryItem::where('tenant_id', $conn->tenant_id)->findOrFail($push->ref_id)),
            default       => throw new RuntimeException('Unknown send type.'),
        };
        $this->mark($pushId, $external === false ? 'skipped' : 'done', is_string($external) ? $external : null, null);
    }

    public function mark(int $pushId, string $status, ?string $externalId, ?string $error): void
    {
        DB::table('tenant_pos_pushes')->where('id', $pushId)->update(array_filter([
            'status' => $status, 'external_id' => $externalId, 'updated_at' => now(),
        ], fn ($v) => $v !== null) + ['last_error' => $error ? Str::limit($error, 900) : null]);
    }

    // ── sales ───────────────────────────────────────────────────

    private function setup(TenantPosConnection $conn): array
    {
        $s = (array) ($conn->order_setup ?? []);
        foreach (['register_id' => 'register', 'user_id' => 'staff member', 'payment_type_id' => 'payment type'] as $k => $label) {
            if (empty($s[$k])) {
                throw new RuntimeException("Choose the {$label} online sales are recorded with, under Connected POS → Orders.");
            }
        }
        if (! empty((array) (($conn->setup_options ?? [])['taxes'] ?? [])) && empty($s['tax_id'])) {
            throw new RuntimeException('Choose the tax online sales are recorded with, under Connected POS → Orders.');
        }

        return $s;
    }

    private function outletFor(TenantPosConnection $conn, ?string $locationId): string
    {
        $outlet = array_search((string) $locationId, (array) ($conn->location_map ?? []), true);
        if ($outlet === false) {
            throw new RuntimeException('This order\'s Intake location is not mapped to a Connected POS outlet. Map it under Connected POS → Sync, then retry.');
        }

        return (string) $outlet;
    }

    /** @return string|false the POS sale id, or false when the order has nothing to record */
    private function sendSale(TenantPosConnection $conn, TenantOrder $order)
    {
        if (! $conn->orders_out) {
            return false;
        }
        $sale = TenantSale::where('tenant_id', $conn->tenant_id)->findOrFail($order->sale_id);
        $setup = $this->setup($conn);
        [$lines, $total, $left] = $this->lines($conn, $sale, 1);
        if (! $lines) {
            return false;
        }

        $note = 'Intake online order ' . $order->order_number . ($left ? ' — not recorded here: ' . implode(', ', $left) : '');

        return $this->adapterCall($conn, fn ($a, $t, $acct, $ctx) => $a->createSale($t, $acct, $ctx, [
            'reference'       => 'intake-' . $order->order_number,
            'note'            => $note,
            'outlet_id'       => $this->outletFor($conn, $sale->location_id),
            'register_id'     => $setup['register_id'],
            'user_id'         => $setup['user_id'],
            'payment_type_id' => $setup['payment_type_id'],
            'tax_id'          => $setup['tax_id'] ?? null,
            'return_for'      => null,
            'lines'           => $lines,
            'total_cents'     => $total,
        ]));
    }

    /** @return string|false */
    private function sendRefund(TenantPosConnection $conn, TenantSale $refund)
    {
        if (! $conn->orders_out) {
            return false;
        }
        $order = TenantOrder::where('tenant_id', $conn->tenant_id)->where('sale_id', $refund->refund_of_sale_id)->first();
        if (! $order) {
            return false; // not an online order's refund
        }
        $orig = DB::table('tenant_pos_pushes')->where('tenant_id', $conn->tenant_id)->where('kind', 'sale')->where('ref_id', $order->id)->first();
        if (! $orig || $orig->status !== 'done') {
            throw new RuntimeException('The original online sale is not in the Connected POS yet — send it first, then retry this refund.');
        }
        $setup = $this->setup($conn);
        [$lines, $total, $left] = $this->lines($conn, $refund, -1);
        if (! $lines) {
            return false;
        }

        return $this->adapterCall($conn, fn ($a, $t, $acct, $ctx) => $a->createSale($t, $acct, $ctx, [
            'reference'       => 'intake-' . $order->order_number . '-refund-' . substr($refund->id, 0, 8),
            'note'            => 'Refund for Intake online order ' . $order->order_number . ($left ? ' — not recorded here: ' . implode(', ', $left) : ''),
            'outlet_id'       => $this->outletFor($conn, $refund->location_id ?? $order->location_id),
            'register_id'     => $setup['register_id'],
            'user_id'         => $setup['user_id'],
            'payment_type_id' => $setup['payment_type_id'],
            'tax_id'          => $setup['tax_id'] ?? null,
            'return_for'      => $orig->external_id,
            'lines'           => $lines,
            'total_cents'     => $total,
        ]));
    }

    /**
     * Product lines in POS terms. Items missing from the POS are created first (also
     * supplier-shipped ones — they are sold, so they belong on the sale). Lines with
     * no item (shipping, custom lines) can't be sold through the POS; they are named
     * in the sale note instead.
     *
     * @return array{0: list<array>, 1: int, 2: list<string>}
     */
    private function lines(TenantPosConnection $conn, TenantSale $sale, int $sign): array
    {
        $lines = [];
        $total = 0;
        $left = [];
        foreach (TenantSaleItem::where('sale_id', $sale->id)->orderBy('position')->get() as $li) {
            if ($li->type !== 'product' || ! $li->inventory_item_id) {
                $amount = (int) $li->line_total_cents + (int) $li->tax_cents;
                if ($amount !== 0) {
                    $left[] = $li->name_snapshot . ' $' . number_format(abs($amount) / 100, 2);
                }
                continue;
            }
            $item = TenantInventoryItem::where('tenant_id', $conn->tenant_id)->find($li->inventory_item_id);
            if (! $item) {
                $left[] = $li->name_snapshot;
                continue;
            }
            $qty = (int) round(abs((float) $li->quantity));
            if ($qty === 0) {
                continue;
            }
            $lineEx = abs((int) $li->line_total_cents);
            $tax = abs((int) $li->tax_cents);
            $lines[] = [
                'item_id'    => $this->ensureItem($conn, $item),
                'qty'        => $sign * $qty,
                'unit_cents' => (int) round($lineEx / $qty),
                'tax_cents'  => $sign * $tax,
            ];
            $total += $sign * ((int) round($lineEx / $qty) * $qty + $tax);
        }

        return [$lines, $total, $left];
    }

    // ── items ───────────────────────────────────────────────────

    /**
     * The item's Connected POS id, creating it there when it isn't.
     *
     * MARKER-POS-ITEM-FIELDS: done in steps, each recorded as it lands, so a retry only
     * does what is left — never a second product:
     *   1. create (or link to the one already there with this SKU) and save the link at once
     *   2. set the price — the link's price stays empty until the POS has accepted it
     *   3. send the shop's own first photo (distributor photos are never copied)
     */
    public function ensureItem(TenantPosConnection $conn, TenantInventoryItem $item): string
    {
        $link = DB::table('tenant_pos_items')->where('tenant_id', $conn->tenant_id)->where('inventory_item_id', $item->id)->whereNull('removed_at')->first();
        $price = $item->shop_sell_price_cents ?? $item->catalog_msrp_cents;

        if (! $link) {
            $fields = [
                'name' => $item->name, 'sku' => $item->sku, 'upc' => $item->catalog_upc, 'ean' => $item->catalog_ean,
                'mpn' => $item->catalog_mpn, 'description' => $item->description,
            ];
            $id = $this->adapterCall($conn, fn ($a, $t, $acct, $ctx) => $a->createItem($t, $acct, $ctx, $fields));
            DB::table('tenant_pos_items')->updateOrInsert(
                ['tenant_id' => $conn->tenant_id, 'external_id' => $id],
                [
                    'adapter_code' => $conn->adapter_code, 'inventory_item_id' => $item->id,
                    'name' => $item->name, 'sku' => $item->sku, 'upc' => $item->catalog_upc, 'ean' => $item->catalog_ean, 'mpn' => $item->catalog_mpn,
                    'price_cents' => null, 'active' => true, 'removed_at' => null, 'synced_at' => now(),
                    'snapshot' => json_encode($this->intakeShared($item)), 'conflict' => null,
                ]
            );
            $link = DB::table('tenant_pos_items')->where('tenant_id', $conn->tenant_id)->where('external_id', $id)->first();
        }
        $id = (string) $link->external_id;

        if (! (int) $link->price_cents && $price) {
            try {
                $this->adapterCall($conn, fn ($a, $t, $acct, $ctx) => $a->setItemPrice($t, $acct, $ctx, $id, (int) $price));
            } catch (\Throwable $e) {
                throw new RuntimeException('Created in your Connected POS, but its price could not be set yet (' . $e->getMessage() . '). Retry sets just the price.');
            }
            DB::table('tenant_pos_items')->where('id', $link->id)->update(['price_cents' => (int) $price]);
        }

        if (! $link->image_sent_at) {
            $media = $item->itemImages()->with('media')->orderBy('sort_order')->get()
                ->map(fn ($j) => $j->media)->first(fn ($m) => $m && ! $m->archived_at && $m->path);
            $disk = \Illuminate\Support\Facades\Storage::disk('public');
            if ($media && $disk->exists($media->path)) {
                try {
                    $this->adapterCall($conn, fn ($a, $t, $acct, $ctx) => $a->uploadImage($t, $acct, $ctx, $id, $disk->path($media->path), $media->original_name ?: $media->filename));
                    DB::table('tenant_pos_items')->where('id', $link->id)->update(['image_sent_at' => now()]);
                } catch (\Throwable $e) {
                    // a photo is a nice-to-have: the item and its price stand; note it and carry on
                    \Illuminate\Support\Facades\Log::notice('pos.image_upload_failed', ['item' => $item->id, 'error' => $e->getMessage()]);
                }
            }
        }

        return $id;
    }

    /** @return string|false */
    private function sendItemEdits(TenantPosConnection $conn, TenantInventoryItem $item)
    {
        if (! $conn->items_edits) {
            return false;
        }
        $link = DB::table('tenant_pos_items')->where('tenant_id', $conn->tenant_id)->where('inventory_item_id', $item->id)->whereNull('removed_at')->first();
        if (! $link) {
            return false;
        }
        $snap = (array) json_decode((string) $link->snapshot, true);
        $pos = $this->adapterCall($conn, fn ($a, $t, $acct, $ctx) => $a->getItem($t, $acct, $ctx, (string) $link->external_id));
        if (! $pos) {
            throw new RuntimeException('This item no longer exists in your Connected POS.');
        }

        $send = [];
        $conflict = (array) json_decode((string) $link->conflict, true);
        foreach (self::SHARED as $col => $key) {
            if ($pos[$key] === null) {
                continue; // this POS doesn't report the field, so it can't be compared or sent
            }
            $mine = (string) ($item->{$col} ?? '');
            $theirs = (string) $pos[$key];
            $was = (string) ($snap[$key] ?? '');
            if ($mine === $was || $mine === $theirs) {
                continue; // Intake didn't change it, or both already agree
            }
            if ($theirs !== $was) {
                $conflict[$key] = ['intake' => $mine, 'pos' => $theirs, 'at' => now()->toIso8601String()];
                continue; // both changed: wait for the shop
            }
            $send[$key] = $mine;
        }

        if ($send) {
            $this->adapterCall($conn, fn ($a, $t, $acct, $ctx) => $a->updateItem($t, $acct, $ctx, (string) $link->external_id, $send));
            foreach ($send as $k => $v) {
                $snap[$k] = $v;
            }
        }
        DB::table('tenant_pos_items')->where('id', $link->id)->update(['snapshot' => json_encode($snap), 'conflict' => $conflict ? json_encode($conflict) : null]);

        return (string) $link->external_id;
    }

    /** The shop's choice on a conflicting field: 'intake' sends Intake's value, 'pos' takes the POS's. */
    public function resolve(TenantPosConnection $conn, int $posItemId, string $field, string $keep): void
    {
        $link = DB::table('tenant_pos_items')->where('tenant_id', $conn->tenant_id)->where('id', $posItemId)->first();
        $conflict = (array) json_decode((string) ($link->conflict ?? ''), true);
        if (! $link || ! isset($conflict[$field]) || ! in_array($field, self::SHARED, true)) {
            throw new RuntimeException('That choice is no longer waiting.');
        }
        $item = TenantInventoryItem::where('tenant_id', $conn->tenant_id)->findOrFail($link->inventory_item_id);
        $col = array_search($field, self::SHARED, true);
        $value = $keep === 'intake' ? (string) ($item->{$col} ?? '') : (string) $conflict[$field]['pos'];

        if ($keep === 'intake') {
            $this->adapterCall($conn, fn ($a, $t, $acct, $ctx) => $a->updateItem($t, $acct, $ctx, (string) $link->external_id, [$field => $value]));
        } else {
            if ($col === 'sku' && TenantInventoryItem::withTrashed()->where('tenant_id', $conn->tenant_id)->where('sku', $value)->where('id', '!=', $item->id)->exists()) {
                throw new RuntimeException("Another Intake item already uses SKU {$value}. Change that item first.");
            }
            PosCatalogSync::$applying = true;
            try {
                $item->forceFill([$col => $value])->save();
            } finally {
                PosCatalogSync::$applying = false;
            }
        }
        $snap = (array) json_decode((string) $link->snapshot, true);
        $snap[$field] = $value;
        unset($conflict[$field]);
        DB::table('tenant_pos_items')->where('id', $link->id)->update(['snapshot' => json_encode($snap), 'conflict' => $conflict ? json_encode($conflict) : null]);
    }

    public function intakeShared(TenantInventoryItem $item): array
    {
        $out = [];
        foreach (self::SHARED as $col => $key) {
            $out[$key] = (string) ($item->{$col} ?? '');
        }

        return $out;
    }

    private function adapterCall(TenantPosConnection $conn, callable $fn): mixed
    {
        $adapter = $this->registry->make($conn->adapter_code);

        return $fn($adapter, $this->conns->accessToken($conn), (string) $conn->external_account_id, (array) ($conn->context ?? []));
    }

    // ── model hooks (registered in AppServiceProvider) ─────────

    /**
     * MARKER-POS-CATCHUP: paid online orders from the last 7 days that were never sent,
     * for the one-time offer after "Send online orders" is turned on.
     */
    public static function catchupOrders(string $tenantId)
    {
        return TenantOrder::query()
            ->where('tenant_id', $tenantId)
            ->whereNotNull('sale_id')
            ->whereIn('status', array_merge(TenantOrder::OPEN_STATUSES, [TenantOrder::STATUS_COMPLETED]))
            ->where('created_at', '>=', now()->subDays(7))
            ->whereNotExists(fn ($q) => $q->select(DB::raw(1))->from('tenant_pos_pushes')
                ->whereColumn('tenant_pos_pushes.ref_id', 'tenant_orders.id')
                ->where('tenant_pos_pushes.tenant_id', $tenantId)
                ->where('tenant_pos_pushes.kind', 'sale'));
    }

    public static function hooks(): void
    {
        // an online order became a sale
        TenantOrder::updated(function (TenantOrder $o) {
            if ($o->wasChanged('sale_id') && $o->sale_id && ! $o->getOriginal('sale_id')) {
                $c = self::connectionFor($o->tenant_id);
                if ($c && $c->orders_out) {
                    app(self::class)->queue($o->tenant_id, 'sale', (string) $o->id, 'Online order ' . $o->order_number);
                }
            }
        });

        // a refund against an online order's sale
        TenantSale::created(function (TenantSale $s) {
            if (! $s->refund_of_sale_id) {
                return;
            }
            $c = self::connectionFor($s->tenant_id);
            if (! $c || ! $c->orders_out) {
                return;
            }
            $order = TenantOrder::where('tenant_id', $s->tenant_id)->where('sale_id', $s->refund_of_sale_id)->first();
            if ($order) {
                app(self::class)->queue($s->tenant_id, 'refund', (string) $s->id, 'Refund for online order ' . $order->order_number);
            }
        });

        // shop edits to shared fields on a linked item
        TenantInventoryItem::updated(function (TenantInventoryItem $i) {
            if (PosCatalogSync::$applying || ! $i->wasChanged(array_keys(self::SHARED))) {
                return;
            }
            $c = self::connectionFor($i->tenant_id);
            if ($c && $c->items_edits && DB::table('tenant_pos_items')->where('tenant_id', $i->tenant_id)->where('inventory_item_id', $i->id)->whereNull('removed_at')->exists()) {
                app(self::class)->queue($i->tenant_id, 'item_update', (string) $i->id, 'Changes to ' . $i->name);
            }
        });

        // new items, when the shop chose to send every new item
        TenantInventoryItem::created(function (TenantInventoryItem $i) {
            if (PosCatalogSync::$applying) {
                return;
            }
            $c = self::connectionFor($i->tenant_id);
            if ($c && $c->items_create === 'always') {
                app(self::class)->queue($i->tenant_id, 'item_create', (string) $i->id, 'New item ' . $i->name);
            }
        });
    }
}
