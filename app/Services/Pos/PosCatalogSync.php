<?php

namespace App\Services\Pos;

use App\Models\Tenant\TenantInventoryItem;
use App\Models\Tenant\TenantInventoryItemLocation;
use App\Models\Tenant\TenantPosConnection;
use App\Services\Pos\Adapters\PosAdapterRegistry;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * MARKER-CONNECTED-POS-CATALOG: brings a shop's Connected POS products, prices and
 * stock into Intake.
 *
 * Runs in steps (a few pages at a time) so a big first import never outlives a
 * queue job; the job re-queues itself until caught up. Progress lives in the
 * connection's sync_state so the Connected POS tab can show it live.
 *
 * Rules (decided with the shop owner in mind):
 *  - Each POS product links to one Intake item: an existing link, else a match by
 *    UPC/EAN, then MPN, then SKU, else a new item. New items start OFF the online
 *    store and can't be oversold; the shop chooses what goes online.
 *  - The Connected POS owns price: its price sets the item's sell price.
 *  - The Connected POS owns stock at each mapped outlet: its count is written as a
 *    'pos_sync' movement, so stock history shows where the change came from.
 *  - When a product leaves the POS, the shop's removal setting decides what happens
 *    in Intake; nothing is deleted.
 */
class PosCatalogSync
{
    private const PAGES_PER_STEP = 8;

    /** MARKER-CONNECTED-POS-PUSH: true while Intake writes what came from the POS, so those writes aren't sent back. */
    public static bool $applying = false;

    public function __construct(private PosAdapterRegistry $registry, private PosConnectionService $conns) {}

    /** Begin a run. A full run re-reads everything from the start; otherwise it picks up from the last cursors. */
    public function start(TenantPosConnection $conn, bool $full): void
    {
        $st = (array) ($conn->sync_state ?? []);
        $st = array_merge($st, [
            'running'     => true,
            'stopped'     => false,
            'full'        => $full,
            'phase'       => 'items',
            'started_at'  => now()->toIso8601String(),
            'finished_at' => null,
            'error'       => null,
            'pages'       => 0,
            'items_seen'  => 0, 'matched' => 0, 'created' => 0, 'price_updates' => 0, 'removed' => 0,
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
            $conn->forceFill(['sync_state' => $st])->save();

            if ($st['phase'] === 'done') {
                $st['running'] = false;
                $st['finished_at'] = now()->toIso8601String();
                $conn->forceFill(['sync_state' => $st, 'last_synced_at' => now()])->save();

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

        DB::table('tenant_pos_items')->updateOrInsert(
            ['tenant_id' => $tenantId, 'external_id' => $it['id']],
            [
                'adapter_code'       => $conn->adapter_code,
                'parent_external_id' => $it['parent_id'],
                'name'               => $it['name'] ? Str::limit($it['name'], 250, '') : null,
                'sku'                => $it['sku'] ? Str::limit($it['sku'], 100, '') : null,
                'upc'                => $it['upc'] ? Str::limit($it['upc'], 40, '') : null,
                'ean'                => $it['ean'] ? Str::limit($it['ean'], 40, '') : null,
                'mpn'                => $it['mpn'] ? Str::limit($it['mpn'], 100, '') : null,
                'brand'              => $it['brand'] ? Str::limit($it['brand'], 150, '') : null,
                'price_cents'        => $it['price_cents'],
                'active'             => $it['active'],
                'removed_at'         => $it['active'] ? null : ($existing->removed_at ?? now()),
                'synced_at'          => now(),
            ]
        );

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

        if (! $it['active']) {
            if ($item && ! ($existing && $existing->removed_at)) {
                $this->applyRemoval($conn, $item, $it['id']);
                $st['removed']++;
            }
            return;
        }

        if (! $item) {
            $item = $this->match($tenantId, $it);
            if ($item) {
                $st['matched']++;
            } else {
                $item = $this->create($tenantId, $it);
                $st['created']++;
            }
            DB::table('tenant_pos_items')->where('tenant_id', $tenantId)->where('external_id', $it['id'])->update(['inventory_item_id' => $item->id]);
        }

        // the Connected POS owns price
        if ($it['price_cents'] !== null && (int) $item->shop_sell_price_cents !== (int) $it['price_cents']) {
            $item->forceFill(['shop_sell_price_cents' => $it['price_cents']])->save();
            $st['price_updates']++;
        }

        $this->reconcileShared($conn, $it, $item, $st);
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

    /** An Intake item not already linked to another POS product: barcode, then MPN, then SKU. */
    private function match(string $tenantId, array $it): ?TenantInventoryItem
    {
        $linked = DB::table('tenant_pos_items')->where('tenant_id', $tenantId)->whereNotNull('inventory_item_id')
            ->where('external_id', '!=', $it['id'])->select('inventory_item_id');
        $base = fn () => TenantInventoryItem::where('tenant_id', $tenantId)->whereNotIn('id', $linked);

        $codes = array_values(array_filter([$it['upc'], $it['ean']]));
        if ($codes) {
            $hit = $base()->where(fn ($q) => $q->whereIn('catalog_upc', $codes)->orWhereIn('catalog_ean', $codes))->orderByDesc('is_active')->first();
            if ($hit) {
                return $hit;
            }
        }
        if ($it['mpn']) {
            $hit = $base()->where('catalog_mpn', $it['mpn'])->orderByDesc('is_active')->first();
            if ($hit) {
                return $hit;
            }
        }
        if ($it['sku']) {
            return $base()->where('sku', $it['sku'])->first();
        }

        return null;
    }

    private function create(string $tenantId, array $it): TenantInventoryItem
    {
        $sku = $this->freeSku($tenantId, $it['sku'] ?: 'POS-' . strtoupper(substr(preg_replace('/[^A-Za-z0-9]/', '', $it['id']), 0, 12)));
        $code = $it['upc'] ?: $it['ean'];
        $catalogId = $code
            ? DB::table('platform_distributor_catalogs')->where('upc', $code)->where('is_active', true)->value('id')
            : null;

        return TenantInventoryItem::create([
            'tenant_id'              => $tenantId,
            'sku'                    => $sku,
            'name'                   => $it['name'] ?: $sku,
            'catalog_upc'            => $it['upc'] ? substr($it['upc'], 0, 20) : null,
            'catalog_ean'            => $it['ean'] ?: null,
            'catalog_mpn'            => $it['mpn'] ?: null,
            'shop_brand'             => $it['brand'] ?: null,
            'description'            => ($it['description'] ?? null) ?: null,
            'shop_sell_price_cents'  => $it['price_cents'],
            'distributor_catalog_id' => $catalogId,
            'is_active'              => true,
            'show_online'            => false,
            'allow_oversell'         => false,
            'is_stock_tracked'       => true,
            'computed_stock_count'   => 0,
        ]);
    }

    /** SKUs are unique per shop, archived items included. */
    private function freeSku(string $tenantId, string $want): string
    {
        $want = Str::limit($want, 60, '');
        $sku = $want;
        for ($n = 2; TenantInventoryItem::withTrashed()->where('tenant_id', $tenantId)->where('sku', $sku)->exists(); $n++) {
            $sku = Str::limit($want, 56, '') . '-' . $n;
        }

        return $sku;
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
