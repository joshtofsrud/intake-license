<?php

namespace App\Console\Commands;

use App\Models\Tenant;
use App\Services\Tenant\ReportBuilder\ReportAccess;
use App\Services\Tenant\ReportBuilder\ReportMailer;
use App\Support\JobFailureReporter;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Check report alerts once a day, early in each shop's own morning.
 * One email per change, not one per day. MARKER-REPORT-DELIVERY
 */
class ReportsCheckAlerts extends Command
{
    protected $signature   = 'reports:check-alerts';
    protected $description = 'Check custom report alerts and email owners when one is crossed';

    public function handle(): int
    {
        $rows = DB::table('tenant_report_deliveries')->where('alert_on', 1)->get();
        $checked = 0; $sent = 0;
        foreach ($rows as $d) {
            $tenant = Tenant::find($d->tenant_id);
            if (!$tenant || !ReportAccess::allowed($tenant)) continue;   // alerts pause while the shop has no extended reports
            $local = now()->setTimezone($tenant->timezone());
            if ((int) $local->format('G') < 6 || $d->alert_checked_on === $local->toDateString()) continue;
            $report = DB::table('tenant_saved_reports')->where('tenant_id', $d->tenant_id)->where('id', $d->report_id)->first();
            if (!$report) continue;
            try {
                $r = (new ReportMailer($tenant))->checkAlert($report, (array) $d);
                DB::table('tenant_report_deliveries')->where('id', $d->id)->update([
                    'alert_state' => json_encode($r['state']), 'alert_checked_on' => $local->toDateString(),
                    'alert_last_at' => $r['sent'] ? now() : $d->alert_last_at,
                ]);
                $checked++;
                if ($r['sent']) $sent++;
            } catch (\Throwable $e) {
                DB::table('tenant_report_deliveries')->where('id', $d->id)->update(['alert_checked_on' => $local->toDateString()]);
                JobFailureReporter::report(self::class, 'A report alert could not be checked', $e, ['report_id' => $d->report_id], $d->tenant_id);
            }
        }
        $this->info("alerts checked: {$checked}, sent: {$sent}");
        return self::SUCCESS;
    }
}
