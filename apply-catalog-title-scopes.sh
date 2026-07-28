#!/bin/bash
# catalog-title-scopes — the index the rebuilt Catalog Titles page reads from.
#   Patch 1 of 3. This one is data only; no UI changes yet.
#
#   The page has to answer "which of my 1,284 category scopes have bad titles"
#   across 60+ distributors and tens of thousands of rows each. That can't be
#   computed per page load, so it gets materialized into catalog_title_scopes
#   by a command, and the page just reads the table.
#
#   IMPORTANT — category_key here is the DISTRIBUTOR's category_path, straight
#   off the feed (e.g. "Tires > Mountain > Tubeless Ready"). It is never a
#   tenant category. Tenants build their own trees and rename them freely;
#   nothing they do can move a title rule, and no rule can leak between
#   tenants. Anything reading this table must keep that boundary.
#
#   Health flags are computed from a bounded sample per scope (200 rows, taken
#   in id order, not randomly) so a full scan stays predictable at volume:
#     size_from_tpi   {size} looks like a thread count while a real size
#                     attribute exists and differs — the Maxxis 60×2 bug
#     token_empty     a token in the effective template is blank on >50%
#     duplicates      distinct titles under 60% of the sample
#     too_long        average title over 90 characters
#     missing_brand   brand blank on >10%
#   Each flag carries its own detail string so the page can say what's wrong
#   without recomputing anything.
#
#   Also adds (distributor_code, category_path) to the catalog table — without
#   it the per-scope sampling degrades into a table scan per scope.
# MIGRATION REQUIRED. After deploy run: php artisan catalog:scan-titles
set -e
if [ -f app/Services/Distributors/CatalogTitleHealthService.php ]; then
  echo "catalog-title-scopes already applied — aborting."; exit 1
fi

# ------------------------------------------------------------------ migration
cat > 'database/migrations/2026_07_28_000001_create_catalog_title_scopes.php' <<'CTS_0_EOF'
<?php

// MARKER-TITLE-SCOPES — materialized coverage + health index for the
// Catalog Titles page. One row per (distributor, distributor category path).

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('catalog_title_scopes', function (Blueprint $t) {
            $t->id();

            $t->string('distributor_code', 32);

            // The DISTRIBUTOR's category path from the feed — not a tenant
            // category. '' is possible for feed rows with no path at all.
            $t->string('category_key', 191)->default('');

            $t->unsignedInteger('item_count')->default(0);

            // Which rule actually applies, after prefix resolution. Null means
            // nothing more specific than the global default matched.
            $t->string('resolved_rule_scope', 191)->nullable();
            $t->boolean('has_own_rule')->default(false);

            // [{code,label,severity,detail}, ...] — empty array = healthy.
            $t->json('flags')->nullable();

            // A few catalog row ids to render previews from, so the editor
            // never has to go hunting for representative items.
            $t->json('sample_ids')->nullable();

            $t->string('sample_title', 255)->nullable();

            $t->boolean('reviewed')->default(false);
            $t->timestamp('reviewed_at')->nullable();

            $t->timestamp('scanned_at')->nullable();
            $t->timestamps();

            $t->unique(['distributor_code', 'category_key'], 'cts_scope_unique');
            $t->index(['distributor_code', 'reviewed'], 'cts_dist_reviewed_idx');
        });

        // Per-scope sampling walks this. Without it every scope is a scan.
        Schema::table('platform_distributor_catalogs', function (Blueprint $t) {
            $t->index(['distributor_code', 'category_path'], 'pdc_dist_catpath_idx');
        });
    }

    public function down(): void
    {
        Schema::table('platform_distributor_catalogs', function (Blueprint $t) {
            $t->dropIndex('pdc_dist_catpath_idx');
        });
        Schema::dropIfExists('catalog_title_scopes');
    }
};
CTS_0_EOF

# ------------------------------------------------------------------ model
cat > 'app/Models/CatalogTitleScope.php' <<'CTS_1_EOF'
<?php

