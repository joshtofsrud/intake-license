<?php
// MARKER-JOB-ISSUES

namespace App\Support;

use App\Models\PlatformSettings;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

/**
 * The one place a background job reports that it failed.
 *
 * Writes an error-severity debug_log (so master admin's Unresolved errors
 * sees it, grouped by fingerprint) and emails the platform alert address
 * through the same mute the 500 handler uses. Never throws.
 */
class JobFailureReporter
{
    /**
     * @param string      $jobClass   e.g. ImportDistributorCatalogJob::class
     * @param string      $summary    plain words: "QBP catalog import stopped after 4,000 items"
     * @param \Throwable  $e          what actually broke
     * @param array       $context    ids and links a human needs: tenant, batch_id, code, offset…
     * @param string|null $tenantId
     */
    public static function report(string $jobClass, string $summary, \Throwable $e, array $context = [], ?string $tenantId = null): string
    {
        $refId = 'JOB-' . strtoupper(Str::random(8));
        $site  = basename($e->getFile()) . ':' . $e->getLine();
        $fingerprint = substr(hash('sha256', $jobClass . '|' . get_class($e) . '|' . $site), 0, 64);

        try {
            // Through error(), not job(): the dashboard's Unresolved errors and
            // the "unresolved errors only" filter both look at channel=error.
            // A row on the 'job' channel would be one more place nobody looks.
            $row = debug_log()->error($e, array_merge($context, [
                'ref_id'  => $refId,
                'summary' => $summary,
                'error'   => Str::limit($e->getMessage(), 800),
                'at'      => $e->getFile() . ':' . $e->getLine(),
            ]));
            if ($row) {
                $row->forceFill([
                    'event'       => 'job.failed',
                    'tenant_id'   => $tenantId,
                    'job_class'   => $jobClass,
                    'message'     => Str::limit($summary . ' — ' . $e->getMessage(), 490),
                    'fingerprint' => $fingerprint,
                ])->save();
            }
        } catch (\Throwable $ignored) {
            Log::error('JobFailureReporter could not write the log row: ' . $ignored->getMessage());
        }

        // Always also in the file log, with the refId, for grep.
        Log::error("Job failed {$refId}: {$summary}", ['job' => $jobClass, 'error' => $e->getMessage(), 'at' => $site] + $context);

        try {
            $ps = PlatformSettings::current();
            $on = (bool) ($ps->alert_500_enabled ?? false);
            $to = $ps->alert_500_email ?: null;
            if ($on && $to && Cache::add('jobfail:' . $fingerprint, 1, 900)) {
                $body = "Ref: {$refId}\n"
                    . 'Time: ' . now()->toDateTimeString() . " UTC\n"
                    . 'Job: ' . class_basename($jobClass) . "\n"
                    . ($tenantId ? 'Tenant: ' . $tenantId . "\n" : '')
                    . 'What: ' . $summary . "\n"
                    . 'Error: ' . $e->getMessage() . "\n"
                    . 'At: ' . $site . "\n"
                    . ($context ? 'Context: ' . json_encode($context) . "\n" : '')
                    . "\nSee it: https://" . config('intake.domain', 'intake.works') . "/admin/debug-logs?activeTab=errors\n"
                    . "Log line (server):\ngrep \"{$refId}\" /var/www/intake-shared/storage/logs/laravel-" . now()->toDateString() . ".log\n\n"
                    . '(Repeats of this exact failure are muted for 15 minutes. Same switch as 500 alerts, on the master admin dashboard.)';
                Mail::raw($body, function ($m) use ($to, $refId, $jobClass) {
                    $m->to($to)->subject('[Intake job failed] ' . $refId . ' — ' . class_basename($jobClass));
                });
            }
        } catch (\Throwable $mailFail) {
            Log::warning('Job failure alert email failed: ' . $mailFail->getMessage());
        }

        return $refId;
    }
}
