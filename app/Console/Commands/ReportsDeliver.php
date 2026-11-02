<?php

namespace App\Console\Commands;

use App\Models\Tenant;
use App\Services\Tenant\ReportBuilder\ReportAccess;
use App\Services\Tenant\ReportBuilder\ReportMailer;
use App\Services\Tenant\ReportBuilder\ReportSchedule;
use App\Support\JobFailureReporter;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/** Email saved reports that are due. MARKER-REPORT-DELIVERY */
class ReportsDeliver extends Command
{
    protected $signature   = 'reports:deliver';
    protected $description = 'Email saved custom reports that are due';

    public function handle(): int
    {
        $isDue = fn () => DB::table('tenant_report_deliveries')
            ->where('email_freq', '!=', 'never')
            ->whereNotNull('next_send_at')
            ->where('next_send_at', '<=', now());
        // Shops without extended reports are paused: left out here so they can't crowd out the rest.
        $paused = [];
        foreach ($isDue()->distinct()->pluck('tenant_id') as $tid) {
            $t = Tenant::find($tid);
            if ($t && !ReportAccess::allowed($t)) $paused[] = $tid;
        }
        $due = $isDue()->when($paused, fn ($q) => $q->whereNotIn('tenant_id', $paused))
            ->orderBy('next_send_at')
            ->limit(200)
            ->get();

        $sent = 0;
        foreach ($due as $d) {
            $tenant = Tenant::find($d->tenant_id);
            $report = DB::table('tenant_saved_reports')->where('tenant_id', $d->tenant_id)->where('id', $d->report_id)->first();
            if (!$tenant || !$report) {
                DB::table('tenant_report_deliveries')->where('id', $d->id)->update(['next_send_at' => null, 'last_error' => 'Report no longer exists.']);
                continue;
            }
            $arr = (array) $d;
            // Move the next send on first, so a slow mail server can't send twice.
            DB::table('tenant_report_deliveries')->where('id', $d->id)->update([
                'next_send_at' => ReportSchedule::next($arr, $tenant, now()), 'updated_at' => now(),
            ]);
            try {
                $to = array_values(array_filter(array_map('trim', explode(',', (string) $d->recipients)), fn ($x) => filter_var($x, FILTER_VALIDATE_EMAIL)));
                $r = (new ReportMailer($tenant))->send($report, $arr, $to);
                DB::table('tenant_report_deliveries')->where('id', $d->id)->update([
                    'last_sent_at' => $r['sent'] ? now() : $d->last_sent_at,
                    'last_error'   => $r['skipped'],
                ]);
                $sent += $r['sent'];
            } catch (\Throwable $e) {
                DB::table('tenant_report_deliveries')->where('id', $d->id)->update(['last_error' => 'Sending failed: ' . mb_substr($e->getMessage(), 0, 200)]);
                JobFailureReporter::report(self::class, 'An emailed report failed to send', $e, ['report_id' => $d->report_id], $d->tenant_id);
            }
        }
        $this->info("report emails sent: {$sent}");
        return self::SUCCESS;
    }
}
