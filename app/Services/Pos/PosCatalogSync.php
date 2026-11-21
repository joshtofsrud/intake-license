<?php

namespace App\Services\Pos;

use App\Models\Tenant\TenantInventoryItem;
use App\Models\Tenant\TenantInventoryItemLocation;
use App\Models\Tenant\TenantPosConnection;
use App\Services\Pos\Adapters\PosAdapterRegistry;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * MARKER-CONNECTED-POS-CATALOG: reads a shop's Connected POS catalog, and keeps the
 * price and stock of linked items in step with it.
 *
 * Runs in steps (a few pages at a time) so a big first read never outlives a
 * queue job; the job re-queues itself until caught up. Progress lives in the
 * connection's sync_state so the Connected POS page can show it live.
 *
 * Rules (decided with the shop owner in mind):
 *  - MARKER-CONNECTED-POS-SECTION: reading NEVER creates an Intake item. Every product
 *    is recorded; one that is plainly an item the shop already carries (same barcode
 *    or part number) is linked when "link matches on their own" is on. Everything
 *    else waits in Inventory › Import until someone adds it.
 *  - In 2 Way Data with sync on, the Connected POS owns price and stock on linked
 *    items: its price sets the sell price, and its count at each mapped outlet is
 *    written as a 'pos_sync' movement, so stock history shows where a change came from.
 *  - In Reading Only the catalog is still read (so Import stays current) but no
 *    Intake item is touched: Intake owns price and stock.
 *  - When a product leaves the POS, the shop's removal setting decides what happens
 *    in Intake; nothing is deleted.
 */
class PosCatalogSync
{
    private const PAGES_PER_STEP = 8;

    /** MARKER-CONNECTED-POS-PUSH: true while Intake writes what came from the POS, so those writes aren't sent back. */
    public static bool $applying = false;

    /** Built the first time a step meets a product with no link, then reused for that step. */
    private ?PosMatcher $matcher = null;

    public function __construct(private PosAdapterRegistry $registry, private PosConnectionService $conns) {}

    /** Begin a run. A full run re-reads everything from the start; otherwise it picks up from the last cursors. */
    public function start(TenantPosConnection $conn, bool $full, bool $thenSwitchOver = false): void
    {
        $st = (array) ($conn->sync_state ?? []);
        $st['switch_after'] = $thenSwitchOver; // Switch over: go to Reading Only when this read finishes
        $st = array_merge($st, [
            'running'     => true,
            'stopped'     => false,
            'full'        => $full,
            'phase'       => 'items',
            'started_at'  => now()->toIso8601String(),
            'finished_at' => null,
            'error'       => null,
            'pages'       => 0,
            'items_seen'  => 0, 'matched' => 0, 'waiting' => 0, 'created' => 0, 'price_updates' => 0, 'removed' => 0,
            'stock_rows'  => 0, 'stock_changes' => 0, 'stock_unmapped' => 0, 'conflicts' => 0,
        ]);
        if ($full) {
            $st['cursor_items'] = null;
            $st['cursor_stock'] = null;
        }
        $conn->forceFill(['sync_state' => $st])->save();
    }

    /** Do one step. Returns true when there is more to do. */
    public function step(TenantPosConnection $conn): bool
    {
        $st = (array) $conn->sync_state;
        $this->matcher = null;
        $adapter = $this->registry->make($conn->adapter_code);
        $ctx = (array) ($conn->context ?? []);
        $acct = (string) $conn->external_account_id;

        for ($i = 0; $i < self::PAGES_PER_STEP; $i++) {
            $token = $this->conns->accessToken($conn);

            if ($st['phase'] === 'items') {
                $page = $adapter->pullItems($token, $acct, $ctx, $st['cursor_items'] ?? null);
                foreach ($page['items'] as $it) {
                    $this->applyItem($conn, $it, $st);
                }
                $st['items_seen'] += count($page['items']);
                $st['cursor_items'] = $page['cursor'];
                if (! $page['more']) {
                    $st['phase'] = 'stock';
                }
            } else {
                $page = $adapter->pullStock($token, $acct, $ctx, $st['cursor_stock'] ?? null);
                foreach ($page['rows'] as $row) {
                    $this->applyStock($conn, $row, $st);
                }
                $st['stock_rows'] += count($page['rows']);
                $st['cursor_stock'] = $page['cursor'];
                if (! $page['more']) {
                    $st['phase'] = 'done';
                }
            }
            $st['pages']++;
            // MARKER-POS-STOP-RESUME: a Stop pressed while this page was being read must not be
            // written over by this page's progress. Keep the stop, and the place.
            $now = (array) json_decode((string) DB::table('tenant_pos_connections')->where('id', $conn->id)->value('sync_state'), true);
            if (! empty($now['stopped'])) {
                $st['running'] = false;
                $st['stopped'] = true;
                $st['switch_after'] = false;
                $st['finished_at'] = now()->toIso8601String();
                $conn->forceFill(['sync_state' => $st])->save();

                return false;
            }
            $conn->forceFill(['sync_state' => $st])->save();

            if ($st['phase'] === 'done') {
                $st['running'] = false;
                $st['finished_at'] = now()->toIso8601String();
                $fill = ['sync_state' => $st, 'last_synced_at' => now()];
                // the first catalog ever read is the starting point, not a list of "new" products
                if (! $conn->items_marked_at) {
                    $fill['items_marked_at'] = now();
                }
                // MARKER-CONNECTED-POS-SECTION: Switch over waits for this last read, so Intake
                // starts with the Connected POS's final prices and counts
                if (! empty($st['switch_after'])) {
                    unset($st['switch_after']);
                    $fill['sync_state'] = $st;
                    $fill['mode'] = TenantPosConnection::READ_ONLY;
                    $fill['switched_at'] = now();
                }
                $conn->forceFill($fill)->save();

                return false;
            }
        }

        return true;
    }

