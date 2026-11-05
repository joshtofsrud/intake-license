<?php

namespace App\Services\Tenant;

use App\Models\Tenant;
use App\Models\Tenant\TenantInventoryReceiveShipment as Shipment;
use App\Models\Tenant\TenantInventoryReceiveShipmentItem as ShipLine;
use App\Models\Tenant\TenantPurchaseOrder as PO;
use App\Models\Tenant\TenantPurchaseOrderLine as POLine;
use App\Models\Tenant\TenantVendor;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * MARKER-PO: a receiving shipment that starts from purchase orders. Every
 * line still owed is filled in (expected = received = what's owed), so the
 * person only changes what's different in the box.
 */
class ReceivingFromPo
{
    public static function number(Tenant $tenant): string
    {
        $today = now($tenant->timezone ?? 'UTC')->format('Ymd');
        $prefix = "RCV-{$today}-";
        $n = Shipment::withTrashed()->where('tenant_id', $tenant->id)->where('shipment_number', 'like', "{$prefix}%")->count() + 1;
        do {
            $number = $prefix . str_pad((string) $n, 3, '0', STR_PAD_LEFT);
            $n++;
        } while (Shipment::withTrashed()->where('tenant_id', $tenant->id)->where('shipment_number', $number)->exists());

        return $number;
    }

    /** @param Collection<int, PO> $pos */
    public static function start(Tenant $tenant, string $locationId, ?string $vendorId, Collection $pos, ?string $number = null, ?string $date = null): Shipment
    {
        return DB::transaction(function () use ($tenant, $locationId, $vendorId, $pos, $number, $date) {
            $vendor = $vendorId ? TenantVendor::where('tenant_id', $tenant->id)->find($vendorId) : null;
            $s = new Shipment();
            $s->tenant_id = $tenant->id;
            $s->location_id = $locationId;
            $s->shipment_number = $number ?: self::number($tenant);
            $s->received_date = $date ?: now($tenant->timezone ?? 'UTC')->toDateString();
            $s->status = 'draft';
            $s->shipping_cost_cents = 0;
            $s->vendor_id = $vendor?->id;
            $s->distributor_name = $vendor?->name;
            $s->distributor_code = $vendor?->distributor_code ? strtoupper($vendor->distributor_code) : null;
            $s->purchase_order_id = $pos->first()?->id;
            $s->freight_method = PurchaseOrderService::settings($tenant)['freight_method'];
            $s->created_by_tenant_user_id = auth('tenant')->id();
            $s->save();
            foreach ($pos as $po) {
                self::addPo($s, $po);
            }

            return $s;
        });
    }

    /** Add every line still owed on a PO that isn't already on the shipment. */
    public static function addPo(Shipment $s, PO $po): int
    {
        if ($po->location_id !== $s->location_id) {
            throw new PurchaseOrderException("{$po->po_number} ships to another location, so it's received there.");
        }
        $already = ShipLine::where('shipment_id', $s->id)->whereNotNull('purchase_order_line_id')->pluck('purchase_order_line_id')->flip();
        $n = 0;
        foreach ($po->lines()->with('item')->get() as $line) {
            $owed = $line->owed();
            if ($owed < 1 || isset($already[$line->id])) {
                continue;
            }
            self::lineFromPo($s, $line, $owed, $owed);
            $n++;
        }
        if (! $s->purchase_order_id) {
            $s->forceFill(['purchase_order_id' => $po->id])->save();
        }

        return $n;
    }

    public static function lineFromPo(Shipment $s, POLine $line, int $expected, int $received): ShipLine
    {
        $item = $line->inventory_item_id ? $line->loadMissing('item')->item : null;
        $sl = new ShipLine();
        $sl->tenant_id = $s->tenant_id;
        $sl->shipment_id = $s->id;
        $sl->inventory_item_id = $line->inventory_item_id;
        $sl->purchase_order_line_id = $line->id;
        $sl->name = $item?->name ?? $line->name_snapshot;
        $sl->sku = $item?->sku ?? $line->vendor_sku;
        $sl->expected_quantity = $expected;
        $sl->received_quantity = $received;
        $sl->status = $received > 0 ? 'received' : 'backorder';
        $sl->unit_cost_cents = $line->unit_cost_cents ?? $item?->effectiveCostCents();
        $sl->total_cost_cents = $sl->unit_cost_cents !== null ? $sl->unit_cost_cents * $received : null;
        $sl->rest_action = 'owed';
        $sl->save();

        return $sl;
    }

    /** An open PO line for this item, from this vendor, to this location, not on the shipment yet. */
    public static function openLineFor(Shipment $s, string $itemId): ?POLine
    {
        if (! $s->vendor_id) {
            return null;
        }
        $already = ShipLine::where('shipment_id', $s->id)->whereNotNull('purchase_order_line_id')->pluck('purchase_order_line_id');

        return POLine::where('tenant_id', $s->tenant_id)->where('inventory_item_id', $itemId)
            ->whereNotIn('id', $already)
            ->whereHas('purchaseOrder', fn ($q) => $q->where('vendor_id', $s->vendor_id)->where('location_id', $s->location_id)->whereIn('status', PO::OPEN))
            ->with('purchaseOrder')->get()->sortBy(fn ($l) => $l->purchaseOrder->placed_at)->first(fn ($l) => $l->owed() > 0);
    }

    /**
     * Landed unit cost per line: unit cost plus its share of freight, spread
     * by line value, by units, or not at all. Returns [line_id => [share_cents, landed_unit_cents]].
     */
    public static function freightShares(Shipment $s, Collection $lines): array
    {
        $method = $s->freight_method ?: 'value';
        $fr = (int) $s->shipping_cost_cents;
        $acc = $lines->filter(fn ($l) => self::committable($l))->values();
        $val = $acc->sum(fn ($l) => self::accepted($l) * (int) $l->unit_cost_cents);
        $units = $acc->sum(fn ($l) => self::accepted($l));
        $out = [];
        $given = 0;
        foreach ($acc as $i => $l) {
            $q = self::accepted($l);
            if ($method === 'off' || $fr <= 0 || $q < 1) {
                $share = 0;
            } elseif ($i === $acc->count() - 1) {
                $share = $fr - $given; // the last line takes the rounding
            } elseif ($method === 'units' || $val <= 0) {
                $share = (int) round($fr * $q / max(1, $units));
            } else {
                $share = (int) round($fr * ($q * (int) $l->unit_cost_cents) / $val);
            }
            $given += $share;
            $out[$l->id] = [$share, (int) $l->unit_cost_cents + ($q ? (int) round($share / $q) : 0)];
        }

        return $out;
    }

    /** Units that go into stock on this line. */
    public static function accepted(ShipLine $l): int
    {
        return max(0, (int) $l->received_quantity - (int) $l->rejected_quantity);
    }

    public static function committable(ShipLine $l): bool
    {
        return $l->status === 'received' && $l->inventory_item_id !== null && self::accepted($l) > 0;
    }

    /** Invoice total vs received value + freight, in cents (null when no invoice total). */
    public static function invoiceGap(Shipment $s, Collection $lines): ?int
    {
        if ($s->invoice_total_cents === null) {
            return null;
        }
        $val = $lines->filter(fn ($l) => $l->status === 'received')->sum(fn ($l) => self::accepted($l) * (int) $l->unit_cost_cents);

        return (int) $s->invoice_total_cents - ($val + (int) $s->shipping_cost_cents);
    }
}
