<?php
// MARKER-DUP-MERGE

namespace App\Services\Inventory;

use App\Models\Tenant;
use App\Models\Tenant\TenantDuplicateGroup;
use App\Models\Tenant\TenantInventoryItem;
use App\Models\Tenant\TenantLocation;
use App\Models\Tenant\TenantUser;
use App\Services\Pos\InventoryService;
use Illuminate\Support\Facades\DB;

/**
 * Merges one duplicate group into its kept item, through ItemMergeService —
 * the same code as the Merge button, so stock, sales, receiving, special
 * orders, vendors, images and flags move exactly as they always have, and
 * every merged-away SKU and barcode becomes an alias that still scans.
 *
 * On top of that, the shop's own values reach the kept (catalog) item:
 *   - price: the first copy in keep order with a price the shop set, unless
 *     a person chose one ($opts['price_from'] = an item id in the group);
 *   - cost: taken from a copy when the kept item has none;
 *   - bin location, reorder point/quantity, case quantity: fill blanks.
 * And for "stock on both copies": $opts['stock'] = 'add' (default) or a
 * number, applied at $preview.stock_location as a recorded adjustment.
 */
class DuplicateItemMerger
{
    public function __construct(
        private ItemMergeService $merge,
        private InventoryService $inventory,
    ) {}

    /** @throws \RuntimeException when the group no longer matches the items */
    public function merge(TenantDuplicateGroup $group, array $opts = [], ?TenantUser $user = null): void
    {
        $ids = (array) $group->item_ids;
        $items = TenantInventoryItem::where('tenant_id', $group->tenant_id)->whereIn('id', $ids)->get()->keyBy('id');
        if ($items->count() !== count($ids)) {
            throw new \RuntimeException('These items have changed since they were found. Refresh the list.');
        }

        $keepId = $opts['keep_id'] ?? $group->keep_item_id;
        if (! isset($items[$keepId])) {
            throw new \RuntimeException('The item to keep is not part of this group.');
        }
        $priceFrom = $opts['price_from'] ?? null;
        if ($priceFrom !== null && ! isset($items[$priceFrom])) {
            throw new \RuntimeException('That price is not from one of these items.');
        }

        DB::transaction(function () use ($group, $items, $ids, $keepId, $priceFrom, $opts, $user) {
            $survivor = $items[$keepId];
            $losers = array_values(array_filter($ids, fn ($id) => $id !== $keepId));

            // Blanks on the kept item filled from the copies, in keep order.
            $fill = [];
            foreach ($losers as $id) {
                // MARKER-DUP-PRICE-RULE — category, brand, colour and size too: a
                // catalog copy is often uncategorised while the shop's copy isn't.
                foreach (['shop_bin_location', 'shop_reorder_threshold', 'shop_reorder_quantity', 'shop_case_quantity',
                          'category_id', 'shop_brand', 'color', 'size'] as $col) {
                    if (! array_key_exists($col, $fill) && blank($survivor->$col) && filled($items[$id]->$col)) {
                        $fill[$col] = $items[$id]->$col;
                    }
                }
            }
            foreach ($losers as $id) {
                if ($items[$id]->is_stock_tracked) {
                    $fill['is_stock_tracked'] = true; // stock the shop counts stays counted
                }
            }
            if ($fill) {
                $survivor->forceFill($fill)->save();
            }

            // MARKER-DUP-PRICE-RULE — the kept (catalog) item carries the import's
            // seed price; a price the shop chose on another copy replaces it.
            $hasShopPrice = DuplicateItemFinder::isShopPrice($survivor);

            foreach ($losers as $id) {
                $loser = $items[$id];
                $survivor->refresh();

                if ($priceFrom !== null) {
                    $price = $priceFrom === $id ? 'take' : 'keep';
                } else {
                    $price = (! $hasShopPrice && DuplicateItemFinder::isShopPrice($loser)) ? 'take' : 'keep';
                    if ($price === 'take') { $hasShopPrice = true; }
                }
                $cost = ($survivor->shop_cost_cents === null && $loser->shop_cost_cents !== null) ? 'take' : 'keep';

                $this->merge->merge($loser, $survivor, ['price' => $price, 'cost' => $cost], $user?->id);
            }

            // "Keep N": the same shelf counted on two copies.
            $choice = $opts['stock'] ?? 'add';
            $loc = $group->preview['stock_location'] ?? null;
            if ($choice !== 'add' && $choice !== null && $choice !== '' && $loc) {
                $tenant   = Tenant::findOrFail($group->tenant_id);
                $location = TenantLocation::where('tenant_id', $group->tenant_id)->findOrFail($loc);
                $this->inventory->adjustStock(
                    $tenant, $survivor->refresh(), $location, max(0, (int) $choice),
                    'Duplicate merge: the same stock was counted on more than one copy', $user
                );
            }

            $group->forceFill([
                'status' => 'merged', 'keep_item_id' => $keepId, 'error' => null,
                'resolved_at' => now(), 'resolved_by' => $user?->id,
            ])->save();
        });
    }
}