    public function fail(TenantPosConnection $conn, string $message): void
    {
        $st = (array) ($conn->sync_state ?? []);
        $st['running'] = false;
        $st['error'] = Str::limit($message, 400);
        $st['finished_at'] = now()->toIso8601String();
        $conn->forceFill(['sync_state' => $st])->save();
    }

    // ── items ───────────────────────────────────────────────────

    private function applyItem(TenantPosConnection $conn, array $it, array &$st): void
    {
        $tenantId = $conn->tenant_id;
        $existing = DB::table('tenant_pos_items')->where('tenant_id', $tenantId)->where('external_id', $it['id'])->first();

        $row = [
            'adapter_code'       => $conn->adapter_code,
            'parent_external_id' => $it['parent_id'],
            'name'               => $it['name'] ? Str::limit($it['name'], 250, '') : null,
            'sku'                => $it['sku'] ? Str::limit($it['sku'], 100, '') : null,
            'upc'                => $it['upc'] ? Str::limit($it['upc'], 40, '') : null,
            'ean'                => $it['ean'] ? Str::limit($it['ean'], 40, '') : null,
            'mpn'                => $it['mpn'] ? Str::limit($it['mpn'], 100, '') : null,
            'brand'              => $it['brand'] ? Str::limit($it['brand'], 150, '') : null,
            'price_cents'        => $it['price_cents'] ?: null, // 0 = no price yet
            'active'             => $it['active'],
            'removed_at'         => $it['active'] ? null : ($existing->removed_at ?? now()),
            'synced_at'          => now(),
        ];
        // MARKER-CONNECTED-POS-SECTION: what Import needs to bring an item in whole. Only
        // written when the Connected POS sent the field, so a POS that doesn't share one
        // never blanks what is already recorded.
        foreach (['category' => 190, 'supplier' => 190, 'supplier_code' => 100] as $k => $max) {
            if (array_key_exists($k, $it)) {
                $row[$k] = filled($it[$k]) ? Str::limit(trim((string) $it[$k]), $max, '') : null;
            }
        }
        if (array_key_exists('cost_cents', $it)) {
            $row['cost_cents'] = ($it['cost_cents'] !== null && (int) $it['cost_cents'] > 0) ? (int) $it['cost_cents'] : null;
        }
        if (array_key_exists('track_stock', $it) && $it['track_stock'] !== null) {
            $row['track_stock'] = (bool) $it['track_stock'];
        }
        if (array_key_exists('description', $it) && $it['description'] !== null) {
            $row['description'] = $it['description'] !== '' ? $it['description'] : null;
        }
        if (! $existing) {
            $row['first_seen_at'] = now();
        }

        DB::table('tenant_pos_items')->updateOrInsert(['tenant_id' => $tenantId, 'external_id' => $it['id']], $row);

        $itemId = $existing->inventory_item_id ?? null;
        $item = $itemId ? TenantInventoryItem::where('tenant_id', $tenantId)->find($itemId) : null;

        self::$applying = true;
        try {
            $this->applyItemInner($conn, $it, $existing, $item, $st);
        } finally {
            self::$applying = false;
        }
    }

