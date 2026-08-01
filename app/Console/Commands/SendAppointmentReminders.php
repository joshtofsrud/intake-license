<?php
// MARKER-PATCH-154

namespace App\Console\Commands;

use App\Models\Tenant;
use App\Models\Tenant\TenantAppointment;
use App\Models\Tenant\TenantEmailSuppression;
use App\Services\EmailService;
use App\Services\Sms\SmsService;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Send 24-hour reminders for appointments.
 *
 * Cron: hourly. For each active tenant:
 *   - In the tenant's local timezone, compute the window 23h–25h from now.
 *   - Find appointments whose (appointment_date + appointment_time) falls
 *     in that window, status not cancelled/refunded, reminded_at IS NULL.
 *   - For each row: send email + SMS via existing services (respecting
 *     per-tenant toggles + suppression list); stamp reminded_at.
 *
 * Idempotence:
 *   - reminded_at is the guard. We stamp it even when the send "succeeds
 *     to zero channels" (both toggles off, or no email/phone on file).
 *     That keeps the cron from churning the same row every hour for a
 *     tenant that opted out.
 *   - Failures are logged AND stamped — better to under-notify than to
 *     re-attempt forever and spam the customer if a transient error
 *     resolves later.
 *
 * Window math:
 *   - 23–25h window absorbs scheduler clock drift + hourly cron jitter.
 *   - Hourly run picks up rows that landed in window since last pass.
 *   - reminded_at guard prevents double-sends in the overlap.
 */
class SendAppointmentReminders extends Command
{
    protected $signature = 'appointments:remind
                            {--dry-run : Show what would be sent without sending}
                            {--tenant= : Limit to a single tenant ID (debug)}';

    protected $description = 'Send 24-hour reminders for upcoming appointments.';

    public function handle(): int
    {
        $dry      = (bool) $this->option('dry-run');
        $tenantId = $this->option('tenant');

        $tenants = Tenant::query()->where('is_active', true);
        if ($tenantId) $tenants->where('id', $tenantId);

        $totalSent = 0;
        $totalSkipped = 0;
        $totalTenants = 0;

        foreach ($tenants->cursor() as $tenant) {
            $totalTenants++;
            $tz = $tenant->timezone ?? config('app.timezone', 'UTC');

            // MARKER-PATCH-154-FIX1 — two reminder modes
            //
            //  Time-slot appointments (have appointment_time): fire 23-25h before
            //  the precise scheduled wall-clock time. Hourly cron + reminded_at
            //  guard ensures one send per row.
            //
            //  Drop-off appointments (appointment_time is null): fire at a fixed
            //  hour the day before. Default is 10am tenant local. Only the cron
            //  tick that lands in the 10am hour fires these reminders.

            $nowLocal    = Carbon::now($tz);
            $windowStart = $nowLocal->copy()->addHours(23);
            $windowEnd   = $nowLocal->copy()->addHours(25);

            // Drop-off branch only fires when the local hour == 10.
            $isDropoffReminderHour = ((int) $nowLocal->format('G')) === 10;
            $tomorrowDate          = $nowLocal->copy()->addDay()->toDateString();

            // Pull a single window of candidate rows. Cover both:
            //  - appointment_date in [today, day-after-tomorrow] for time-slot
            //  - appointment_date == tomorrow for drop-off
            $rows = TenantAppointment::query()
                ->where('tenant_id', $tenant->id)
                ->whereNotIn('status', ['cancelled', 'refunded'])
                ->whereNull('reminded_at')
                // MARKER-REMINDER-CONFIRMATION-GATE — only appointments the
                // customer was actually told about. Presence of a row is the
                // whole test: status is ignored on purpose, because a failed
                // confirmation should still get its reminder, and because
                // the job logs 'skipped' rows too. A work-up dispatches no
                // job at all, so it has no rows and stays silent.
                // Uses the (tenant_id, related_type, related_id) index.
                ->whereExists(function ($q) use ($tenant) {
                    $q->selectRaw('1')
                      ->from('tenant_notification_log as ncf')
                      ->whereColumn('ncf.related_id', 'tenant_appointments.id')
                      ->where('ncf.tenant_id', $tenant->id)
                      ->where('ncf.related_type', 'appointment')
                      ->where('ncf.event_type', 'booking_confirmation');
                })
                ->whereBetween('appointment_date', [
                    $windowStart->copy()->startOfDay()->toDateString(),
                    $windowEnd->copy()->endOfDay()->toDateString(),
                ])
                ->orderBy('appointment_date')
                ->orderBy('appointment_time')
                ->get();

            foreach ($rows as $row) {
                $isDropoff = empty($row->appointment_time);

                if ($isDropoff) {
                    // Drop-off: fire only at 10am tenant local on the day-before.
                    if (!$isDropoffReminderHour) continue;
                    if ($row->appointment_date->toDateString() !== $tomorrowDate) continue;
                } else {
                    // Time-slot: precise 23-25h window check.
                    $combined = Carbon::parse(
                        $row->appointment_date->toDateString() . ' ' . $row->appointment_time,
                        $tz
                    );
                    if ($combined->lt($windowStart) || $combined->gt($windowEnd)) continue;
                }

                if ($dry) {
                    $this->line(sprintf(
                        '  WOULD SEND: tenant=%s appt=%s mode=%s date=%s',
                        $tenant->id,
                        $row->id,
                        $isDropoff ? 'dropoff' : 'time-slot',
                        $row->appointment_date->toDateString()
                    ));
                    $totalSent++;
                    continue;
                }

                $this->sendReminder($tenant, $row, $tz);
                $totalSent++;
            }
        }

        $this->info(sprintf(
            'Done. tenants=%d sent=%d skipped=%d%s',
            $totalTenants,
            $totalSent,
            $totalSkipped,
            $dry ? ' (DRY RUN)' : ''
        ));

        return self::SUCCESS;
    }

