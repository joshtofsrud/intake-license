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

            // In the tenant's local time, "tomorrow at this hour" is the
            // target window center. Use UTC for the actual query against
            // the DB (Laravel will convert).
            $windowStart = Carbon::now($tz)->addHours(23);
            $windowEnd   = Carbon::now($tz)->addHours(25);

            // appointment_date is a DATE column; appointment_time is a TIME
            // column. Combine in SQL to get the wall-clock datetime in
            // tenant TZ.
            $rows = TenantAppointment::query()
                ->where('tenant_id', $tenant->id)
                ->whereNotIn('status', ['cancelled', 'refunded'])
                ->whereNull('reminded_at')
                // appointment_date between windowStart->date and windowEnd->date
                // narrows by index; the precise time filter happens in PHP.
                ->whereBetween('appointment_date', [
                    $windowStart->copy()->startOfDay()->toDateString(),
                    $windowEnd->copy()->endOfDay()->toDateString(),
                ])
                ->orderBy('appointment_date')
                ->orderBy('appointment_time')
                ->get();

            foreach ($rows as $row) {
                // Combine date + time into a single Carbon in tenant TZ
                $apptTime = $row->appointment_time ?? '00:00:00';
                $combined = Carbon::parse(
                    $row->appointment_date->toDateString() . ' ' . $apptTime,
                    $tz
                );

                if ($combined->lt($windowStart) || $combined->gt($windowEnd)) {
                    continue; // Outside the precise window; skip.
                }

                if ($dry) {
                    $this->line(sprintf(
                        '  WOULD SEND: tenant=%s appt=%s when=%s',
                        $tenant->id, $row->id, $combined->toDateTimeString()
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
        $apptTime = $row->appointment_time ?? '00:00:00';
        $combined = Carbon::parse(
            $row->appointment_date->toDateString() . ' ' . $apptTime, $tz
        );

        return [
            'first_name'       => $row->customer_first_name ?? '',
            'last_name'        => $row->customer_last_name ?? '',
            'shop_name'        => $tenant->name,
            'ra_number'        => $row->ra_number ?? '',
            'appointment_date' => $combined->format('l, F j'),
            'appointment_time' => $combined->format('g:i A'),
            'date_short'       => $combined->format('M j'),
            'accent'           => $tenant->accent_color ?? '#BEF264',
            'accent_text'      => \App\Support\ColorHelper::accentTextColor(
                                      $tenant->accent_color ?? '#BEF264'
                                  ),
        ];
    }

    private function renderSmsBody(Tenant $tenant, array $vars): string
    {
        $shop = $vars['shop_name'];
        $when = $vars['date_short'] . ' at ' . $vars['appointment_time'];
        return "{$shop}: Reminder, your appointment is tomorrow ({$when}). Reply STOP to opt out.";
    }
}
