<?php

// MARKER-CATALOG-LOOKUP

namespace App\Filament\Pages;

use App\Models\PlatformDistributorCatalog;
use Filament\Pages\Page;
use Illuminate\Support\Facades\DB;

/**
 * Look up one distributor item and see everything recorded about it.
 *
 * Deliberately searches several identifier columns at once rather than
 * making you pick which kind of number you are holding — in practice you
 * have "a number off a box" and do not know whether it is a UPC, an EAN or
 * the distributor's own part number.
 */
class CatalogItemLookup extends Page
{
    use \App\Support\UsesAdminNav; // MARKER-NAV-ORDER
    use \App\Support\GatedByAdminArea; // MARKER-ADMIN-NAV-GATE
    protected static string $adminArea = 'catalog';

    protected static ?string $navigationIcon  = 'heroicon-o-magnifying-glass';
    protected static ?string $navigationLabel = 'Item lookup';
    protected static ?string $navigationGroup = 'Distribution';
    protected static ?int    $navigationSort  = 40;
    protected static ?string $title = 'Catalog item lookup';

    protected static string $view = 'filament.pages.catalog-item-lookup';

    public string $q        = '';
    public string $code     = '';
    public ?string $selected = null;

    public function updatedQ(): void    { $this->selected = null; }
    public function updatedCode(): void { $this->selected = null; }

    public function select(string $id): void
    {
        $this->selected = $id;
    }

    /** @return \Illuminate\Support\Collection */
    public function getResultsProperty()
    {
        $q = trim($this->q);
        if (mb_strlen($q) < 3) {
            return collect();
        }

        $like = '%' . str_replace(['%', '_'], ['\\%', '\\_'], $q) . '%';

        return PlatformDistributorCatalog::query()
            ->when($this->code !== '', fn ($b) => $b->where('distributor_code', $this->code))
            ->where(function ($b) use ($q, $like) {
                // Exact on the identifier columns first — a scanned number is
                // exact, and a LIKE on it would drag in every longer barcode
                // that happens to contain it.
                $b->where('upc', $q)
                  ->orWhere('ean', $q)
                  ->orWhere('manufacturer_sku', $q)
                  ->orWhere('distributor_product_no', $q)
                  ->orWhere('distributor_variant_no', $q)
                  ->orWhere('product_key', $q)
                  ->orWhere('name', 'like', $like)
                  ->orWhere('manufacturer_sku', 'like', $like);
            })
            ->orderBy('distributor_code')
            ->orderBy('name')
            ->limit(50)
            ->get();
    }

    public function getRowProperty(): ?PlatformDistributorCatalog
    {
        return $this->selected
            ? PlatformDistributorCatalog::find($this->selected)
            : null;
    }

    /** Which shops carry this exact catalog row, and at what cost. */
    public function getCarriersProperty(): array
    {
        if (! $this->selected) {
            return [];
        }

        return DB::table('tenant_inventory_item_vendors as iv')
            ->join('tenant_inventory_items as i', 'i.id', '=', 'iv.inventory_item_id')
            ->join('tenants as t', 't.id', '=', 'i.tenant_id')
            ->leftJoin('tenant_vendors as v', 'v.id', '=', 'iv.vendor_id')
            ->where('iv.distributor_catalog_id', $this->selected)
            ->select(
                't.subdomain', 't.name as tenant_name', 'i.name as item_name',
                'i.sku', 'v.name as vendor_name', 'iv.live_cost_cents',
                'iv.unit_cost_cents', 'iv.live_avail', 'iv.vendor_sku'
            )
            ->orderBy('t.subdomain')
            ->limit(100)
            ->get()
            ->all();
    }

    /** Distributor codes present, for the filter. */
    public function getCodesProperty(): array
    {
        return PlatformDistributorCatalog::query()
            ->select('distributor_code')->distinct()
            ->orderBy('distributor_code')->pluck('distributor_code')->all();
    }

    /**
     * Canonical fields, grouped. Blanks are rendered, not hidden — a null
     * cost or a missing UPC is usually the thing being investigated.
     */
    public function fieldGroups(PlatformDistributorCatalog $r): array
    {
        return [
            'Identity' => [
                'distributor_code' => $r->distributor_code,
                'distributor_name' => $r->distributor_name,
                'product_key'      => $r->product_key,
                'distributor_product_no' => $r->distributor_product_no,
                'distributor_variant_no' => $r->distributor_variant_no,
                'manufacturer_sku' => $r->manufacturer_sku,
                'upc'              => $r->upc,
                'ean'              => $r->ean,
            ],
            'Naming' => [
                'name'             => $r->name,
                'display_name'     => $r->display_name,
                'display_subtitle' => $r->display_subtitle,
                'manufacturer'     => $r->manufacturer,
                'brand_id'         => $r->brand_id,
                'description'      => $r->description,
            ],
            'Pricing' => [
                'cost_cents'      => $r->cost_cents,
                'msrp_cents'      => $r->msrp_cents,
                'map_cents'       => $r->map_cents,
                'prev_cost_cents' => $r->prev_cost_cents,
                'alt_prices'      => $r->alt_prices,
                'taxable'         => $r->taxable,
            ],
            'Classification' => [
                'category'      => $r->category,
                'category_id'   => $r->category_id,
                'category_path' => $r->category_path,
                'item_group'    => $r->item_group,
                // MARKER-LOOKUP-COLORSIZE — resolved names, not distributor codes
                'color'         => $r->color,
                'size'          => $r->size,
                'size_id'       => $r->size_id,
                'color_id'      => $r->color_id,
                'config'        => $r->config,
            ],
            'Physical & shipping' => [
                'uom'            => $r->uom,
                'case_quantity'  => $r->case_quantity,
                'weight'         => $r->weight,
                'dimensions'     => $r->dimensions,
                'ground_only'    => $r->ground_only,
                'hazmat_type'    => $r->hazmat_type,
                'freight_class'  => $r->freight_class,
                'dropship_fulfillable' => $r->dropship_fulfillable,
            ],
            'Status' => [
                'canonical_status'   => $r->canonical_status,
                'source_status_id'   => $r->source_status_id,
                'source_status_label'=> $r->source_status_label,
                'is_sellable'        => $r->is_sellable,
                'is_active'          => $r->is_active,
                'last_synced_at'     => $r->last_synced_at,
                'source_modified_at' => $r->source_modified_at,
            ],
        ];
    }
}
