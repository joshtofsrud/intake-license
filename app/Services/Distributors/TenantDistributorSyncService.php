<?php
// MARKER-PATCH-HLC3B

namespace App\Services\Distributors;

use App\Models\PlatformDistributorCatalog;
use App\Models\Tenant\TenantDistributorCatalogSubscription;
use App\Models\Tenant\TenantInventoryItem;
use App\Models\Tenant\TenantInventoryItemVendor;
use App\Models\Tenant\TenantPricingAttentionFlag;

/**
 * Tier-2: per-tenant cost + availability for LINKED items, using the tenant's
 * own distributor key. Seeds sell price on first link (MAP -> MSRP) but never
 * overwrites a set price. Reconciles vanish flags for in-stock items.
 */
class TenantDistributorSyncService
{
    public function __construct(
        private readonly DistributorRegistry $registry,
        private readonly DistributorMapResolver $resolver,
    ) {}

    public function sync(TenantDistributorCatalogSubscription $sub, bool $dryRun = false): array
    {
        $code = strtoupper((string) $sub->distributor_code);

        // Empty-map guard: refuse rather than silently write broken rows.
        if (empty($this->resolver->mapsFor($code))) {
            throw new \RuntimeException("No field map for {$code}. Seed DistributorFieldMapSeeder before syncing.");
        }

        $adapter = $this->registry->forSubscription($sub);
        if ($adapter === null) {
            throw new \RuntimeException("Subscription {$sub->id} has no usable {$code} credentials.");
        }

        $tenantId = (string) $sub->tenant_id;
        $res = [
            'tenant_id' => $tenantId, 'code' => $code, 'linked' => 0,
            'cost_updated' => 0, 'avail_updated' => 0, 'seeded_price' => 0,
            'flags_opened' => 0, 'flags_resolved' => 0, 'dry_run' => $dryRun, 'errors' => [],
        ];

        /** @var \Illuminate\Support\Collection<int,TenantInventoryItemVendor> $pivots */
        $pivots = TenantInventoryItemVendor::query()
            ->where('distributor_code', $code)
            ->whereNotNull('distributor_catalog_id')
            ->whereHas('item', fn ($q) => $q->where('tenant_id', $tenantId))
            ->with(['item', 'distributorCatalog'])
            ->get();

        $res['linked'] = $pivots->count();
        if ($pivots->isEmpty()) {
            return $res;
        }

        $catalogRows = $pivots->pluck('distributorCatalog')->filter()->unique('id');
        $variantNos = $catalogRows->pluck('distributor_variant_no')->filter()->unique()->values()->all();
        $upcs = $catalogRows->pluck('upc')->filter()->unique()->values()->all();
        $mpns = $catalogRows->pluck('manufacturer_sku')->filter()->unique()->values()->all();

        [$costByVariant, $costErrors] = $this->fetchCosts($adapter, $code, $upcs, $mpns, $variantNos);
        $res['errors'] = array_merge($res['errors'], $costErrors);
        $availByVariant = $this->fetchAvailability($adapter, $variantNos, $res);

        foreach ($pivots as $pivot) {
            $cat = $pivot->distributorCatalog;
            $item = $pivot->item;
            if (! $cat || ! $item) {
                continue;
            }
            $vno = (string) $cat->distributor_variant_no;
            $newCost = $costByVariant[$vno] ?? null;
            $newAvail = $availByVariant[$vno] ?? null;
            $prevCost = $pivot->live_cost_cents;

            if (! $dryRun) {
                $pivot->live_cost_cents = $newCost;
                if ($newAvail !== null) {
                    $pivot->live_avail = $newAvail;
                }
                $pivot->live_checked_at = now();
                $pivot->save();

                // MARKER-PATCH-556 — stamp the item so "Last synced" is honest
                // and cost/margin surfaces (item page, register modal, reports
                // via effectiveCostCents) see the synced cost. Never clobber a
                // known cost with null when HLC omits one.
                $item->catalog_synced_at = now();
                if ($newCost !== null) {
                    $item->catalog_cost_cents = $newCost;
                }
                $item->saveQuietly();
            }
            if ($newCost !== null) {
                $res['cost_updated']++;
            }
            if ($newAvail !== null) {
                $res['avail_updated']++;
                if (! $dryRun) {
                    \Illuminate\Support\Facades\DB::table('distributor_availability_snapshots')->insert([
                        'tenant_id' => $tenantId,
                        'distributor_code' => $code,
                        'distributor_variant_no' => $vno,
                        'distributor_catalog_id' => $cat->id,
                        'avail' => $newAvail,
                        'checked_at' => now(),
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                }
            }

            // First-link seed only: MAP -> MSRP. Never overwrite a set price.
            if ($item->shop_sell_price_cents === null) {
                $seed = $cat->map_cents ?? $cat->msrp_cents;
                if ($seed !== null) {
                    if (! $dryRun) {
                        $item->shop_sell_price_cents = $seed;
                        $item->save();
                    }
                    $res['seeded_price']++;
                }
            }

            $inStock = (int) ($item->computed_stock_count ?? 0) > 0;
            $this->reconcile($tenantId, $item, $cat, $prevCost, $newCost, $inStock, $dryRun, $res);
        }

        return $res;
    }

    /**
     * Pull tenant-keyed cost (Base, via the resolver) for the linked variants,
     * targeted by their UPCs/MPNs so we don't drag the whole catalog per tenant.
     *
     * @return array{0: array<string,int|null>, 1: array<string>}
     */
    private function fetchCosts(DistributorAdapter $adapter, string $code, array $upcs, array $mpns, array $variantNos): array
    {
        // HLC's prices() endpoint (Catalog/Products/Prices) honours the SKU
        // filter — unlike products() — and returns per-variant Prices[]. Run each
        // row through the same field-map resolver tier-1 uses, so cost (Base /
        // TypeId 0) is computed identically everywhere. ($upcs/$mpns unused now.)
        $cost = [];
        $errors = [];

        foreach (array_chunk($variantNos, 50) as $chunk) {
            try {
                foreach ($this->productsList($adapter->prices($chunk)) as $row) {
                    $vno = $row['VariantNo'] ?? null;
                    if ($vno === null) {
                        continue;
                    }
                    // The prices row carries Prices[] directly; pass it as both
                    // variant and product so the cost map row can read it.
                    $resolved = $this->resolver->resolve($code, $row, $row);
                    $cost[$vno] = $resolved['cost_cents'] ?? null;
                }
            } catch (\Throwable $e) {
                $errors[] = 'cost fetch: ' . $e->getMessage();
            }
        }

        return [$cost, $errors];
    }

    /** @return array<string,int> variant_no => available qty (best-effort parse) */
    private function fetchAvailability(DistributorAdapter $adapter, array $variantNos, array &$res): array
    {
        $avail = [];
        foreach (array_chunk($variantNos, 50) as $chunk) {
            try {
                foreach ($this->normalizeInventory($adapter->inventory($chunk)) as $vno => $qty) {
                    $avail[$vno] = $qty;
                }
            } catch (\Throwable $e) {
                $res['errors'][] = 'avail fetch: ' . $e->getMessage();
            }
        }
        return $avail;
    }

    private function productsList(mixed $batch): array
    {
        if (! is_array($batch)) {
            return [];
        }
        if (isset($batch['Products']) && is_array($batch['Products'])) {
            return $batch['Products'];
        }
        if (isset($batch['Items']) && is_array($batch['Items'])) {
            return $batch['Items'];
        }
        return array_is_list($batch) ? $batch : [];
    }

    /**
     * Tolerant inventory parser — HLC's exact Inventory shape is confirmed
     * against live data later; until then we recognise the common forms and
     * leave availability null when we can't read it (never guess a number).
     *
     * @return array<string,int>
     */
    private function normalizeInventory(mixed $resp): array
    {
        $out = [];
        $rows = $this->productsList($resp);
        foreach ($rows as $row) {
            if (! is_array($row)) {
                continue;
            }
            $vno = $row['VariantNo'] ?? $row['Sku'] ?? $row['SKU'] ?? null;
            if ($vno === null) {
                continue;
            }
            $qty = null;
            foreach (['TotalQtyAvailable', 'Available', 'TotalAvailable', 'Quantity', 'QtyAvailable'] as $k) {
                if (isset($row[$k]) && is_numeric($row[$k])) {
                    $qty = (int) $row[$k];
                    break;
                }
            }
            if ($qty === null) {
                foreach (['WarehousesQuantities', 'Warehouses', 'Inventory'] as $k) {
                    if (! empty($row[$k]) && is_array($row[$k])) {
                        $sum = 0;
                        $found = false;
                        foreach ($row[$k] as $w) {
                            foreach (['QtyAvailable', 'Quantity', 'Available', 'Qty'] as $wk) {
                                if (isset($w[$wk]) && is_numeric($w[$wk])) {
                                    $sum += (int) $w[$wk];
                                    $found = true;
                                    break;
                                }
                            }
                        }
                        if ($found) {
                            $qty = $sum;
                            break;
                        }
                    }
                }
            }
            if ($qty !== null) {
                $out[(string) $vno] = $qty;
            }
        }
        return $out;
    }

    private function reconcile(
        string $tenantId,
        TenantInventoryItem $item,
        PlatformDistributorCatalog $cat,
        ?int $prevCost,
        ?int $newCost,
        bool $inStock,
        bool $dryRun,
        array &$res
    ): void {
        $checks = [
            TenantPricingAttentionFlag::REASON_COST_VANISHED =>
                $inStock && $prevCost !== null && $newCost === null,
            TenantPricingAttentionFlag::REASON_MAP_VANISHED =>
                $inStock && $cat->map_cents === null && $cat->prev_map_cents !== null,
            TenantPricingAttentionFlag::REASON_MSRP_VANISHED =>
                $inStock && $cat->msrp_cents === null && $cat->prev_msrp_cents !== null,
        ];

        foreach ($checks as $reason => $active) {
            if ($active) {
                $this->openFlag($tenantId, $item, $cat, $reason, $prevCost, $dryRun, $res);
            } else {
                $this->resolveFlag($tenantId, $item, $reason, $dryRun, $res);
            }
        }

        // MARKER-PATCH-557 — price-position detectors.
        $sell = $item->effectiveSellPriceCents();
        if ($sell !== null && $sell > 0) {
            if ($cat->map_cents !== null && $sell < $cat->map_cents) {
                $this->openFlag($tenantId, $item, $cat,
                    TenantPricingAttentionFlag::REASON_BELOW_MAP, $prevCost, $dryRun, $res, [
                        'sell_cents'     => $sell,
                        'map_cents'      => $cat->map_cents,
                        'prev_map_cents' => $cat->prev_map_cents,
                        'delta_cents'    => $cat->map_cents - $sell,
                        'at'             => now()->toIso8601String(),
                    ]);
            } else {
                $this->resolveFlag($tenantId, $item, TenantPricingAttentionFlag::REASON_BELOW_MAP, $dryRun, $res);
            }

            // Off-MSRP only matters when it's not already a MAP problem and
            // the gap is real (>= 5% under MSRP), so the queue stays signal.
            $msrp = $cat->msrp_cents;
            $isMapProblem = $cat->map_cents !== null && $sell < $cat->map_cents;
            if (! $isMapProblem && $msrp !== null && $msrp > 0 && $sell < (int) round($msrp * 0.95)) {
                $this->openFlag($tenantId, $item, $cat,
                    TenantPricingAttentionFlag::REASON_OFF_MSRP, $prevCost, $dryRun, $res, [
                        'sell_cents'      => $sell,
                        'msrp_cents'      => $msrp,
                        'prev_msrp_cents' => $cat->prev_msrp_cents,
                        'pct_under'       => round((($msrp - $sell) / $msrp) * 100, 1),
                        'at'              => now()->toIso8601String(),
                    ]);
            } else {
                $this->resolveFlag($tenantId, $item, TenantPricingAttentionFlag::REASON_OFF_MSRP, $dryRun, $res);
            }
        }

        // Title / identity drift — NOT stock-gated; a renamed catalog item
        // matters regardless of stock. Never auto-applied: the tenant adopts or
        // keeps their own name from the attention surface.
        $titleNow = $cat->display_name;
        $titleDiffers = $titleNow !== null && $titleNow !== '' && $titleNow !== $item->catalog_title_seen;
        // MARKER-TITLE-RATIO -- only a meaningful rename flags. Feeds tweak
        // casing/spacing/punctuation constantly; below the configured ratio
        // the seen baseline advances silently instead of flooding attention.
        $titleRatio = $titleDiffers ? self::titleChangeRatio($item->catalog_title_seen, $titleNow) : 0.0;
        $titleThreshold = (float) config('distributors.title_change_min_ratio', 0.15);
        if ($titleDiffers && $titleRatio >= $titleThreshold) {
            $this->openFlag(
                $tenantId, $item, $cat,
                TenantPricingAttentionFlag::REASON_TITLE_CHANGED, null, $dryRun, $res,
                [
                    'old'               => $item->catalog_title_seen,
                    'new'               => $titleNow,
                    'current_item_name' => $item->name,
                    'change_ratio'      => round($titleRatio * 100),
                    'at'                => now()->toIso8601String(),
                ]
            );
        } else {
            if ($titleDiffers && ! $dryRun) {
                // Trivial drift: adopt the baseline so the same cosmetic diff
                // is not re-evaluated on every sync. The item's own name is
                // never touched here.
                $item->catalog_title_seen = $titleNow;
                $item->save();
            }
            $this->resolveFlag($tenantId, $item, TenantPricingAttentionFlag::REASON_TITLE_CHANGED, $dryRun, $res);
        }

        // MARKER-DETAILS-WATCH — descriptive drift (color / size / description).
        // Same contract as the title watch: never auto-applied; the tenant
        // adopts or keeps from the attention surface. A null baseline is
        // seeded silently so an existing library never floods attention.
        $detailsNow = [
            'color'       => $cat->color,
            'size'        => $cat->size,
            'description' => $cat->description,
        ];
        $seenDetails = $item->catalog_details_seen;
        if ($seenDetails === null) {
            if (! $dryRun) {
                $item->catalog_details_seen = $detailsNow;
                $item->save();
            }
        } else {
            $changed = [];
            foreach ($detailsNow as $k => $v) {
                if (($v ?? '') !== ($seenDetails[$k] ?? '')) {
                    $changed[$k] = ['old' => $seenDetails[$k] ?? null, 'new' => $v];
                }
            }
            if ($changed !== []) {
                $this->openFlag($tenantId, $item, $cat,
                    TenantPricingAttentionFlag::REASON_DETAILS_CHANGED, null, $dryRun, $res, [
                        'changed' => $changed,
                        'at'      => now()->toIso8601String(),
                    ]);
            } else {
                $this->resolveFlag($tenantId, $item, TenantPricingAttentionFlag::REASON_DETAILS_CHANGED, $dryRun, $res);
            }
        }
    }

    private function openFlag(string $tenantId, TenantInventoryItem $item, PlatformDistributorCatalog $cat, string $reason, ?int $prevCost, bool $dryRun, array &$res, ?array $detailOverride = null): void
    {
        $existing = TenantPricingAttentionFlag::query()
            ->where('tenant_id', $tenantId)
            ->where('inventory_item_id', $item->id)
            ->where('reason', $reason)
            ->first();

        if ($existing && $existing->status === 'open') {
            return; // already open
        }

        $res['flags_opened']++;
        if ($dryRun) {
            return;
        }

        $detail = $detailOverride ?? [
            'prev_cost_cents' => $prevCost,
            'prev_map_cents'  => $cat->prev_map_cents,
            'prev_msrp_cents' => $cat->prev_msrp_cents,
            'at'              => now()->toIso8601String(),
        ];

        TenantPricingAttentionFlag::query()->updateOrCreate(
            ['tenant_id' => $tenantId, 'inventory_item_id' => $item->id, 'reason' => $reason],
            [
                'distributor_catalog_id' => $cat->id,
                'detail'      => $detail,
                'status'      => 'open',
                'resolved_at' => null,
                'resolved_by' => null,
            ]
        );
    }

    // MARKER-TITLE-RATIO -- 0.0 (identical) .. 1.0 (entirely different),
    // measured on case/whitespace-normalized strings so cosmetic feed edits
    // score near zero. A null or blank baseline counts as a full change,
    // preserving the pre-ratio behavior for never-seen titles. Public static
    // so the cleanup command applies the identical measure.
    public static function titleChangeRatio(?string $old, ?string $new): float
    {
        $norm = fn (?string $s) => preg_replace('/\s+/', ' ', trim(mb_strtolower((string) $s)));
        $a = $norm($old);
        $b = $norm($new);
        if ($a === '' || $b === '') {
            return 1.0;
        }
        if ($a === $b) {
            return 0.0;
        }
        similar_text($a, $b, $pct);
        return max(0.0, min(1.0, 1 - $pct / 100));
    }

    private function resolveFlag(string $tenantId, TenantInventoryItem $item, string $reason, bool $dryRun, array &$res): void
    {
        $existing = TenantPricingAttentionFlag::query()
            ->where('tenant_id', $tenantId)
            ->where('inventory_item_id', $item->id)
            ->where('reason', $reason)
            ->where('status', 'open')
            ->first();

        if (! $existing) {
            return;
        }

        $res['flags_resolved']++;
        if ($dryRun) {
            return;
        }

        $existing->status = 'resolved';
        $existing->resolved_at = now();
        $existing->save();
    }
}
