<?php

namespace App\Events\Pos;

use App\Models\Tenant\TenantInventoryMovement;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Fires after every successful stock change (sale, refund, receive,
 * adjustment, transfer, initial).
 *
 * Listeners can:
 * - Write analytics rows
 * - Trigger downstream cache invalidation
 * - Push real-time updates to the register UI via websockets (future)
 *
 * The movement is the full record of what happened — listeners can read
 * type, delta, item, location, cost, snapshot fields, etc. from it.
 */
class InventoryStockChanged
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public readonly TenantInventoryMovement $movement,
    ) {
    }
}
