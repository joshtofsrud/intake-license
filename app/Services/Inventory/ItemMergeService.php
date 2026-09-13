<?php

namespace App\Services\Inventory;

use App\Models\Tenant\TenantInventoryItem;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * MARKER-ITEM-MERGE — fold one inventory item into another.
 *
 * Everything happens in one transaction. A half-finished merge leaves two
 * records that disagree about who owns the stock, which is worse than the
 * duplicate it was trying to fix.
 *
 * The loser is soft-deleted rather than hard-deleted: its id still appears on
 * historical rows we deliberately do not rewrite (a receipt's snapshot), and
 * a hard delete would break those lookups.
 */
class ItemMergeService
{
    /**
     * MARKER-MERGE-UI — what merge() WOULD do, touching nothing.
     *
     * Counts the same rows and runs the same per-location signed arithmetic
     * as merge(), so the confirm screen cannot promise something different
     * from what happens. Negative counts sum here exactly as they do there.
     */
    public function preview(TenantInventoryItem $loser, TenantInventoryItem $survivor): array
    {
        $locNames = DB::table('tenant_locations')
            ->where('tenant_id', $survivor->tenant_id)
            ->pluck('name', 'id')->all();

        $loserLocs = DB::table('tenant_inventory_item_locations')
            ->where('inventory_item_id', $loser->id)->get();

        $survivorLocs = DB::table('tenant_inventory_item_locations')
            ->where('inventory_item_id', $survivor->id)
            ->pluck('computed_stock_count', 'location_id')->all();

        $locations = [];
        $movedTotal = 0;

        foreach ($loserLocs as $row) {
            $qty = (int) $row->computed_stock_count;

            if ($qty === 0) {
                continue;
            }

            $before = (int) ($survivorLocs[$row->location_id] ?? 0);
            $movedTotal += $qty;

            $locations[] = [
                'name'   => $locNames[$row->location_id] ?? 'Unknown location',
                'moved'  => $qty,
                'before' => $before,
                'after'  => $before + $qty,
            ];
        }

        $survivorTotal = (int) array_sum($survivorLocs);

        // Weighted average, offered as a cost option. Negative counts are left
        // out of the weighting: stock below zero contributes no value, and
        // letting it weight the average produces a cost that never existed.
        $loserCost    = $loser->effectiveCostCents();
        $survivorCost = $survivor->effectiveCostCents();
        $weighted     = null;

        $lq = max(0, $movedTotal);
        $sq = max(0, $survivorTotal);

        if ($loserCost !== null && $survivorCost !== null && ($lq + $sq) > 0) {
            $weighted = (int) round((($loserCost * $lq) + ($survivorCost * $sq)) / ($lq + $sq));
        }

        return [
            'locations'      => $locations,
            'moved_total'    => $movedTotal,
            'survivor_total' => $survivorTotal,
            'result_total'   => $survivorTotal + $movedTotal,
            'counts'         => [
                'sales'     => DB::table('tenant_sale_items')->where('inventory_item_id', $loser->id)->count(),
                'receiving' => DB::table('tenant_inventory_receive_shipment_items')->where('inventory_item_id', $loser->id)->count(),
                'special'   => DB::table('tenant_special_orders')->where('inventory_item_id', $loser->id)->count(),
                'parts'     => DB::table('tenant_appointment_parts')->where('inventory_item_id', $loser->id)->count(),
                'movements' => DB::table('tenant_inventory_movements')->where('inventory_item_id', $loser->id)->count(),
                'vendors'   => DB::table('tenant_inventory_item_vendors')->where('inventory_item_id', $loser->id)->count(),
                'photos'    => DB::getSchemaBuilder()->hasTable('tenant_inventory_item_images')
                    ? DB::table('tenant_inventory_item_images')->where('inventory_item_id', $loser->id)->count()
                    : 0,
            ],
            'cost' => [
                'survivor' => $survivorCost,
                'loser'    => $loserCost,
                'weighted' => $weighted,
            ],
            'price' => [
                'survivor' => $survivor->effectiveSellPriceCents(),
                'loser'    => $loser->effectiveSellPriceCents(),
            ],
            // MARKER-MERGE-COMMITMENTS — what is promised to someone. These
            // all survive a merge (every row is repointed at the survivor),
            // but a person about to make an irreversible change should know
            // something is in flight before making it.
            'commitments' => [
                'open_sales' => DB::table('tenant_sale_items as si')
                    ->join('tenant_sales as s', 's.id', '=', 'si.sale_id')
                    ->where('si.inventory_item_id', $loser->id)
                    ->whereIn('s.payment_status', ['draft', 'quote', 'unpaid', 'partial'])
                    ->count(),

                'special_orders' => DB::table('tenant_special_orders')
                    ->where('inventory_item_id', $loser->id)
                    ->whereIn('status', \App\Models\Tenant\TenantSpecialOrder::STATUSES_OPEN)
                    ->count(),

                'incoming' => DB::table('tenant_inventory_receive_shipment_items as li')
                    ->join('tenant_inventory_receive_shipments as sh', 'sh.id', '=', 'li.shipment_id')
                    ->where('li.inventory_item_id', $loser->id)
                    ->where('sh.status', 'draft')
                    ->count(),
            ],

            'adopts' => array_values(array_filter([
                blank($survivor->catalog_upc) && filled($loser->catalog_upc) ? 'UPC ' . $loser->catalog_upc : null,
                blank($survivor->catalog_ean) && filled($loser->catalog_ean) ? 'EAN ' . $loser->catalog_ean : null,
                blank($survivor->catalog_mpn) && filled($loser->catalog_mpn) ? 'MPN ' . $loser->catalog_mpn : null,
                blank($survivor->distributor_catalog_id) && filled($loser->distributor_catalog_id) ? 'its catalog link' : null,
            ])),
        ];
    }

    /**
     * @param  TenantInventoryItem  $loser     merged away
     * @param  TenantInventoryItem  $survivor  kept
     * @param  array{price?:string, cost?:string}  $choices  'keep' (survivor's),
     *         'take' (loser's), or 'average' for cost — weighted by the stock
     *         each side brings, which is the only version that values the
     *         merged pile correctly.
     * @return array  a report of what moved, for display and for the log
     */
    public function merge(
        TenantInventoryItem $loser,
        TenantInventoryItem $survivor,
        array $choices = [],
        ?string $actingUserId = null,
    ): array {
        if ($loser->tenant_id !== $survivor->tenant_id) {
            throw new \InvalidArgumentException('Items belong to different tenants.');
        }

        if ($loser->id === $survivor->id) {
            throw new \InvalidArgumentException('Cannot merge an item into itself.');
        }

        $tenantId = $survivor->tenant_id;
        $mergeId  = (string) Str::uuid();

        return DB::transaction(function () use ($loser, $survivor, $choices, $tenantId, $mergeId, $actingUserId) {
            $report = ['merge_id' => $mergeId, 'locations' => [], 'moved' => []];

            // ---- stock, per location, signed ---------------------------------
            // Counts can be negative (oversold), so this is a signed sum and
            // never an absolute one. Zero-count rows are skipped: moving a 0
            // writes a movement that says nothing happened.
            $loserLocs = DB::table('tenant_inventory_item_locations')
                ->where('inventory_item_id', $loser->id)
                ->lockForUpdate()
                ->get();

            foreach ($loserLocs as $row) {
                $qty = (int) $row->computed_stock_count;

                if ($qty === 0) {
                    continue;
                }

                $target = DB::table('tenant_inventory_item_locations')
                    ->where('inventory_item_id', $survivor->id)
                    ->where('location_id', $row->location_id)
                    ->lockForUpdate()
                    ->first();

                $before = (int) ($target->computed_stock_count ?? 0);
                $after  = $before + $qty;

                if ($target) {
                    DB::table('tenant_inventory_item_locations')
                        ->where('id', $target->id)
                        ->update(['computed_stock_count' => $after]);
                } else {
                    DB::table('tenant_inventory_item_locations')->insert([
                        'id'                   => (string) Str::uuid(),
                        'tenant_id'            => $tenantId,
                        'inventory_item_id'    => $survivor->id,
                        'location_id'          => $row->location_id,
                        'computed_stock_count' => $after,
                    ]);
                }

                // Paired movements, same reference_id, so the merge reads back
                // as one event rather than two unexplained adjustments.
                foreach ([
                    ['item' => $loser->id,    'type' => 'merge_out', 'delta' => -$qty],
                    ['item' => $survivor->id, 'type' => 'merge_in',  'delta' => $qty],
                ] as $m) {
                    // Column names taken from the migrations, not from memory:
                    // the quantity column is quantity_delta, the snapshots are
                    // *_snapshot, the user column is user_id, and this table has
                    // created_at with NO updated_at.
                    DB::table('tenant_inventory_movements')->insert([
                        'id'                 => (string) Str::uuid(),
                        'tenant_id'          => $tenantId,
                        'inventory_item_id'  => $m['item'],
                        'location_id'        => $row->location_id,
                        'movement_type'      => $m['type'],
                        'reference_type'     => 'item_merge',
                        'reference_id'       => $mergeId,
                        'quantity_delta'     => $m['delta'],
                        'item_name_snapshot' => $m['item'] === $loser->id ? $loser->name : $survivor->name,
                        'item_sku_snapshot'  => $m['item'] === $loser->id ? $loser->sku : $survivor->sku,
                        'cost_cents_at_time' => $m['item'] === $loser->id
                            ? $loser->effectiveCostCents()
                            : $survivor->effectiveCostCents(),
                        // MARKER-MERGE-USERCOL — tenant_user_id, not user_id.
                        // The create migration declares user_id; a later one
                        // (fix_pos_user_fks_to_tenant_users) drops it and adds
                        // tenant_user_id. Reading only the first file gets this
                        // exactly backwards.
                        'tenant_user_id'     => $actingUserId,
                        'reason'             => 'merge',
                        'notes'              => 'Merged from ' . $loser->sku . ' into ' . $survivor->sku,
                        'created_at'         => now(),
                    ]);
                }

                // MARKER-MERGE-RESULT — the name, not just the id: the
                // result panel has to say WHERE the stock landed, and an id
                // tells a person nothing.
                $report['locations'][] = [
                    'location_id' => $row->location_id,
                    'name'        => DB::table('tenant_locations')
                        ->where('id', $row->location_id)->value('name') ?: 'Unknown location',
                    'moved'       => $qty,
                    'before'      => $before,
                    'after'       => $after,
                ];
            }

            DB::table('tenant_inventory_item_locations')
                ->where('inventory_item_id', $loser->id)
                ->update(['computed_stock_count' => 0]);

            // ---- straightforward reattachments -------------------------------
            foreach ([
                'tenant_sale_items',
                'tenant_appointment_parts',
                'tenant_inventory_receive_shipment_items',
                'tenant_special_orders',
            ] as $table) {
                $report['moved'][$table] = DB::table($table)
                    ->where('inventory_item_id', $loser->id)
                    ->update(['inventory_item_id' => $survivor->id]);
            }

            // History moves too, so the survivor's timeline is the whole story.
            // The merge_out row written above moves with it, which is correct:
            // it explains where the stock came from.
            $report['moved']['movements'] = DB::table('tenant_inventory_movements')
                ->where('inventory_item_id', $loser->id)
                ->update(['inventory_item_id' => $survivor->id]);

            // ---- vendors: merge, never duplicate ------------------------------
            $survivorVendorIds = DB::table('tenant_inventory_item_vendors')
                ->where('inventory_item_id', $survivor->id)
                ->pluck('vendor_id')->all();

            $report['moved']['vendors'] = DB::table('tenant_inventory_item_vendors')
                ->where('inventory_item_id', $loser->id)
                ->whereNotIn('vendor_id', $survivorVendorIds ?: ['__none__'])
                ->update(['inventory_item_id' => $survivor->id]);

            DB::table('tenant_inventory_item_vendors')
                ->where('inventory_item_id', $loser->id)
                ->delete();

            // ---- photos: after the survivor's own -----------------------------
            if (DB::getSchemaBuilder()->hasTable('tenant_inventory_item_images')) {
                $offset = (int) DB::table('tenant_inventory_item_images')
                    ->where('inventory_item_id', $survivor->id)
                    ->max('sort_order');

                foreach (DB::table('tenant_inventory_item_images')
                    ->where('inventory_item_id', $loser->id)
                    ->orderBy('sort_order')->get() as $i => $img) {
                    // The unique key is (item, media): if the survivor already
                    // has this exact photo, drop the duplicate rather than fail.
                    $clash = DB::table('tenant_inventory_item_images')
                        ->where('inventory_item_id', $survivor->id)
                        ->where('media_id', $img->media_id)
                        ->exists();

                    if ($clash) {
                        DB::table('tenant_inventory_item_images')->where('id', $img->id)->delete();
                        continue;
                    }

                    DB::table('tenant_inventory_item_images')
                        ->where('id', $img->id)
                        ->update([
                            'inventory_item_id' => $survivor->id,
                            'sort_order'        => $offset + $i + 1,
                            'updated_at'        => now(),
                        ]);
                }
            }

            // ---- attention flags: unique on (tenant, item, reason) -------------
            $survivorReasons = DB::table('tenant_pricing_attention_flags')
                ->where('inventory_item_id', $survivor->id)
                ->pluck('reason')->all();

            $report['moved']['flags'] = DB::table('tenant_pricing_attention_flags')
                ->where('inventory_item_id', $loser->id)
                ->whereNotIn('reason', $survivorReasons ?: ['__none__'])
                ->update(['inventory_item_id' => $survivor->id]);

            // The rest describe an item that will not exist in a moment.
            DB::table('tenant_pricing_attention_flags')
                ->where('inventory_item_id', $loser->id)
                ->delete();

            // ---- identifiers: fill blanks only ---------------------------------
            $fill = [];
            foreach (['catalog_upc', 'catalog_ean', 'catalog_mpn'] as $col) {
                if (blank($survivor->$col) && filled($loser->$col)) {
                    $fill[$col] = $loser->$col;
                }
            }

            if (blank($survivor->distributor_catalog_id) && filled($loser->distributor_catalog_id)) {
                $fill['distributor_catalog_id'] = $loser->distributor_catalog_id;
            }

            // ---- the two decisions ----------------------------------------------
            if (($choices['price'] ?? 'keep') === 'take' && $loser->shop_sell_price_cents !== null) {
                $fill['shop_sell_price_cents'] = $loser->shop_sell_price_cents;
            }

            $costChoice = $choices['cost'] ?? 'keep';

            if ($costChoice === 'take' && $loser->shop_cost_cents !== null) {
                $fill['shop_cost_cents'] = $loser->shop_cost_cents;
            } elseif ($costChoice === 'average') {
                // Weighted by what each side brings. Negative counts are excluded
                // from the weighting: a stock of -2 contributes no value to the
                // pile, and letting it weight the average would produce a cost
                // that never existed.
                $loserQty    = max(0, (int) collect($report['locations'])->sum('moved'));
                $survivorQty = max(0, (int) DB::table('tenant_inventory_item_locations')
                    ->where('inventory_item_id', $survivor->id)->sum('computed_stock_count') - $loserQty);

                $loserCost    = $loser->effectiveCostCents();
                $survivorCost = $survivor->effectiveCostCents();

                if ($loserCost !== null && $survivorCost !== null && ($loserQty + $survivorQty) > 0) {
                    $fill['shop_cost_cents'] = (int) round(
                        (($loserCost * $loserQty) + ($survivorCost * $survivorQty))
                        / ($loserQty + $survivorQty)
                    );
                }
            }

            if ($fill) {
                $survivor->forceFill($fill)->save();
            }

            $report['applied'] = $fill;

            // MARKER-ITEM-ALIASES — every identifier the loser carried keeps
            // resolving, to the survivor. A code the survivor already has as
            // its OWN identifier is skipped: it already resolves. A code the
            // survivor already holds as an alias is skipped by the unique key.
            // The loser's own aliases come across too — a chain of merges
            // must not lose the oldest label.
            $survivor->refresh();

            $own = array_filter([
                strtoupper(trim((string) $survivor->sku)),
                strtoupper(trim((string) $survivor->catalog_upc)),
                strtoupper(trim((string) $survivor->catalog_ean)),
                strtoupper(trim((string) $survivor->catalog_mpn)),
            ]);

            $candidates = [
                ['sku', $loser->sku],
                ['upc', $loser->catalog_upc],
                ['ean', $loser->catalog_ean],
                ['mpn', $loser->catalog_mpn],
            ];

            foreach (DB::table('tenant_inventory_item_aliases')
                ->where('inventory_item_id', $loser->id)->get() as $inherited) {
                $candidates[] = [$inherited->kind, $inherited->code];
            }

            $report['aliases'] = [];

            foreach ($candidates as [$kind, $code]) {
                $code = trim((string) $code);

                if ($code === '' || in_array(strtoupper($code), $own, true)) {
                    continue;
                }

                $exists = DB::table('tenant_inventory_item_aliases')
                    ->where('inventory_item_id', $survivor->id)
                    ->where('code', $code)
                    ->exists();

                if ($exists) {
                    continue;
                }

                DB::table('tenant_inventory_item_aliases')->insert([
                    'id'                => (string) Str::uuid(),
                    'tenant_id'         => $tenantId,
                    'inventory_item_id' => $survivor->id,
                    'code'              => $code,
                    'kind'              => $kind,
                    'source'            => 'merge',
                    'from_item_id'      => $loser->id,
                    'created_at'        => now(),
                ]);

                $report['aliases'][] = $code;
            }

            // The loser's alias rows are now the survivor's; nothing should
            // still point at a record that is about to be soft-deleted.
            DB::table('tenant_inventory_item_aliases')
                ->where('inventory_item_id', $loser->id)
                ->delete();

            // ---- and the loser goes -------------------------------------------
            // MARKER-MERGE-AFTER — record the destination. Without this the
            // loser is indistinguishable from an ordinary archived item, and
            // Restore would resurrect a husk whose stock and history now
            // belong to the survivor.
            $loser->forceFill([
                'is_active'      => false,
                'merged_into_id' => $survivor->id,
            ])->save();
            $loser->delete(); // soft: historical rows still resolve its id

            return $report;
        });
    }
}
