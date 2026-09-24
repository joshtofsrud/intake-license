<?php
// MARKER-ATTENTION-QUEUE

namespace App\Services\Tenant;

/**
 * The Catalog attention bulk action, lifted out of the controller so a web
 * request and a queued job run exactly the same code.
 *
 * It used to run inside the request: at grndctrl's 5,663 open flags each one
 * loads its item, saves it and records an undo row, which runs for minutes —
 * nginx gave up at 60 seconds (the 504s on Sep 10 and Sep 21) while the work
 * carried on invisibly. Anything over a couple of hundred is queued now.
 */
class PricingAttentionResolver
{
    /** Flags a selection covers: the filter when "apply to all", otherwise the ticked ids. */
    public function query(string $tenantId, array $sel)
    {
        $q = \App\Models\Tenant\TenantPricingAttentionFlag::query()
            ->with('item.distributorCatalog')
            ->where('tenant_id', $tenantId)
            ->where('status', 'open');

        if (! empty($sel['select_all'])) {
            // "Apply to all matching the filter" — re-query server-side, ignore ids.
            if (filled($sel['f_brand'] ?? null)) {
                $q->whereHas('item.distributorCatalog', fn ($w) => $w->where('manufacturer', $sel['f_brand']));
            }
            if (filled($sel['f_category'] ?? null)) {
                $q->whereHas('item.distributorCatalog', fn ($w) => $w->where('category', $sel['f_category']));
            }
            if (filled($sel['f_reason'] ?? null)) {
                $q->where('reason', $sel['f_reason']);
            }
            if (($sel['f_stock'] ?? 'all') === 'in') {
                $q->whereHas('item', fn ($w) => $w->where('computed_stock_count', '>', 0));
            } elseif (($sel['f_stock'] ?? 'all') === 'out') {
                $q->whereHas('item', fn ($w) => $w->where('computed_stock_count', '<=', 0));
            }
        } else {
            $q->whereIn('id', $sel['flag_ids'] ?? []);
        }

        return $q;
    }

    /**
     * @param  array $sel  select_all, f_brand, f_category, f_reason, f_stock, flag_ids
     * @return array{applied:int, skipped:int, batch_id:?string}
     */
    public function run(string $tenantId, string $action, array $sel, ?string $userId, ?string $userEmail): array
    {
        $flags = $this->query($tenantId, $sel)->lazyById(200);

        $applied = 0;
        $skipped = 0;
        

        // MARKER-CATALOG-HISTORY — capture what these items look like before we
        // touch them, so the batch can be put back.
        $recorder = new \App\Services\Tenant\CatalogChangeRecorder(
            $tenantId,
            $action,
            [
                'select_all' => (bool) ($sel['select_all'] ?? false),
                'brand'      => $sel['f_brand'] ?? null,
                'category'   => $sel['f_category'] ?? null,
                'reason'     => $sel['f_reason'] ?? null,
            ],
            $userEmail,
        );
        $titleReason = \App\Models\Tenant\TenantPricingAttentionFlag::REASON_TITLE_CHANGED;
        $detailsReason = \App\Models\Tenant\TenantPricingAttentionFlag::REASON_DETAILS_CHANGED; // MARKER-DETAILS-WATCH

        foreach ($flags as $flag) {
            $isTitle = $flag->reason === $titleReason;
            $isDetails = $flag->reason === $detailsReason; // MARKER-DETAILS-WATCH
            $item = $flag->item;

            // Type guards — an action only applies to the matching flag kind.
            if (in_array($action, ['adopt_title', 'keep_title'], true) && ! $isTitle) {
                $skipped++;
                continue;
            }
            if (in_array($action, ['adopt_details', 'keep_details'], true) && ! $isDetails) {
                $skipped++;
                continue;
            }
            if (in_array($action, ['raise_map', 'match_msrp'], true) && ($isTitle || $isDetails)) {
                $skipped++;
                continue;
            }

            if ($action === 'raise_map' || $action === 'match_msrp') {
                $target = $action === 'raise_map'
                    ? ($item?->catalog_map_cents ?? ($flag->detail['prev_map_cents'] ?? null))
                    : ($item?->catalog_msrp_cents ?? ($flag->detail['prev_msrp_cents'] ?? null));
                if (! $item || ! $target) {
                    $skipped++;
                    continue;
                }
                $recorder->capture($item);          // MARKER-CATALOG-HISTORY
                $item->shop_sell_price_cents = (int) $target;
                $item->save();
                $recorder->captured($item);
            } elseif ($action === 'adopt_title') {
                $cat = $item?->distributorCatalog;
                if (! $item || ! $cat || blank($cat->display_name)) {
                    $skipped++;
                    continue;
                }
                $recorder->capture($item);          // MARKER-CATALOG-HISTORY
                $item->name = $cat->display_name;
                $item->catalog_title_seen = $cat->display_name; // snapshot so it won't re-flag
                $item->save();
                $recorder->captured($item);          // MARKER-CATALOG-HISTORY
            } elseif ($action === 'keep_title') {
                // Keep the tenant's name; just acknowledge the catalog's new title
                // so the watch stops flagging it.
                $cat = $item?->distributorCatalog;
                if ($item && $cat) {
                    $item->catalog_title_seen = $cat->display_name;
                    $item->save();
                }
            } elseif ($action === 'adopt_details') {
                // MARKER-DETAILS-WATCH — copy only non-blank catalog values;
                // the feed dropping a field never blanks the shop's own.
                $cat = $item?->distributorCatalog;
                if (! $item || ! $cat) {
                    $skipped++;
                    continue;
                }
                $recorder->capture($item);          // MARKER-CATALOG-HISTORY
                foreach (['color', 'size', 'description'] as $fld) {
                    if (filled($cat->{$fld})) {
                        $item->{$fld} = $cat->{$fld};
                    }
                }
                $item->catalog_details_seen = [
                    'color'       => $cat->color,
                    'size'        => $cat->size,
                    'description' => $cat->description,
                ];
                $item->save();
                $recorder->captured($item);          // MARKER-CATALOG-HISTORY
            } elseif ($action === 'keep_details') {
                // Keep the tenant's values; snapshot the catalog's so the
                // watch stops flagging this change.
                $cat = $item?->distributorCatalog;
                if ($item && $cat) {
                    $item->catalog_details_seen = [
                        'color'       => $cat->color,
                        'size'        => $cat->size,
                        'description' => $cat->description,
                    ];
                    $item->save();
                }
            }
            // 'acknowledge' falls through to resolve with no item change.

            $flag->status = 'resolved';
            $flag->resolved_at = now();
            $flag->resolved_by = $userId;
            $flag->save();
            $applied++;
        }

        $batchId = $recorder->finish(); // MARKER-CATALOG-HISTORY — one batch per bulk action

        return ['applied' => $applied, 'skipped' => $skipped, 'batch_id' => $batchId];
    }
}
