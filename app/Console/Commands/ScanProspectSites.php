<?php
// the website pass. Runs on the scheduler every five
// minutes; each run reads a batch of prospects' websites that haven't been
// read yet. Pause/Resume lives on Find shops.

namespace App\Console\Commands;

use App\Models\SalesProspect;
use App\Models\SalesSetting;
use App\Services\Sales\SiteScanner;
use App\Support\JobFailureReporter;
use Illuminate\Console\Command;

class ScanProspectSites extends Command
{
    protected $signature = 'sales:scan-sites
        {--limit= : Prospects to read this run (default: the Speed set on Find shops)}
        {--id= : Read just this prospect (ignores pause)}
        {--rescan : Include prospects already read}
        {--force : Run even while stopped}';

    protected $description = "Read prospects' websites for email, socials, owner and brands";

    /** Shops per five-minute run, by the Speed picked on Find shops. */
    public const SPEEDS = ['normal' => 80, 'fast' => 250, 'max' => 600];

    public function handle(SiteScanner $scanner): int
    {
        if (! $this->option('id') && ! $this->option('force') && SalesSetting::get('site_scan_on') !== '1') {
            $this->line('Website pass is stopped (Find shops › Website pass › Start).');
            return self::SUCCESS;
        }
        @set_time_limit(0);

        $q = SalesProspect::query();
        if ($id = $this->option('id')) {
            $q->where('id', $id);
        } else {
            $q->whereNotNull('website')->where('website', '!=', '')->whereNull('tenant_id');
            if (! $this->option('rescan')) $q->whereNull('site_scanned_at');
            $q->orderByRaw("CASE priority WHEN 'A' THEN 0 WHEN 'B' THEN 1 WHEN 'C' THEN 2 ELSE 3 END")->orderBy('created_at');
        }
        $limit = (int) ($this->option('limit') ?: (self::SPEEDS[SalesSetting::get('site_scan_speed', 'normal')] ?? 80));
        $rows = $q->limit(max(1, $limit))->get();
        if ($rows->isEmpty()) { $this->line('Nothing left to read.'); return self::SUCCESS; }

        $tally = [];
        try {
            foreach ($rows->chunk(8) as $chunk) {
                foreach ($scanner->scanMany($chunk) as $status) $tally[$status] = ($tally[$status] ?? 0) + 1;
            }
        } catch (\Throwable $e) {
            // scheduled output is thrown away, so a failure has to be reported to be seen
            JobFailureReporter::report(self::class, 'Website pass stopped after ' . array_sum($tally) . ' shops', $e, ['tally' => $tally]);
            $this->error($e->getMessage());
            return self::FAILURE;
        }
        ksort($tally);
        $this->info('Read ' . array_sum($tally) . ' sites: ' . collect($tally)->map(fn ($n, $k) => "$k $n")->implode(', '));
        return self::SUCCESS;
    }
}