// MARKER-TITLE-SCOPES

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * One row per (distributor, distributor category path).
 *
 * category_key is the distributor's own path from the feed. It is NOT a
 * tenant category and must never be populated from tenant data — tenants
 * build and rename their own category trees, and title rules have to stay
 * independent of that.
 */
class CatalogTitleScope extends Model
{
    protected $fillable = [
        'distributor_code', 'category_key', 'item_count',
        'resolved_rule_scope', 'has_own_rule', 'flags', 'sample_ids',
        'sample_title', 'reviewed', 'reviewed_at', 'scanned_at',
    ];

    protected $casts = [
        'flags'        => 'array',
        'sample_ids'   => 'array',
        'has_own_rule' => 'boolean',
        'reviewed'     => 'boolean',
        'reviewed_at'  => 'datetime',
        'scanned_at'   => 'datetime',
    ];

    public function isHealthy(): bool
    {
        return empty($this->flags);
    }

    /** Worst severity present: 'bad' > 'warn' > null. */
    public function severity(): ?string
    {
        $codes = array_column($this->flags ?? [], 'severity');
        if (in_array('bad', $codes, true))  return 'bad';
        if (in_array('warn', $codes, true)) return 'warn';
        return null;
    }
}
CTS_1_EOF

# ------------------------------------------------------------------ service
cat > 'app/Services/Distributors/CatalogTitleHealthService.php' <<'CTS_2_EOF'
<?php

// MARKER-TITLE-SCOPES

namespace App\Services\Distributors;

use App\Models\PlatformDistributorCatalog;

/**
 * Looks at a sample of rows from one distributor category and reports what
 * is wrong with the titles the current rule produces.
 *
 * Everything here is judged from the DISTRIBUTOR's feed data. Tenant
 * categories and tenant item names are deliberately out of scope.
 */
class CatalogTitleHealthService
{
    /** Rows sampled per scope. Bounded on purpose — this runs 1,284 times. */
    public const SAMPLE = 200;

    public function __construct(private CatalogTitleComposer $composer) {}

    /**
     * @param  \Illuminate\Support\Collection<int,PlatformDistributorCatalog> $rows
     * @return array{flags:array,sample_title:?string}
     */
    public function inspect(string $distributorCode, $rows): array
    {
        if ($rows->isEmpty()) {
            return ['flags' => [], 'sample_title' => null];
        }

        $titles = [];
        $emptyToken = [];      // token => count of rows where it resolved blank
        $noBrand = 0;
        $tpiLooking = 0;

        foreach ($rows as $row) {
            $parts    = $this->composer->partsFromRow($row);
            $composed = $this->composer->compose($distributorCode, $parts);
            $titles[] = $composed['title'];

            if (trim((string) $row->manufacturer) === '') {
                $noBrand++;
            }

            // A size that looks like a thread count while the row also carries
            // a real size attribute that disagrees. This is the 60×2 signature.
            $size = trim((string) ($composed['size'] ?? ''));
            if ($size !== '' && preg_match('/^\d{2,3}([x\x{d7}]\d)?$/u', $size)) {
                $labeled = $this->attr($row, ['Labeled Size', 'Size']);
                if ($labeled !== '' && stripos($labeled, $size) === false) {
                    $tpiLooking++;
                }
            }

            foreach ($this->tokensIn($distributorCode) as $token) {
                if (trim($this->resolveOne($token, $composed, $parts)) === '') {
                    $emptyToken[$token] = ($emptyToken[$token] ?? 0) + 1;
                }
            }
        }

        $n = count($titles);
        $flags = [];

        if ($tpiLooking > 0) {
            $pct = (int) round($tpiLooking / $n * 100);
            $flags[] = $this->flag('size_from_tpi', 'size looks like a thread count',
                'bad', "{$pct}% of sampled items have a Labeled Size that disagrees with {size}");
        }

        foreach ($emptyToken as $token => $count) {
            if ($count / $n > 0.5) {
                $pct = (int) round($count / $n * 100);
                $flags[] = $this->flag('token_empty', "{$token} is usually empty",
                    'warn', "blank on {$pct}% of sampled items");
            }
        }

        $distinct = count(array_unique($titles));
        if ($n >= 20 && $distinct / $n < 0.6) {
            $flags[] = $this->flag('duplicates', 'many items share a title',
                'warn', "{$distinct} distinct titles across {$n} sampled items");
        }

        $avg = (int) round(array_sum(array_map('mb_strlen', $titles)) / $n);
        if ($avg > 90) {
            $flags[] = $this->flag('too_long', 'titles run long',
                'warn', "averaging {$avg} characters");
        }

        if ($noBrand / $n > 0.1) {
            $pct = (int) round($noBrand / $n * 100);
            $flags[] = $this->flag('missing_brand', 'brand missing',
                'warn', "no manufacturer on {$pct}% of sampled items");
        }

        return ['flags' => $flags, 'sample_title' => $titles[0] ?? null];
    }

