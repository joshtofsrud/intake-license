<?php

namespace App\Console\Commands;

use App\Models\Tenant;
use App\Services\Tenant\ReportBuilder\ReportSuggestions;
use App\Support\JobFailureReporter;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Nightly "Suggested builds" for Reports › Custom. Runs hourly and checks
 * each shop once a night, after 2am in the shop's own time, so the tiles
 * are fresh when the shop opens. MARKER-REPORT-SUGGEST
 *
 *   --now       check every shop now, whatever the hour (after a deploy)
 *   --tenant=   check one shop now
 */
class ReportsSuggest extends Command
{
    protected $signature   = 'reports:suggest {--now} {--tenant=}';
    protected $description = 'Find report builds worth a look for each shop (Reports › Custom › Suggested builds)';

    public function handle(): int
    {
        $q = Tenant::query();
        if ($this->option('tenant')) {
            $q->where('id', $this->option('tenant'));
        } else {
            if (Schema::hasColumn('tenants', 'is_platform')) $q->where('is_platform', false);
            if (Schema::hasColumn('tenants', 'suspended_at')) $q->whereNull('suspended_at');
            if (Schema::hasColumn('tenants', 'is_active')) $q->where('is_active', true);
        }
        $force = $this->option('now') || $this->option('tenant');
        // A check that never finished (checked_at still empty) is picked up again.
        $last = DB::table('tenant_report_suggest_checks')->whereNotNull('checked_at')->pluck('checked_on', 'tenant_id');

        $done = 0; $found = 0;
        foreach ($q->get() as $tenant) {
            $local = now()->setTimezone($tenant->timezone());
            $today = $local->toDateString();
            if (!$force && ((int) $local->format('G') < 2 || (string) ($last[$tenant->id] ?? '') === $today)) continue;
            try {
                $s = new ReportSuggestions($tenant);
                $items = $s->build();
                $s->store($items, $today);
                $done++; $found += count($items);
                if ($s->errors) {
                    JobFailureReporter::report(self::class, 'Some suggested-build checks failed for a shop',
                        new \RuntimeException(implode(' | ', $s->errors)), ['checks' => $s->errors], $tenant->id);
                }
            } catch (\Throwable $e) {
                DB::table('tenant_report_suggest_checks')->updateOrInsert(['tenant_id' => $tenant->id], ['checked_on' => $today, 'checked_at' => now()]);
                JobFailureReporter::report(self::class, 'Suggested builds could not be worked out for a shop', $e, [], $tenant->id);
            }
        }
        $this->info("shops checked: {$done}, suggestions: {$found}");
        return self::SUCCESS;
    }
}
