<?php
// MARKER-PATCH-155

namespace App\Console\Commands;

use App\Models\Tenant;
use App\Models\Tenant\TenantDelivery;
use App\Services\Tenant\TenantDeliveryNotificationService;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Send 24-hour reminders for upcoming deliveries.
 *
 * Cron: hourly. For each active tenant:
 *   - In tenant local timezone, compute the 23-25h window from now.
 *   - Find tenant_deliveries with scheduled_at in window, status not
 *     cancelled, reminded_at IS NULL.
 *   - For each row: call TenantDeliveryNotificationService::sendReminder()
 *     which sends email + SMS via existing services and respects toggles.
 *   - Stamp reminded_at on the row regardless of send outcome (idempotence).
 *
 * Simpler than appointments:remind because deliveries always have a
 * precise scheduled_at — no drop-off mode quirk to handle.
 */
class SendDeliveryReminders extends Command
{
    protected $signature = 'deliveries:remind
                            {--dry-run : Show what would be sent without sending}
                            {--tenant= : Limit to a single tenant ID (debug)}';

    protected $description = 'Send 24-hour reminders for upcoming deliveries.';

    public function handle(): int
    {
        $dry      = (bool) $this->option('dry-run');
        $tenantId = $this->option('tenant');

        $tenants = Tenant::query()->where('is_active', true);
        if ($tenantId) $tenants->where('id', $tenantId);

        $totalSent = 0;
        $totalTenants = 0;

        foreach ($tenants->cursor() as $tenant) {
            $totalTenants++;
            $tz = $tenant->timezone ?? config('app.timezone', 'UTC');

            $windowStart = Carbon::now($tz)->addHours(23);
            $windowEnd   = Carbon::now($tz)->addHours(25);

            // scheduled_at is stored as UTC datetime; Laravel/Carbon handles the
            // conversion. whereBetween compares Carbon instances against the
            // column natively.
            $rows = TenantDelivery::query()
                ->with('customer')
                ->where('tenant_id', $tenant->id)
                ->where('status', '!=', TenantDelivery::STATUS_CANCELLED)
                ->whereNull('reminded_at')
                ->whereBetween('scheduled_at', [$windowStart, $windowEnd])
                ->orderBy('scheduled_at')
                ->get();

            foreach ($rows as $row) {
                if ($dry) {
                    $local = $row->scheduled_at->copy()->setTimezone($tz);
                    $this->line(sprintf(
                        '  WOULD SEND: tenant=%s delivery=%s type=%s when=%s',
                        $tenant->id, $row->id, $row->type,
                        $local->toDateTimeString()
                    ));
                    $totalSent++;
                    continue;
                }

                $channels = TenantDeliveryNotificationService::forTenant($tenant)
                    ->sendReminder($row);

                // Stamp reminded_at regardless of outcome to prevent retries
                $row->update(['reminded_at' => now()]);

                if (!empty($channels)) {
                    $this->line(sprintf(
                        '  sent: delivery=%s channels=%s',
                        $row->id, implode(',', $channels)
                    ));
                }

                $totalSent++;
            }
        }

        $this->info(sprintf(
            'Done. tenants=%d sent=%d%s',
            $totalTenants, $totalSent, $dry ? ' (DRY RUN)' : ''
        ));

        return self::SUCCESS;
    }
}
