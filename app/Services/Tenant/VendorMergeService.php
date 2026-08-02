<?php

namespace App\Services\Tenant;

// MARKER-VENDOR-MERGE — absorb one vendor into another, wholesale.

use App\Models\Tenant\TenantInventoryItem;
use App\Models\Tenant\TenantInventoryItemVendor;
use App\Models\Tenant\TenantInventoryReceiveShipment;
use App\Models\Tenant\TenantSpecialOrder;
use App\Models\Tenant\TenantVendor;
use Illuminate\Support\Facades\DB;

class VendorMergeService
{
    /** Vendor columns worth inheriting when the survivor's is empty. */
    private const FILLABLE_BLANKS = [
        'contact_email', 'contact_phone', 'website', 'account_number',
        'notes', 'free_freight_cents', 'program_discount_pct',
    ];

    /** Pivot columns worth inheriting on a colliding row. */
    private const PIVOT_BLANKS = [
        'distributor_code', 'distributor_catalog_id', 'vendor_sku',
        'unit_cost_cents', 'live_cost_cents', 'live_avail',
        'live_checked_at', 'lead_time_days', 'last_ordered_at',
    ];

    /**
     * Which vendor is the importer currently using for this distributor?
     *
     * Defined by the item rows, not by the code stamp — that catches an
     * auto-created vendor whose distributor_code was cleared or never set.
     */
    public function currentSourceFor(string $tenantId, string $code, string $excludeVendorId): ?TenantVendor
    {
        $vendorIds = TenantVendor::where('tenant_id', $tenantId)
            ->where('id', '!=', $excludeVendorId)->pluck('id');

        if ($vendorIds->isEmpty()) {
            return null;
        }

        $id = TenantInventoryItemVendor::whereIn('vendor_id', $vendorIds)
            ->where('distributor_code', strtoupper($code))
            ->value('vendor_id');

        if (! $id) {
            // No items yet — fall back to the code stamp, then the old
            // naming convention the importer used.
            $id = TenantVendor::where('tenant_id', $tenantId)
                ->where('id', '!=', $excludeVendorId)
                ->where(fn ($q) => $q->where('distributor_code', strtolower($code))
                    ->orWhereRaw('LOWER(name) = ?', [strtolower($code)]))
                ->value('id');
        }

        return $id ? TenantVendor::find($id) : null;
    }

    /** Counts for the confirmation screen. Touches nothing. */
    public function preview(TenantVendor $source, TenantVendor $target): array
    {
        $sourceItemIds = TenantInventoryItemVendor::where('vendor_id', $source->id)
            ->pluck('inventory_item_id');

        $collides = TenantInventoryItemVendor::where('vendor_id', $target->id)
            ->whereIn('inventory_item_id', $sourceItemIds)->count();

        return [
            'source_name'    => $source->name,
            'target_name'    => $target->name,
            'items'          => $sourceItemIds->count(),
            'items_collide'  => $collides,
            'special_orders' => TenantSpecialOrder::where('vendor_id', $source->id)->count(),
            'shipments'      => TenantInventoryReceiveShipment::where('vendor_id', $source->id)->count(),
            'default_for'    => TenantInventoryItem::where('default_vendor_id', $source->id)->count(),
            'inherits'       => collect(self::FILLABLE_BLANKS)
                ->filter(fn ($f) => blank($target->{$f}) && filled($source->{$f}))
                ->values()->all(),
        ];
    }

    /**
     * Absorb $source into $target. One transaction: any failure and both
     * vendors are left exactly as they were.
     */
    public function merge(TenantVendor $source, TenantVendor $target, string $code): array
    {
        $result = $this->preview($source, $target);

        DB::transaction(function () use ($source, $target, $code) {
            // 1. Item rows. The unique index on (inventory_item_id,
            //    vendor_id) blocks a blind move, so a colliding row donates
            //    its blanks to the survivor and is dropped.
            $targetByItem = TenantInventoryItemVendor::where('vendor_id', $target->id)
                ->get()->keyBy('inventory_item_id');

            TenantInventoryItemVendor::where('vendor_id', $source->id)
                ->get()
                ->each(function (TenantInventoryItemVendor $row) use ($target, $targetByItem) {
                    $existing = $targetByItem->get($row->inventory_item_id);

                    if (! $existing) {
                        $row->update(['vendor_id' => $target->id]);
                        return;
                    }

                    $fill = [];
                    foreach (self::PIVOT_BLANKS as $f) {
                        if (blank($existing->{$f}) && filled($row->{$f})) {
                            $fill[$f] = $row->{$f};
                        }
                    }
                    // Preferred is sticky: if either row was preferred, keep it.
                    if ($row->is_preferred && ! $existing->is_preferred) {
                        $fill['is_preferred'] = true;
                    }
                    if ($fill) {
                        $existing->update($fill);
                    }

                    $row->delete();
                });

            // 2. History and pointers. No unique constraints, plain repoint.
            TenantSpecialOrder::where('vendor_id', $source->id)
                ->update(['vendor_id' => $target->id]);

            TenantInventoryReceiveShipment::where('vendor_id', $source->id)
                ->update(['vendor_id' => $target->id]);

            // Fill a BLANK distributor_code only — the snapshot on a receipt
            // is what the receiving screens show, and it is not ours to rewrite.
            TenantInventoryReceiveShipment::where('vendor_id', $target->id)
                ->whereNull('distributor_code')
                ->update(['distributor_code' => strtoupper($code)]);

            TenantInventoryItem::where('default_vendor_id', $source->id)
                ->update(['default_vendor_id' => $target->id]);

            // 3. MARKER-MERGE-CODE-RELEASE — hand the code over before the
            //    target claims it. (tenant_id, distributor_code) is unique, so
            //    two rows holding 'bti' for even one statement is rejected.
            //    The source cannot be deleted first to free it:
            //    tenant_inventory_item_vendors cascades on delete and the rows
            //    have only just been moved off it.
            $source->update(['distributor_code' => null]);

            // 4. Inherit blanks. Never overwrite something the shop typed.
            $fill = [];
            foreach (self::FILLABLE_BLANKS as $f) {
                if (blank($target->{$f}) && filled($source->{$f})) {
                    $fill[$f] = $source->{$f};
                }
            }
            $fill['distributor_code'] = strtolower($code);
            $target->update($fill);

            // 5. Nothing references the source now, so the cascade on
            //    tenant_inventory_item_vendors has nothing left to take.
            $source->delete();
        });

        return $result;
    }
}
