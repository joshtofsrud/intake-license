#!/bin/bash
# catalog-title-flag-tuning — makes the flag set mean something.
#   First scan on HLC flagged 401 of 401 scopes, which narrows nothing.
#   Breakdown was token_empty 894, duplicates 48, size_from_tpi 1.
#
#   token_empty was the noise. The HLC catch-all is
#   {brand} {model} {size} {color} {unit} {type0}, and {size}/{color} are
#   blank on nearly everything that isn't a tire — but an empty token is not
#   a defect, the renderer already collapses the gap. It's useful context
#   when you're editing a template and useless as a queue item.
#
#   So severity gains an 'info' level. Info findings still ride along on the
#   scope (the editor shows "{size} blank on 94% here") but never put the
#   scope in the queue. Only warn/bad do. Expected result on HLC: ~49
#   flagged instead of 401.
#
#   Also adds a `severity` column. The page has to filter 1,284+ scopes by
#   "needs attention", and filtering inside a json column doesn't use an
#   index. Worst severity is denormalized at scan time and indexed.
# MIGRATION REQUIRED. After deploy re-run: php artisan catalog:scan-titles HLC
set -e
if grep -q "MARKER-FLAG-TUNING" app/Services/Distributors/CatalogTitleHealthService.php; then
  echo "catalog-title-flag-tuning already applied — aborting."; exit 1
fi

# ------------------------------------------------------------------ migration
cat > 'database/migrations/2026_07_28_000002_add_severity_to_catalog_title_scopes.php' <<'CTFT_0_EOF'
<?php

// MARKER-FLAG-TUNING — denormalized worst severity, so the page can filter
// "needs attention" on an index instead of unpacking a json column per row.

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('catalog_title_scopes', function (Blueprint $t) {
            // null = clean · 'info' = context only · 'warn' · 'bad'
            $t->string('severity', 8)->nullable()->after('flags');
            $t->index(['distributor_code', 'severity'], 'cts_dist_sev_idx');
        });
    }

    public function down(): void
    {
        Schema::table('catalog_title_scopes', function (Blueprint $t) {
            $t->dropIndex('cts_dist_sev_idx');
            $t->dropColumn('severity');
        });
    }
};
CTFT_0_EOF

# ------------------------------------------------------------------ service
python3 - <<'CTFT_1_EOF'
import io
p = 'app/Services/Distributors/CatalogTitleHealthService.php'
s = io.open(p, encoding='utf-8').read()

# token_empty drops to info
old = """            if ($count / $n > 0.5) {
                $pct = (int) round($count / $n * 100);
                $flags[] = $this->flag('token_empty', "{$token} is usually empty",
                    'warn', "blank on {$pct}% of sampled items");
            }"""
assert s.count(old) == 1, s.count(old)
new = """            if ($count / $n > 0.5) {
                $pct = (int) round($count / $n * 100);
                // MARKER-FLAG-TUNING \u2014 'info', not 'warn'. An empty token is
                // normal: {size} and {color} are blank on most non-tire
                // categories and render() already collapses the gap. This is
                // context for whoever edits the template, not a defect, and
                // it must never put a scope in the review queue \u2014 it fired
                // 894 times across 401 HLC scopes when it did.
                $flags[] = $this->flag('token_empty', "{$token} is usually empty",
                    'info', "blank on {$pct}% of sampled items");
            }"""
s = s.replace(old, new)

# helper: worst severity for a flag set
old = """    private function flag(string $code, string $label, string $severity, string $detail): array
    {
        return compact('code', 'label', 'severity', 'detail');
    }"""
assert s.count(old) == 1
new = """    private function flag(string $code, string $label, string $severity, string $detail): array
    {
        return compact('code', 'label', 'severity', 'detail');
    }

    /**
     * MARKER-FLAG-TUNING \u2014 worst severity in a flag set, for the indexed
     * column. 'info' is deliberately ranked below 'warn' and is not a
     * queueing condition; a scope carrying only info findings is clean.
     */
    public static function worstSeverity(array $flags): ?string
    {
        $levels = array_column($flags, 'severity');
        foreach (['bad', 'warn', 'info'] as $level) {
            if (in_array($level, $levels, true)) {
                return $level;
            }
        }
        return null;
    }

    /** Does this flag set warrant human review? Info alone does not. */
    public static function needsReview(array $flags): bool
    {
        return in_array(self::worstSeverity($flags), ['warn', 'bad'], true);
    }"""
