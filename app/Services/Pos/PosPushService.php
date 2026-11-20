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

    /** Queue a send once; returns the push row (existing one when already queued or done). */
    public function queue(string $tenantId, string $kind, string $refId, string $label): object
    {
        $row = DB::table('tenant_pos_pushes')->where('tenant_id', $tenantId)->where('kind', $kind)->where('ref_id', $refId)->first();
        if (! $row) {
            $id = DB::table('tenant_pos_pushes')->insertGetId([
                'tenant_id' => $tenantId, 'kind' => $kind, 'ref_id' => $refId, 'label' => Str::limit($label, 195),
                'status' => 'pending', 'created_at' => now(), 'updated_at' => now(),
            ]);
            $row = DB::table('tenant_pos_pushes')->find($id);
        } elseif (in_array($kind, ['item_update'], true) && $row->status !== 'pending') {
            // an item can change many times; each change re-arms its one row
            DB::table('tenant_pos_pushes')->where('id', $row->id)->update(['status' => 'pending', 'last_error' => null, 'updated_at' => now()]);
        }
        if ($row->status === 'pending' || in_array($kind, ['item_update'], true)) {
            // a refund's lines are written just after the refund itself; give them a moment
            \App\Jobs\PosPushJob::dispatch((int) $row->id)->afterCommit()->delay(now()->addSeconds($kind === 'refund' ? 15 : 3));
        }

        return $row;
    }

    /** Run one send. Throws on failure (the job records it). */
    public function run(int $pushId): void
    {
        $push = DB::table('tenant_pos_pushes')->find($pushId);
        if (! $push || $push->status === 'done') {
            return;
        }
        $conn = self::connectionFor($push->tenant_id);
        if (! $conn) {
            $this->mark($pushId, 'skipped', null, 'No Connected POS is connected.');
            return;
        }
        DB::table('tenant_pos_pushes')->where('id', $pushId)->increment('attempts');

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

    /** The item's POS id, creating it in the POS when it isn't there. */
    public function ensureItem(TenantPosConnection $conn, TenantInventoryItem $item): string
    {
        $link = DB::table('tenant_pos_items')->where('tenant_id', $conn->tenant_id)->where('inventory_item_id', $item->id)->whereNull('removed_at')->first();
        if ($link) {
            return (string) $link->external_id;
        }
        $fields = [
            'name' => $item->name, 'sku' => $item->sku, 'upc' => $item->catalog_upc, 'ean' => $item->catalog_ean,
            'mpn' => $item->catalog_mpn, 'description' => $item->description,
            'price_cents' => $item->shop_sell_price_cents ?? $item->catalog_msrp_cents,
        ];
        $id = $this->adapterCall($conn, fn ($a, $t, $acct, $ctx) => $a->createItem($t, $acct, $ctx, $fields));

        DB::table('tenant_pos_items')->updateOrInsert(
            ['tenant_id' => $conn->tenant_id, 'external_id' => $id],
            [
                'adapter_code' => $conn->adapter_code, 'inventory_item_id' => $item->id,
                'name' => $item->name, 'sku' => $item->sku, 'upc' => $item->catalog_upc, 'ean' => $item->catalog_ean, 'mpn' => $item->catalog_mpn,
                'price_cents' => $fields['price_cents'], 'active' => true, 'removed_at' => null, 'synced_at' => now(),
                'snapshot' => json_encode($this->intakeShared($item)), 'conflict' => null,
            ]
        );

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
            throw new RuntimeException('This item no longer exists in the Connected POS.');
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
