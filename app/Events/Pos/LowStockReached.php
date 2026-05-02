<?php

namespace App\Events\Pos;

use App\Models\Tenant\TenantInventoryItemLocation;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Fires when a decrement crosses an item-location below its reorder threshold.
 *
 * Only fires on the threshold-crossing decrement, not on every subsequent
 * decrement while still below threshold. Re-fires only after stock is
 * replenished above threshold and crosses below again.
 *
 * Future listeners:
 * - Send SMS / email to tenant owner
 * - Update dashboard low-stock tile
 * - Pre-fill a draft purchase order with the reorder_quantity
 */
class LowStockReached
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public readonly TenantInventoryItemLocation $itemLocation,
    ) {
    }
}
