<?php

namespace App\Observers\Pos;

use App\Models\Tenant\TenantInventoryReceiveShipment;
use App\Models\Tenant\TenantInventoryReceiveShipmentItem;

/**
 * Keeps the denormalized counters on TenantInventoryReceiveShipment
 * (expected_count, received_count, backorder_count, unexpected_count)
 * in sync with the actual line items.
 *
 * Cheap recompute on every save/delete. Single SUM-style query per
 * recount, scoped to one shipment. Cannot drift from the lines.
 */
class TenantInventoryReceiveShipmentItemObserver
{
    public function saved(TenantInventoryReceiveShipmentItem $item): void
    {
        $this->recount($item->shipment_id);
    }

    public function deleted(TenantInventoryReceiveShipmentItem $item): void
    {
        $this->recount($item->shipment_id);
    }

    private function recount(string $shipmentId): void
    {
        $shipment = TenantInventoryReceiveShipment::find($shipmentId);
        if (! $shipment) {
            return;
        }

        $lines = TenantInventoryReceiveShipmentItem::where('shipment_id', $shipmentId)
            ->get(['status', 'expected_quantity', 'received_quantity']);

        $expected   = 0;
        $received   = 0;
        $backorder  = 0;
        $unexpected = 0;

        foreach ($lines as $line) {
            switch ($line->status) {
                case 'expected':
                    $expected += $line->expected_quantity;
                    break;
                case 'received':
                    $received += $line->received_quantity;
                    if ($line->expected_quantity > $line->received_quantity) {
                        $backorder += ($line->expected_quantity - $line->received_quantity);
                    }
                    break;
                case 'backorder':
                    $backorder += $line->expected_quantity;
                    break;
                case 'unexpected_pending':
                case 'unexpected_added':
                case 'unexpected_hold':
                    $unexpected += $line->received_quantity;
                    break;
            }
        }

        $shipment->expected_count   = $expected;
        $shipment->received_count   = $received;
        $shipment->backorder_count  = $backorder;
        $shipment->unexpected_count = $unexpected;
        $shipment->saveQuietly();
    }
}
