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
        {--limit=80 : Prospects to read this run}
        {--id= : Read just this prospect (ignores pause)}
        {--rescan : Include prospects already read}
        {--force : Run even while paused}';

    protected $description = "Read prospects' websites for email, socials, owner and brands";

    public function handle(SiteScanner $scanner): int
    {
        if (! $this->option('id') && ! $this->option('force') && SalesSetting::get('site_scan_paused') === '1') {
            $this->line('Website pass is paused (Find shops › Website pass).');
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
        $rows = $q->limit(max(1, (int) $this->option('limit')))->get();
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