s = s.replace(old, new)

io.open(p, 'w', encoding='utf-8').write(s)
print('health service ok')
CTFT_1_EOF

# ------------------------------------------------------------------ model
python3 - <<'CTFT_2_EOF'
import io
p = 'app/Models/CatalogTitleScope.php'
s = io.open(p, encoding='utf-8').read()

old = """        'sample_title', 'reviewed', 'reviewed_at', 'scanned_at',"""
assert s.count(old) == 1
new = """        'sample_title', 'severity', 'reviewed', 'reviewed_at', 'scanned_at',"""
s = s.replace(old, new)

old = """    public function isHealthy(): bool
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
    }"""
assert s.count(old) == 1
new = """    /**
     * MARKER-FLAG-TUNING \u2014 'healthy' means nothing needs a human, so a
     * scope carrying only info findings counts as healthy even though its
     * flags array isn't empty.
     */
    public function isHealthy(): bool
    {
        return ! $this->needsReview();
    }

    public function needsReview(): bool
    {
        return in_array($this->severity, ['warn', 'bad'], true);
    }

    /** Only the findings worth showing as problems \u2014 info excluded. */
    public function problems(): array
    {
        return array_values(array_filter(
            $this->flags ?? [],
            fn ($f) => in_array($f['severity'] ?? null, ['warn', 'bad'], true)
        ));
    }

    /** Context findings for the editor: empty tokens and the like. */
    public function notes(): array
    {
        return array_values(array_filter(
            $this->flags ?? [],
            fn ($f) => ($f['severity'] ?? null) === 'info'
        ));
    }"""
s = s.replace(old, new)

io.open(p, 'w', encoding='utf-8').write(s)
print('model ok')
CTFT_2_EOF

# ------------------------------------------------------------------ command
python3 - <<'CTFT_3_EOF'
import io
p = 'app/Console/Commands/ScanCatalogTitleScopes.php'
s = io.open(p, encoding='utf-8').read()

old = """                    'flags'               => $flags,"""
assert s.count(old) == 1
new = """                    'flags'               => $flags,
                    // MARKER-FLAG-TUNING
                    'severity'            => CatalogTitleHealthService::worstSeverity($flags),"""
s = s.replace(old, new)

old = """        $flagged = CatalogTitleScope::query()
            ->when($code, fn ($q) => $q->where('distributor_code', $code))
            ->whereNotNull('flags')->where('flags', '!=', '[]')->count();

        $this->info("Scanned {$groups->count()} scopes \u00b7 {$flagged} flagged \u00b7 {$stale->count()} stale removed");"""
assert s.count(old) == 1, s.count(old)
new = """        // MARKER-FLAG-TUNING \u2014 only warn/bad counts as needing review.
        $base = fn () => CatalogTitleScope::query()
            ->when($code, fn ($q) => $q->where('distributor_code', $code));

        $flagged = $base()->whereIn('severity', ['warn', 'bad'])->count();
        $info    = $base()->where('severity', 'info')->count();
        $clean   = $base()->whereNull('severity')->count();

        $this->info("Scanned {$groups->count()} scopes \u00b7 {$flagged} need review \u00b7 {$info} info only \u00b7 {$clean} clean \u00b7 {$stale->count()} stale removed");"""
s = s.replace(old, new)

io.open(p, 'w', encoding='utf-8').write(s)
print('command ok')
CTFT_3_EOF

php -l app/Services/Distributors/CatalogTitleHealthService.php
php -l app/Models/CatalogTitleScope.php
php -l app/Console/Commands/ScanCatalogTitleScopes.php

echo
echo "catalog-title-flag-tuning applied."
