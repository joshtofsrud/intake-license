<?php

// MARKER-TITLE-SCOPES

namespace App\Console\Commands;

use App\Models\CatalogTitleScope;
use App\Models\CatalogTitleSetting;
use App\Models\PlatformDistributorCatalog;
use App\Services\Distributors\CatalogTitleHealthService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Rebuilds catalog_title_scopes: one row per distributor category path, with
 * item counts, which rule resolves for it, and what's wrong with the titles.
 *
 * Safe to re-run. Scopes that no longer have items are dropped.
 */
class ScanCatalogTitleScopes extends Command
{
    protected $signature = 'catalog:scan-titles
        {code? : distributor code, omit for all}
        {--skip-health : counts and rule resolution only, no sampling}';

    protected $description = 'Rebuild the catalog title coverage + health index';

    public function handle(CatalogTitleHealthService $health): int
    {
        $code = $this->argument('code');

        $groups = PlatformDistributorCatalog::query()
            ->when($code, fn ($q) => $q->where('distributor_code', $code))
            ->where('is_active', true)
            ->select('distributor_code',
                DB::raw("COALESCE(category_path,'') as cat"),
                DB::raw('COUNT(*) as n'))
            ->groupBy('distributor_code', 'cat')
            ->get();

        $this->info("Scopes found: {$groups->count()}");
        $bar = $this->output->createProgressBar($groups->count());

        $seen = [];
        foreach ($groups as $g) {
            $dist = $g->distributor_code;
            $cat  = (string) $g->cat;
            $seen[] = $dist . "\0" . $cat;

            $flags = [];
            $sampleIds = [];
            $sampleTitle = null;

            if (! $this->option('skip-health')) {
                $rows = PlatformDistributorCatalog::query()
                    ->where('distributor_code', $dist)
                    ->where(fn ($q) => $cat === ''
                        ? $q->whereNull('category_path')->orWhere('category_path', '')
                        : $q->where('category_path', $cat))
                    ->where('is_active', true)
                    ->orderBy('id')
                    ->limit(CatalogTitleHealthService::SAMPLE)
                    ->get();

                $result     = $health->inspect($dist, $rows);
                $flags      = $result['flags'];
                $sampleTitle = $result['sample_title'];
                $sampleIds  = $rows->take(5)->pluck('id')->all();
            }

            [$ruleScope, $own] = $this->resolveRule($dist, $cat);

            CatalogTitleScope::updateOrCreate(
                ['distributor_code' => $dist, 'category_key' => $cat],
                [
                    'item_count'          => (int) $g->n,
                    'resolved_rule_scope' => $ruleScope,
                    'has_own_rule'        => $own,
                    'flags'               => $flags,
                    'sample_ids'          => $sampleIds,
                    'sample_title'        => $sampleTitle,
                    'scanned_at'          => now(),
                ]
            );

            $bar->advance();
        }
        $bar->finish();
        $this->newLine(2);

        // Drop scopes whose items are gone, so the page never lists a category
        // that no longer exists in the feed.
        $stale = CatalogTitleScope::query()
            ->when($code, fn ($q) => $q->where('distributor_code', $code))
            ->get()
            ->reject(fn ($s) => in_array($s->distributor_code . "\0" . $s->category_key, $seen, true));

        foreach ($stale as $s) { $s->delete(); }

        $flagged = CatalogTitleScope::query()
            ->when($code, fn ($q) => $q->where('distributor_code', $code))
            ->whereNotNull('flags')->where('flags', '!=', '[]')->count();

        $this->info("Scanned {$groups->count()} scopes · {$flagged} flagged · {$stale->count()} stale removed");
        return self::SUCCESS;
    }

    /**
     * Which rule row wins for this category, using the same prefix ladder the
     * composer uses. Returns [resolved category_key or null, is it this
     * scope's own rule].
     */
    private function resolveRule(string $dist, string $cat): array
    {
        $candidates = [];
        if ($cat !== '') {
            $segs = array_map('trim', preg_split('/>+/', $cat));
            for ($i = count($segs); $i > 0; $i--) {
                $candidates[] = implode(' > ', array_slice($segs, 0, $i));
            }
        }
        $candidates[] = '';

        foreach ($candidates as $c) {
            $row = CatalogTitleSetting::where('is_active', true)
                ->where('distributor_code', $dist)
                ->where('category_key', $c)
                ->first();
            if ($row) {
                return [$c === '' ? null : $c, $c === $cat && $cat !== ''];
            }
        }
        return [null, false];
    }
}
