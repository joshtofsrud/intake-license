<?php

namespace App\Events\Pos;

use App\Models\Tenant\TenantInventoryMovement;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Fires when a decrement goes negative on an allow_oversell=true item.
 *
 * Audit trail — the sale was honored (we don't block at the register),
 * but the shop owner needs to know stock has fallen below zero so they
 * can reconcile, mark backorder, or refund.
 *
 * Future listeners:
 * - Surface in the dashboard "Needs review" zone
 * - Email tenant owner end-of-day with the day's oversold items
 * - Write to a tenant_pos_audit_log row
 */
class OversoldStock
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public readonly TenantInventoryMovement $movement,
        public readonly int $stockBeforeMovement,
        public readonly int $stockAfterMovement,
    ) {
    }
}
