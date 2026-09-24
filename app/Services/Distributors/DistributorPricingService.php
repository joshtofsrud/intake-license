<?php
// MARKER-PRICE-SEED

namespace App\Services\Distributors;

use App\Services\Tenant\CatalogChangeRecorder;
use Illuminate\Support\Facades\DB;

/**
 * Where a distributor's items sit against its two list prices, and moving the
 * untouched ones from one to the other.
 *
 * A "seed" price is the one the import gave an item; a shop's own price is
 * anything else. Only seeded items are ever swept, so a price someone set by
 * hand is never overwritten. Zero counts as no price: some feeds send MSRP
 * 0.00 for products they hold no retail price for.
 */
class DistributorPricingService
{
    /** @return array{at_msrp:int, at_map:int, shop_priced:int, no_price:int, change:int, delta_cents:int, avg_pct:float, target:string} */
    public function stats(string $tenantId, string $code, string $target): array
    {
        $rows = $this->base($tenantId, $code)
            ->selectRaw("
                SUM(CASE WHEN i.shop_sell_price_cents > 0 AND i.shop_sell_price_cents = i.catalog_msrp_cents THEN 1 ELSE 0 END) at_msrp,
                SUM(CASE WHEN i.shop_sell_price_cents > 0 AND i.shop_sell_price_cents = i.catalog_map_cents
                          AND (i.catalog_msrp_cents IS NULL OR i.catalog_map_cents <> i.catalog_msrp_cents) THEN 1 ELSE 0 END) at_map,
                SUM(CASE WHEN i.shop_sell_price_cents > 0
                          AND (i.catalog_msrp_cents IS NULL OR i.shop_sell_price_cents <> i.catalog_msrp_cents)
                          AND (i.catalog_map_cents IS NULL OR i.shop_sell_price_cents <> i.catalog_map_cents) THEN 1 ELSE 0 END) shop_priced,
                SUM(CASE WHEN i.shop_sell_price_cents IS NULL OR i.shop_sell_price_cents = 0 THEN 1 ELSE 0 END) no_price
            ")->first();

        $move = $this->movable($tenantId, $code, $target)
            ->selectRaw('COUNT(*) n, COALESCE(SUM(' . $this->targetCol($target) . ' - i.shop_sell_price_cents), 0) delta,
                         COALESCE(AVG((' . $this->targetCol($target) . ' - i.shop_sell_price_cents) / i.shop_sell_price_cents), 0) pct')
            ->first();

        return [
            'at_msrp'     => (int) ($rows->at_msrp ?? 0),
            'at_map'      => (int) ($rows->at_map ?? 0),
            'shop_priced' => (int) ($rows->shop_priced ?? 0),
            'no_price'    => (int) ($rows->no_price ?? 0),
            'change'      => (int) ($move->n ?? 0),
            'delta_cents' => (int) ($move->delta ?? 0),
            'avg_pct'     => round(((float) ($move->pct ?? 0)) * 100, 1),
            'target'      => $target,
        ];
    }

    /** Move every seeded item to the target price. Recorded as one undoable batch. */
    public function sweep(string $tenantId, string $code, string $target, ?string $userId, ?string $userEmail): int
    {
        $recorder = new CatalogChangeRecorder(
            $tenantId,
            'price_seed_sweep',
            ['distributor' => strtoupper($code), 'target' => $target],
            $userEmail,
        );

        $applied = 0;
        $this->movable($tenantId, $code, $target)
            ->select('i.id')
            ->orderBy('i.id')
            ->chunk(500, function ($ids) use (&$applied, $recorder, $target) {
                $items = \App\Models\Tenant\TenantInventoryItem::whereIn('id', $ids->pluck('id'))->get();
                foreach ($items as $item) {
                    $to = $target === 'msrp' ? $item->catalog_msrp_cents : $item->catalog_map_cents;
                    if (! $to || (int) $to <= 0) {
                        continue;
                    }
                    $recorder->capture($item);
                    $item->shop_sell_price_cents = (int) $to;
                    $item->save();
                    $recorder->captured($item);
                    $applied++;
                }
            });

        $recorder->finish();

        return $applied;
    }

    private function targetCol(string $target): string
    {
        return $target === 'msrp' ? 'i.catalog_msrp_cents' : 'i.catalog_map_cents';
    }

    /** This distributor's items in this shop. */
    private function base(string $tenantId, string $code)
    {
        return DB::table('tenant_inventory_items as i')
            ->join('platform_distributor_catalogs as c', 'c.id', '=', 'i.distributor_catalog_id')
            ->where('i.tenant_id', $tenantId)
            ->whereNull('i.deleted_at')
            ->where('c.distributor_code', strtoupper($code));
    }

    /**
     * Items still at the price the import gave them, which have the target
     * price and aren't already on it.
     */
    private function movable(string $tenantId, string $code, string $target)
    {
        $col = $this->targetCol($target);

        return $this->base($tenantId, $code)
            ->where('i.shop_sell_price_cents', '>', 0)
            ->whereRaw("{$col} > 0")
            ->whereRaw("i.shop_sell_price_cents <> {$col}")
            ->where(fn ($q) => $q->whereRaw('i.shop_sell_price_cents = i.catalog_map_cents')
                ->orWhereRaw('i.shop_sell_price_cents = i.catalog_msrp_cents'));
    }
}