    private function flag(string $code, string $label, string $severity, string $detail): array
    {
        return compact('code', 'label', 'severity', 'detail');
    }

    /** Tokens used by the effective title template for this distributor. */
    private function tokensIn(string $code): array
    {
        static $cache = [];
        if (! isset($cache[$code])) {
            $tpl = $this->composer->titleTemplateFor($code);
            preg_match_all('/\{([^}]+)\}/', $tpl, $m);
            $cache[$code] = array_values(array_unique(array_map('trim', $m[1] ?? [])));
        }
        return $cache[$code];
    }

    /** Best-effort single-token resolution against an already-composed row. */
    private function resolveOne(string $token, array $composed, array $parts): string
    {
        if ($token === 'size')  return (string) ($composed['size'] ?? '');
        if ($token === 'color') return (string) ($composed['color'] ?? '');
        if (str_starts_with($token, 'attr:')) {
            $want = trim(substr($token, 5));
            foreach (($parts['attributes'] ?? []) as $a) {
                if (is_array($a) && isset($a['Name'], $a['Value'])
                    && strcasecmp((string) $a['Name'], $want) === 0) {
                    return (string) $a['Value'];
                }
            }
            return '';
        }
        $map = ['brand' => 'brand', 'model' => 'model', 'mpn' => 'mpn', 'unit' => 'unit'];
        return isset($map[$token]) ? (string) ($parts[$map[$token]] ?? '') : '';
    }

    private function attr(PlatformDistributorCatalog $row, array $names): string
    {
        foreach (($row->attributes ?? []) as $a) {
            if (! is_array($a) || ! isset($a['Name'], $a['Value'])) continue;
            foreach ($names as $want) {
                if (strcasecmp((string) $a['Name'], $want) === 0) {
                    return trim((string) $a['Value']);
                }
            }
        }
        return '';
    }
}
CTS_2_EOF

# ------------------------------------------------------------------ command
cat > 'app/Console/Commands/ScanCatalogTitleScopes.php' <<'CTS_3_EOF'
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
CTS_3_EOF

# ------------------------------------------------------------------ composer helper
python3 - <<'CTS_4_EOF'
import io
p = 'app/Services/Distributors/CatalogTitleComposer.php'
s = io.open(p, encoding='utf-8').read()

old = """    public function extractSize(string $distributorCode, string $description, string $categoryPath = ''): string"""
assert s.count(old) == 1, s.count(old)
new = """    /**
     * MARKER-TITLE-SCOPES \u2014 the effective title template for a distributor's
     * catch-all scope, so the health scan knows which tokens to check for
     * emptiness without re-deriving the template itself.
     */
    public function titleTemplateFor(string $distributorCode, string $categoryKey = ''): string
    {
        return $this->setting($distributorCode, $categoryKey)->title_template
            ?: self::FALLBACK_TITLE;
    }

    public function extractSize(string $distributorCode, string $description, string $categoryPath = ''): string"""
io.open(p, 'w', encoding='utf-8').write(s.replace(old, new))
print('composer helper ok')
CTS_4_EOF

php -l app/Models/CatalogTitleScope.php
php -l app/Services/Distributors/CatalogTitleHealthService.php
php -l app/Console/Commands/ScanCatalogTitleScopes.php
php -l app/Services/Distributors/CatalogTitleComposer.php

echo
echo "catalog-title-scopes applied."