    /**
     * Send the reminder for a single appointment. Always stamps
     * reminded_at — even when zero channels fire — so we don't
     * re-attempt next hour.
     */
    private function sendReminder(Tenant $tenant, TenantAppointment $row, string $tz): void
    {
        $vars = $this->buildVars($tenant, $row, $tz);
        $channels = [];

        // EMAIL
        if (
            $tenant->notificationEnabled('appointment_reminder_email')
            && !empty($row->customer_email)
        ) {
            try {
                EmailService::forTenant($tenant)->send(
                    'appointment_reminder',
                    $row->customer_email,
                    $vars
                );
                $channels[] = 'email';
            } catch (\Throwable $e) {
                Log::error('Appointment reminder email failed', [
                    'tenant_id'      => $tenant->id,
                    'appointment_id' => $row->id,
                    'error'          => $e->getMessage(),
                ]);
            }
        }

        // SMS
        if (
            $tenant->notificationEnabled('appointment_reminder_sms')
            && !empty($row->customer_phone)
        ) {
            try {
                $body = $this->renderSmsBody($tenant, $vars);
                SmsService::send($tenant, $row->customer_phone, $body);
                $channels[] = 'sms';
            } catch (\Throwable $e) {
                Log::error('Appointment reminder SMS failed', [
                    'tenant_id'      => $tenant->id,
                    'appointment_id' => $row->id,
                    'error'          => $e->getMessage(),
                ]);
            }
        }

        // Stamp reminded_at regardless — see class doc for rationale.
        $row->update(['reminded_at' => now()]);

        if (!empty($channels)) {
            $this->line(sprintf(
                '  sent: appt=%s channels=%s', $row->id, implode(',', $channels)
            ));
        }
    }

    private function buildVars(Tenant $tenant, TenantAppointment $row, string $tz): array
    {
        // MARKER-PATCH-154-FIX1 — date-only appointments leave time blank
        $isDropoff = empty($row->appointment_time);

        $dateOnly = Carbon::parse($row->appointment_date->toDateString(), $tz);

        if ($isDropoff) {
            $appointment_time = '';            // empty so templates can swallow it
            $when_human       = $dateOnly->format('l, F j');
            $when_sms         = $dateOnly->format('l (M j)');
        } else {
            $combined = Carbon::parse(
                $row->appointment_date->toDateString() . ' ' . $row->appointment_time, $tz
            );
            $appointment_time = $combined->format('g:i A');
            $when_human       = $dateOnly->format('l, F j') . ' at ' . $appointment_time;
            $when_sms         = $dateOnly->format('M j') . ' at ' . $appointment_time;
        }

        return [
            'first_name'       => $row->customer_first_name ?? '',
            'last_name'        => $row->customer_last_name ?? '',
            'shop_name'        => $tenant->name,
            'ra_number'        => $row->ra_number ?? '',
            'appointment_date' => $dateOnly->format('l, F j'),
            'appointment_time' => $appointment_time,   // empty for drop-off
            'when_human'       => $when_human,         // composes date + time
            'when_sms'         => $when_sms,
            'date_short'       => $dateOnly->format('M j'),
            'accent'           => $tenant->accent_color ?? '#BEF264',
            'accent_text'      => \App\Support\ColorHelper::accentTextColor(
                                      $tenant->accent_color ?? '#BEF264'
                                  ),
        ];
    }

    private function renderSmsBody(Tenant $tenant, array $vars): string
    {
        $shop = $vars['shop_name'];
        // MARKER-PATCH-154-FIX1 — use when_sms (handles both modes)
        return "{$shop}: Reminder, your appointment is tomorrow ({$vars['when_sms']}). Reply STOP to opt out.";
    }
}
