<?php

namespace App\Observers;

use App\Models\Tenant;
use App\Models\Tenant\TenantAppointment;
use Carbon\Carbon;

class TenantAppointmentObserver
{
    // MARKER-PATCH-311 — prefill promised_at when not explicitly provided:
    // drop-off date + N business days at 5pm tenant-local, stored UTC. Lives
    // here so every create path (admin, public booking, walk-in) gets the same
    // default. An explicit value, or a record with no date, is left untouched.
    public function creating(TenantAppointment $appointment): void
    {
        if (! empty($appointment->promised_at)) {
            return;
        }
        if (empty($appointment->appointment_date)) {
            return;
        }

        $tenant = $appointment->tenant_id
            ? Tenant::find($appointment->tenant_id)
            : (function_exists('tenant') ? tenant() : null);
        if (! $tenant) {
            return;
        }

        $tz   = method_exists($tenant, 'timezone') ? $tenant->timezone() : config('app.timezone', 'UTC');
        $lead = (int) data_get($tenant->settings, 'work_order_tag.lead_days', 3);

        $d = Carbon::parse((string) $appointment->appointment_date, $tz)->setTime(17, 0);
        for ($added = 0; $added < max(0, $lead); ) {
            $d->addDay();
            if (! $d->isWeekend()) {
                $added++;
            }
        }

        $appointment->promised_at = $d->setTimezone('UTC');
    }
}
