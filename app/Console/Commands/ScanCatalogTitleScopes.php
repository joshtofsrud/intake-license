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
                    // MARKER-FLAG-TUNING
                    'severity'            => CatalogTitleHealthService::worstSeverity($flags),
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

        // MARKER-FLAG-TUNING — only warn/bad counts as needing review.
        $base = fn () => CatalogTitleScope::query()
            ->when($code, fn ($q) => $q->where('distributor_code', $code));

        $flagged = $base()->whereIn('severity', ['warn', 'bad'])->count();
        $info    = $base()->where('severity', 'info')->count();
        $clean   = $base()->whereNull('severity')->count();

        $this->info("Scanned {$groups->count()} scopes · {$flagged} need review · {$info} info only · {$clean} clean · {$stale->count()} stale removed");
        return self::SUCCESS;
    }

    /**
     * MARKER-ONE-RESOLVER — delegates to the composer instead of walking
     * the candidate ladder again. The previous local copy compared category
     * keys with exact string equality while the composer normalises them,
     * so a scope could resolve to "no rule" here and to a real rule at
     * render time — which is exactly what made the editor show the
     * catch-all for HLC · Tires > Mountain Tires.
     *
     * @return array{0:?string,1:bool} [matched category_key or null, is it this scope's own rule]
     */
    private function resolveRule(string $dist, string $cat): array
    {
        $row = app(\App\Services\Distributors\CatalogTitleComposer::class)
            ->matchedSetting($dist, $cat);

        if (! $row) {
            return [null, false];
        }

        $key = (string) $row->category_key;

        return [
            $key === '' ? null : $key,
            $key !== '' && $key === $cat && $row->distributor_code === $dist,
        ];
    }
}