    private function applyItemInner(TenantPosConnection $conn, array $it, ?object $existing, ?TenantInventoryItem $item, array &$st): void
    {
        $tenantId = $conn->tenant_id;
        $owns = $conn->ownsPriceAndStock();

        if (! $it['active']) {
            if ($owns && $item && ! ($existing && $existing->removed_at)) {
                $this->applyRemoval($conn, $item, $it['id']);
                $st['removed']++;
            }
            return;
        }

        // MARKER-CONNECTED-POS-SECTION: no link yet. Link it only when it is plainly an item
        // the shop already carries; otherwise it waits in Inventory › Import. Never created here.
        if (! $item) {
            $hit = $conn->auto_link ? $this->matcher($tenantId)->find($it) : null;
            if (! $hit || ! in_array($hit['how'], PosMatcher::STRONG, true)) {
                $st['waiting'] = ($st['waiting'] ?? 0) + 1;
                return;
            }
            $item = TenantInventoryItem::where('tenant_id', $tenantId)->find($hit['id']);
            if (! $item) {
                $st['waiting'] = ($st['waiting'] ?? 0) + 1;
                return;
            }
            DB::table('tenant_pos_items')->where('tenant_id', $tenantId)->where('external_id', $it['id'])->update(['inventory_item_id' => $item->id]);
            $this->matcher($tenantId)->take((string) $item->id);
            $st['matched']++;
        }

        if (! $owns) {
            return; // Reading Only, or sync off: Intake's price, stock and fields are left alone
        }

        // the Connected POS owns price
        // MARKER-POS-ITEM-FIELDS: a price of 0 means the Connected POS has none yet — never wipe Intake's
        if ($it['price_cents'] !== null && (int) $it['price_cents'] > 0 && (int) $item->shop_sell_price_cents !== (int) $it['price_cents']) {
            $item->forceFill(['shop_sell_price_cents' => $it['price_cents']])->save();
            $st['price_updates']++;
        }

        $this->reconcileShared($conn, $it, $item, $st);
    }

    private function matcher(string $tenantId): PosMatcher
    {
        return $this->matcher ??= PosMatcher::forTenant($tenantId);
    }

    /**
     * MARKER-CONNECTED-POS-SECTION: bring a just-linked item level with its Connected POS
     * product — the price, and the count at each mapped outlet. Used by Import, where the
     * shop asked for this item. $openingStock is for Reading Only, where a brand-new item
     * takes the last count read as its starting stock and Intake owns it from then on.
     */
    public function levelWith(TenantPosConnection $conn, TenantInventoryItem $item, object $pos, bool $isNew): void
    {
        $was = self::$applying;
        self::$applying = true;
        try {
            if (! $conn->ownsPriceAndStock() && ! $isNew) {
                return; // an item the shop already had keeps its own price and stock
            }
            if ($pos->price_cents && (int) $item->shop_sell_price_cents !== (int) $pos->price_cents) {
                $item->forceFill(['shop_sell_price_cents' => (int) $pos->price_cents])->save();
            }
            if (! $item->is_stock_tracked) {
                return;
            }
            $map = (array) ($conn->location_map ?? []);
            foreach (DB::table('tenant_pos_stock')->where('tenant_id', $conn->tenant_id)->where('external_item_id', $pos->external_id)->get() as $s) {
                if ($loc = $map[$s->external_location_id] ?? null) {
                    $this->setCount($conn->tenant_id, (string) $item->id, (string) $loc, max(0, (int) $s->qty), (string) $pos->external_id);
                }
            }
        } finally {
            self::$applying = $was;
        }
    }

    /**
     * MARKER-CONNECTED-POS-PUSH: shared fields (name, SKU, barcode, description).
     * A POS change lands in Intake when Intake hasn't changed that field since they
     * last agreed; when both have, it waits under Needs review. Nothing is overwritten.
     */
    private function reconcileShared(TenantPosConnection $conn, array $it, TenantInventoryItem $item, array &$st): void
    {
        $row = DB::table('tenant_pos_items')->where('tenant_id', $conn->tenant_id)->where('external_id', $it['id'])->first();
        $snap = $row->snapshot ? (array) json_decode($row->snapshot, true) : null;
        $conflict = $row->conflict ? (array) json_decode($row->conflict, true) : [];
        $pos = ['name' => $it['name'], 'sku' => $it['sku'], 'upc' => $it['upc'], 'description' => $it['description'] ?? null];

        if ($snap === null) {
            $snap = [];
            foreach (PosPushService::SHARED as $col => $key) {
                $snap[$key] = $pos[$key] !== null ? (string) $pos[$key] : (string) ($item->{$col} ?? '');
            }
        } else {
            $changes = [];
            foreach (PosPushService::SHARED as $col => $key) {
                if ($pos[$key] === null) {
                    continue;
                }
                $theirs = (string) $pos[$key];
                $was = (string) ($snap[$key] ?? '');
                $mine = (string) ($item->{$col} ?? '');
                if ($theirs === $was) {
                    continue;
                }
                if ($mine === $theirs) {
                    $snap[$key] = $theirs;
                    unset($conflict[$key]);
                } elseif ($mine === $was && ! ($col === 'sku' && $this->skuTaken($conn->tenant_id, $theirs, $item->id))) {
                    $changes[$col] = $theirs === '' ? null : $theirs;
                    $snap[$key] = $theirs;
                    unset($conflict[$key]);
                } else {
                    $conflict[$key] = ['intake' => $mine, 'pos' => $theirs, 'at' => now()->toIso8601String()];
                    $st['conflicts'] = ($st['conflicts'] ?? 0) + 1;
                }
            }
            if ($changes) {
                if (isset($changes['catalog_upc'])) {
                    $changes['catalog_upc'] = substr((string) $changes['catalog_upc'], 0, 20);
                }
                $item->forceFill($changes)->save();
            }
        }

        DB::table('tenant_pos_items')->where('id', $row->id)->update(['snapshot' => json_encode($snap), 'conflict' => $conflict ? json_encode($conflict) : null]);
    }

