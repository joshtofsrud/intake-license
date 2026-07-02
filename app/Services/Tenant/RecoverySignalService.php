<?php

namespace App\Services\Tenant;

use App\Models\Tenant;
use App\Models\Tenant\TenantAppointment;
use App\Models\Tenant\TenantCustomerSignal;

/**
 * Evaluates quality signals for the recovery engine. Called after an appointment's
 * completion is stamped. Each detector is idempotent (one signal per appointment
 * per type), so re-saves don't duplicate.
 */
class RecoverySignalService
{
    public function evaluate(TenantAppointment $appt): void
    {
        $this->lateCompletion($appt);
        // Future: lateDelivery(), reschedule(), specialOrderDelay().
    }

    protected function lateCompletion(TenantAppointment $appt): void
    {
        if (! $appt->promised_at || ! $appt->completed_at || ! $appt->customer_id) {
            return;
        }

        $graceDays = $this->graceDays($appt->tenant_id);
        $threshold = $appt->promised_at->copy()->addDays($graceDays);

        // On time (within the shop's grace) — no signal.
        if ($appt->completed_at->lessThanOrEqualTo($threshold)) {
            return;
        }

        TenantCustomerSignal::updateOrCreate(
            [
                'tenant_id'      => $appt->tenant_id,
                'appointment_id' => $appt->id,
                'type'           => 'late_completion',
            ],
            [
                'customer_id' => $appt->customer_id,
                'occurred_at' => $appt->completed_at,
                'meta'        => [
                    'promised_at'  => $appt->promised_at->toIso8601String(),
                    'completed_at' => $appt->completed_at->toIso8601String(),
                    'days_late'    => $appt->promised_at->diffInDays($appt->completed_at),
                    'grace_days'   => $graceDays,
                    'ra_number'    => $appt->ra_number,
                ],
            ]
        );
    }

    protected function graceDays(string $tenantId): int
    {
        $settings = Tenant::find($tenantId)?->settings ?? [];

        return max(0, (int) ($settings['recovery_late_completion_grace_days'] ?? 1));
    }
}
