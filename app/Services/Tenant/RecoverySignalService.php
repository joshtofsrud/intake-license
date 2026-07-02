<?php

namespace App\Services\Tenant;

use App\Models\Tenant;
use App\Models\Tenant\TenantAppointment;
use App\Models\Tenant\TenantCustomerSignal;

/**
 * Evaluates quality signals for the recovery engine. Called from the appointment
 * model after relevant changes. Each detector is idempotent (one signal per
 * appointment per type) and honors the shop's per-signal on/off toggle.
 */
class RecoverySignalService
{
    /** Completion-driven signals (called after completed_at is stamped). */
    public function evaluate(TenantAppointment $appt): void
    {
        $this->lateCompletion($appt);
        // Future: lateDelivery() with pickup/delivery v2.
    }

    protected function lateCompletion(TenantAppointment $appt): void
    {
        if (! $appt->promised_at || ! $appt->completed_at || ! $appt->customer_id) {
            return;
        }
        if (! $this->enabled($appt->tenant_id, 'late_completion')) {
            return;
        }

        $graceDays = $this->graceDays($appt->tenant_id);
        $threshold = $appt->promised_at->copy()->addDays($graceDays);

        if ($appt->completed_at->lessThanOrEqualTo($threshold)) {
            return; // on time (within grace)
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

    /** A shop-side date move on a live appointment. Called from the model hook. */
    public function reschedule(TenantAppointment $appt, $oldDate = null): void
    {
        if (! $appt->customer_id || ! $this->enabled($appt->tenant_id, 'reschedule')) {
            return;
        }

        TenantCustomerSignal::updateOrCreate(
            [
                'tenant_id'      => $appt->tenant_id,
                'appointment_id' => $appt->id,
                'type'           => 'reschedule',
            ],
            [
                'customer_id' => $appt->customer_id,
                'occurred_at' => tnow()->utc(),
                'meta'        => [
                    'old_date'  => $oldDate ? (string) $oldDate : null,
                    'new_date'  => $appt->appointment_date?->toDateString(),
                    'ra_number' => $appt->ra_number,
                ],
            ]
        );
    }

    protected function graceDays(string $tenantId): int
    {
        $settings = Tenant::find($tenantId)?->settings ?? [];

        return max(0, (int) ($settings['recovery_late_completion_grace_days'] ?? 1));
    }

    protected function enabled(string $tenantId, string $key): bool
    {
        $settings = Tenant::find($tenantId)?->settings ?? [];

        return (bool) ($settings["recovery_signal_{$key}"] ?? true);
    }
}
