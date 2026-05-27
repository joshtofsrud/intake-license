<?php

namespace App\Jobs;

use App\Models\Tenant\TenantAppointment;
use App\Models\Tenant\TenantNotificationLog;
use App\Services\EmailService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\View;

/**
 * MARKER-PATCH-160 — Sends an appointment work-order receipt.
 *
 * Dispatched from:
 *  - AppointmentController::handleUpdate (when status enters a configured
 *    terminal state — default ['completed'], optionally 'shipped' / 'closed').
 *  - Manual re-send (future endpoint).
 *
 * Same fail-open + log pattern as SendSaleReceiptJob.
 */
class SendAppointmentReceiptJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries   = 3;
    public int $backoff = 60;

    public function __construct(
        public readonly string $appointmentId,
        public readonly ?string $overrideEmail = null,
        public readonly string $reason = 'auto_send_on_status'
    ) {}

    public function handle(): void
    {
        $appt = TenantAppointment::with([
            'tenant', 'customer', 'items', 'addons', 'charges'
        ])->find($this->appointmentId);
        if (!$appt) return;

        $tenant = $appt->tenant;
        if (!$tenant) return;

        $isManual = $this->reason === 'manual_resend';
        if (!$isManual && !$tenant->notificationEnabled('appointment_receipt_email')) {
            $this->log($appt, '(disabled)', 'skipped', 'disabled by tenant');
            return;
        }

        $to = $this->overrideEmail
            ?: $appt->customer?->email
            ?: $appt->customer_email;
        if (!$to) {
            $this->log($appt, '(none)', 'skipped', 'no email available');
            return;
        }

        $vars = [
            'first_name'  => $appt->customer_first_name ?? '',
            'ra_number'   => $appt->ra_number ?? '',
            'shop_name'   => $tenant->name,
            'date'        => $appt->appointment_date
                ? \Carbon\Carbon::parse($appt->appointment_date)->format('M j, Y')
                : '',
            'total'       => format_money($appt->total_cents ?? 0),
        ];

        $svc = EmailService::forTenant($tenant);
        $template = \App\Models\Tenant\TenantEmailTemplate::where('tenant_id', $tenant->id)
            ->where('template_type', 'appointment_receipt')
            ->first();

        if ($template && $template->is_enabled) {
            $subject  = $svc->interpolate($template->subject ?: 'Your {{shop_name}} work is complete — #{{ra_number}}', $vars);
            $greeting = $svc->interpolate($template->body_html ?: '', $vars);
        } else {
            $subject  = $svc->interpolate('Your {{shop_name}} work is complete — #{{ra_number}}', $vars);
            $greeting = $svc->interpolate(
                'Hi {{first_name}} — we finished the work on your service request. Here is everything we did and what it cost.',
                $vars
            );
        }

        $trackPixel = (bool) (($tenant->settings ?? [])['email_track_opens'] ?? true);
        $pixelUrl   = $trackPixel
            ? url('/_e/o/appointment/' . $appt->id . '.gif')
            : '';

        $html = View::make('emails.appointment-receipt', [
            'tenant'      => $tenant,
            'appointment' => $appt,
            'greeting'    => $greeting,
            'subject'     => $subject,
            'track_pixel' => $trackPixel,
            'pixel_url'   => $pixelUrl,
        ])->render();

        try {
            $ok = $svc->sendRendered('appointment_receipt', $to, $subject, $html);
            if ($ok) {
                $this->log($appt, $to, 'sent', null);
            } else {
                $this->log($appt, $to, 'skipped', 'suppressed or send failed');
            }
        } catch (\Throwable $e) {
            Log::error('SendAppointmentReceiptJob failed', [
                'appointment_id' => $appt->id,
                'error'          => $e->getMessage(),
            ]);
            $this->log($appt, $to, 'failed', $e->getMessage());
        }
    }

    private function log(TenantAppointment $appt, string $recipient, string $status, ?string $error): void
    {
        TenantNotificationLog::record([
            'tenant_id'     => $appt->tenant_id,
            'event_type'    => 'appointment_receipt',
            'channel'       => 'email',
            'recipient'     => $recipient,
            'related_type'  => 'appointment',
            'related_id'    => $appt->id,
            'status'        => $status,
            'error_message' => $error,
            'template_key'  => 'appointment_receipt',
        ]);
    }
}
