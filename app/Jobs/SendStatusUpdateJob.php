<?php

namespace App\Jobs;

use App\Models\Tenant\TenantAppointment;
use App\Models\Tenant\TenantEmailSuppression;
use App\Models\Tenant\TenantNotificationLog;
use App\Services\EmailService;
use App\Support\AppointmentStatus;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * "Your work order moved" email.
 *
 * Only dispatched for the statuses the shop ticked on the Communication page
 * (settings.status_update_states, empty by default), so nothing goes out until
 * a shop chooses it. Completed is never offered: the Complete modal and the
 * work-order receipt own that moment.
 */
class SendStatusUpdateJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries   = 3;
    public int $backoff = 60;

    public function __construct(
        public readonly string $appointmentId,
        public readonly string $status
    ) {}

    public function handle(): void
    {
        $appt = TenantAppointment::with(['tenant', 'customer'])->find($this->appointmentId);
        if (! $appt || ! $appt->tenant) return;
        $tenant = $appt->tenant;

        if (! $tenant->notificationEnabled('status_update_email')) {
            $this->log($appt, '(disabled)', 'skipped', 'disabled by shop');
            return;
        }
        if (! in_array($this->status, (array) (($tenant->settings ?? [])['status_update_states'] ?? []), true)) {
            $this->log($appt, '(none)', 'skipped', 'status no longer picked');
            return;
        }
        // moved again before the job ran: the customer gets the newer one, not this.
        if ($appt->status !== $this->status) {
            $this->log($appt, '(none)', 'skipped', 'status changed again before sending');
            return;
        }

        $to = $appt->customer?->email ?: $appt->customer_email;
        if (! $to) {
            $this->log($appt, '(none)', 'skipped', 'no email available');
            return;
        }
        if (TenantEmailSuppression::isSuppressed($tenant->id, $to)) {
            $this->log($appt, $to, 'skipped', 'suppressed');
            return;
        }

        try {
            EmailService::forTenant($tenant)->send('status_update', $to, [
                'first_name'  => $appt->customer_first_name ?? '',
                'ra_number'   => $appt->ra_number ?? '',
                'status'      => AppointmentStatus::LABELS[$this->status] ?? ucfirst(str_replace('_', ' ', $this->status)),
                'status_note' => '',
                'shop_name'   => $tenant->name,
            ]);
            $this->log($appt, $to, 'sent', null);
        } catch (\Throwable $e) {
            \App\Support\JobFailureReporter::report(self::class, 'Status update did not send', $e,
                ['appointment_id' => $appt->id], $appt->tenant_id);
            $this->log($appt, $to, 'failed', $e->getMessage());
        }
    }

    private function log(TenantAppointment $appt, string $recipient, string $status, ?string $error): void
    {
        TenantNotificationLog::record([
            'tenant_id'     => $appt->tenant_id,
            'event_type'    => 'status_update',
            'channel'       => 'email',
            'recipient'     => $recipient,
            'related_type'  => 'appointment',
            'related_id'    => $appt->id,
            'status'        => $status,
            'error_message' => $error,
            'template_key'  => 'status_update',
        ]);
    }
}
