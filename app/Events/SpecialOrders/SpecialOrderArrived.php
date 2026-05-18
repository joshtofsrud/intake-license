<?php

namespace App\Events\SpecialOrders;

use App\Models\Tenant\TenantSpecialOrder;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Fires when an SO transitions from 'ordered' → 'arrived'.
 *
 * INTENDED LISTENERS (future):
 *   - SendCustomerSoArrivalNotification — Twilio SMS or email to
 *     customer (when messaging subsystem ships)
 *   - Push to staff dashboard via websockets (real-time bench updates)
 *   - Analytics: lead-time actuals, vendor performance tracking
 *
 * Dispatches AFTER the DB transaction commits, so listeners can
 * safely assume the SO state is durable.
 */
class SpecialOrderArrived
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public readonly TenantSpecialOrder $specialOrder,
    ) {
    }
}
