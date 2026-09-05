<?php

namespace App\Jobs;

use App\Models\Tenant;
use App\Models\Tenant\TenantAppointment;
use App\Models\Tenant\TenantNotificationLog;
use App\Services\EmailService;
use App\Services\Sms\SmsService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Sends a booking confirmation to the customer who just booked.
 * Dual-channel (email + SMS), each gated independently by tenant settings.
 * Logs every attempt to tenant_notification_log for audit + support tickets.
 *
 * Pattern mirrors SendWaitlistOfferNotificationJob:
 *  - Queued (don't block the booking flow on third-party APIs)
 *  - 3 retries with 60s backoff
 *  - Each channel has its own try/catch so one failing doesn't kill the other
 *  - Errors get logged but never bubble up to the customer (P15: fail open)
 */
class SendBookingConfirmationJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries   = 3;
    public int $backoff = 60;

    public function __construct(public readonly string $appointmentId) {}

    /**
     * MARKER-NOTIFY-CHOICE — which channels this dispatch may use.
     *
     * null keeps the original behaviour: both channels, each still gated by
     * the tenant's own notification settings. A staff member choosing "text
     * only" passes ['sms'], and the tenant setting can still veto it — this
     * narrows what may be sent, it never overrides a shop's own switch.
     *
     * @var array<int,string>|null
     */
    public ?array $onlyChannels = null;

    public function forChannels(?array $channels): self
    {
        $this->onlyChannels = $channels;
        return $this;
    }

    private function channelAllowed(string $channel): bool
    {
        return $this->onlyChannels === null || in_array($channel, $this->onlyChannels, true);
    }

    public function handle(): void
    {
        $appointment = TenantAppointment::with(['tenant', 'customer', 'items'])
            ->find($this->appointmentId);
        if (!$appointment) return;

        $tenant   = $appointment->tenant;
        $customer = $appointment->customer;
        if (!$tenant || !$customer) return;

        $emailEnabled = $tenant->notificationEnabled('booking_confirmation_email');
        $smsEnabled   = $tenant->notificationEnabled('booking_confirmation_sms');

        // Build the variable bag once — reused across email body interpolation.
        $vars = $this->buildVars($appointment, $tenant);

        // ---- Email channel ----
        if ($emailEnabled && $customer->email && $this->channelAllowed('email')) {
            try {
                EmailService::forTenant($tenant)->send(
                    'booking_confirmation',
                    $customer->email,
                    $vars
                );
                $this->log($tenant, $appointment, 'email', $customer->email, 'sent', null);
            } catch (\Throwable $e) {
                Log::error('Booking confirmation email failed', [
                    'appointment_id' => $appointment->id,
                    'error'          => $e->getMessage(),
                ]);
                \App\Support\JobFailureReporter::report(self::class, 'Booking confirmation email did not send', $e,   // MARKER-JOB-ISSUES-2
                    ['appointment_id' => $appointment->id], $appointment->tenant_id);
                $this->log($tenant, $appointment, 'email', $customer->email, 'failed', $e->getMessage());
            }
        } else {
            $this->log($tenant, $appointment, 'email', $customer->email ?? '(none)', 'skipped',
                $emailEnabled ? 'no email on customer' : 'disabled by tenant');
        }

        // ---- SMS channel ----
        if ($smsEnabled && $customer->phone && $this->channelAllowed('sms')) {
            try {
                SmsService::send($tenant, $customer->phone, $this->buildSmsBody($appointment, $tenant));
                $this->log($tenant, $appointment, 'sms', $customer->phone, 'sent', null);
            } catch (\Throwable $e) {
                Log::error('Booking confirmation SMS failed', [
                    'appointment_id' => $appointment->id,
                    'error'          => $e->getMessage(),
                ]);
                \App\Support\JobFailureReporter::report(self::class, 'Booking confirmation text did not send', $e,   // MARKER-JOB-ISSUES-2
                    ['appointment_id' => $appointment->id], $appointment->tenant_id);
                $this->log($tenant, $appointment, 'sms', $customer->phone, 'failed', $e->getMessage());
            }
        } else {
            $this->log($tenant, $appointment, 'sms', $customer->phone ?? '(none)', 'skipped',
                $smsEnabled ? 'no phone on customer' : 'disabled by tenant');
        }
    }

    /**
     * Build the variable bag for template interpolation.
     * Keys must match the placeholders in EmailService::defaultTemplate('booking_confirmation').
     */
    private function buildVars(TenantAppointment $appointment, Tenant $tenant): array
    {
        $date = $appointment->appointment_date
            ? \Carbon\Carbon::parse($appointment->appointment_date)->format('l, F j, Y')
            : '';
        if ($appointment->appointment_time) {
            $date .= ' at ' . \Carbon\Carbon::parse($appointment->appointment_time)->format('g:i A');
        }

        return [
            'first_name'       => $appointment->customer_first_name ?? '',
            'last_name'        => $appointment->customer_last_name ?? '',
            'ra_number'        => $appointment->ra_number ?? '',
            'appointment_date' => $date,
            'total'            => '$' . number_format(($appointment->total_cents ?? 0) / 100, 2),
            'shop_name'        => $tenant->name ?? '',
        ];
    }

    /**
     * Short-form SMS body. Plain text, no HTML, mindful of the 160-char limit.
     */
    private function buildSmsBody(TenantAppointment $appointment, Tenant $tenant): string
    {
        $date = $appointment->appointment_date
            ? \Carbon\Carbon::parse($appointment->appointment_date)->format('M j')
            : '';
        if ($appointment->appointment_time) {
            $date .= ' at ' . \Carbon\Carbon::parse($appointment->appointment_time)->format('g:i A');
        }

        return sprintf(
            "%s: booking confirmed for %s. Reference: %s",
            $tenant->name,
            $date,
            $appointment->ra_number
        );
    }

    private function log(Tenant $tenant, TenantAppointment $appointment, string $channel, string $recipient, string $status, ?string $error): void
    {
        TenantNotificationLog::record([
            'tenant_id'     => $tenant->id,
            'event_type'    => 'booking_confirmation',
            'channel'       => $channel,
            'recipient'     => $recipient,
            'related_type'  => 'appointment',
            'related_id'    => $appointment->id,
            'status'        => $status,
            'error_message' => $error,
            'template_key'  => $channel === 'email' ? 'booking_confirmation' : null,
        ]);
    }
}