    private function skuTaken(string $tenantId, string $sku, string $exceptId): bool
    {
        return $sku === '' || TenantInventoryItem::withTrashed()->where('tenant_id', $tenantId)->where('sku', $sku)->where('id', '!=', $exceptId)->exists();
    }

    /** The shop's choice for a product that left the Connected POS. Its POS stock goes to zero either way. */
    private function applyRemoval(TenantPosConnection $conn, TenantInventoryItem $item, string $externalId): void
    {
        foreach (array_filter((array) ($conn->location_map ?? [])) as $locationId) {
            $this->setCount($conn->tenant_id, $item->id, (string) $locationId, 0, $externalId);
        }
        DB::table('tenant_pos_stock')->where('tenant_id', $conn->tenant_id)->where('external_item_id', $externalId)->update(['qty' => 0, 'synced_at' => now()]);

        match ($conn->removal_mode) {
            'hide'       => $item->forceFill(['show_online' => false])->save(),
            'deactivate' => $item->forceFill(['is_active' => false, 'show_online' => false])->save(),
            default      => null, // 'keep': stays for sale through any supplier that has it
        };
    }

    // ── stock ───────────────────────────────────────────────────

    private function applyStock(TenantPosConnection $conn, array $row, array &$st): void
    {
        DB::table('tenant_pos_stock')->updateOrInsert(
            ['tenant_id' => $conn->tenant_id, 'external_item_id' => $row['item_id'], 'external_location_id' => $row['location_id']],
            ['qty' => $row['qty'], 'synced_at' => now()]
        );

        if (! $conn->ownsPriceAndStock()) {
            return; // recorded above for Import; Intake's own counts are not touched
        }
        $locationId = ((array) ($conn->location_map ?? []))[$row['location_id']] ?? null;
        $itemId = DB::table('tenant_pos_items')->where('tenant_id', $conn->tenant_id)->where('external_id', $row['item_id'])
            ->whereNull('removed_at')->value('inventory_item_id');
        if (! $locationId || ! $itemId) {
            $st['stock_unmapped']++;
            return;
        }
        if ($this->setCount($conn->tenant_id, $itemId, (string) $locationId, max(0, $row['qty']), $row['item_id'])) {
            $st['stock_changes']++;
        }
    }

    /** Make Intake's count at a location match the POS, recording the difference. Returns true when it changed. */
    public function setCount(string $tenantId, string $itemId, string $locationId, int $qty, string $externalId): bool
    {
        return DB::transaction(function () use ($tenantId, $itemId, $locationId, $qty, $externalId) {
            $loc = TenantInventoryItemLocation::where('inventory_item_id', $itemId)->where('location_id', $locationId)->lockForUpdate()->first();
            if (! $loc) {
                $loc = TenantInventoryItemLocation::create([
                    'tenant_id' => $tenantId, 'inventory_item_id' => $itemId, 'location_id' => $locationId,
                    'computed_stock_count' => 0, 'is_active' => true,
                ]);
            }
            $delta = $qty - (int) $loc->computed_stock_count;
            if ($delta === 0) {
                return false;
            }
            $item = TenantInventoryItem::find($itemId);
            DB::table('tenant_inventory_movements')->insert([
                'id'                 => (string) Str::uuid(),
                'tenant_id'          => $tenantId,
                'inventory_item_id'  => $itemId,
                'quantity_delta'     => $delta,
                'movement_type'      => 'pos_sync',
                'reference_type'     => 'pos_item',
                'reference_id'       => Str::limit($externalId, 36, ''),
                'item_name_snapshot' => $item?->name,
                'item_sku_snapshot'  => $item?->sku,
                'cost_cents_at_time' => $item?->effectiveCostCents(),
                'location_id'        => $locationId,
                'reason'             => 'Connected POS count',
                'notes'              => null,
                'created_at'         => now(),
            ]);
            $loc->computed_stock_count = $qty;
            $loc->save();

            $total = (int) TenantInventoryItemLocation::where('inventory_item_id', $itemId)->sum('computed_stock_count');
            TenantInventoryItem::where('id', $itemId)->update(['computed_stock_count' => $total]);

            return true;
        });
    }
}
