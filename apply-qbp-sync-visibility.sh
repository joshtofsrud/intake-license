#!/usr/bin/env bash
# apply-qbp-sync-visibility.sh
#
# DIAGNOSTIC, not a fix. Nothing here changes what QBP returns — it changes
# whether you can see what it returned.
#
# Today QbpClient::products() fetches each brand in a try/catch and, on any
# failure, silently `continue`s. There are no Log:: calls in that file, and the
# sync service only records an error when a whole ten-brand chunk throws. So a
# run can fail on four hundred brands and still report "done" with no errors,
# leaving those brands stuck on 'pending' with no reason recorded.
#
# After this patch:
#   * every swallowed brand failure is logged AND returned to the caller
#   * the sync gives every brand a terminal status — 'failed' (fetch threw) or
#     'empty' (fetched fine, no products) instead of leaving it 'pending'
#   * `php artisan distributors:sync-catalog QBP` prints a status breakdown and
#     the first 25 errors rather than the first 5
#
# Guarded by MARKER-QBP-VISIBILITY. Idempotent. Exact string replacement.
set -euo pipefail
cd "$(dirname "$0")"

python3 - <<'PYEOF'
import sys

MARKER = "MARKER-QBP-VISIBILITY"
edits = {}

# ─────────────────────────────────────────────── 1. QbpClient: log + report
edits["app/Services/Distributors/QbpClient.php"] = [
(
"""use Illuminate\\Support\\Facades\\Http;
use RuntimeException;""",
"""use Illuminate\\Support\\Facades\\Http;
use Illuminate\\Support\\Facades\\Log;
use RuntimeException;"""
),
(
"""        $byModel = [];

        foreach ($ids as $brandId) {""",
"""        $byModel = [];
        // MARKER-QBP-VISIBILITY — a brand that fails is reported, not dropped.
        $failures = [];

        foreach ($ids as $brandId) {"""
),
(
"""            try {
                $doc = $this->fetch('1/product/brand/id/' . rawurlencode($brandId));
            } catch (\\Throwable $e) {
                // One bad brand must not abandon the page. A brand with no
                // products is normal; a 500 on one is not worth losing the
                // other twenty-four.
                continue;
            }""",
"""            try {
                $doc = $this->fetch('1/product/brand/id/' . rawurlencode($brandId));
            } catch (\\Throwable $e) {
                // MARKER-QBP-VISIBILITY — one bad brand still must not abandon
                // the page, but it no longer vanishes without trace. fetch()
                // already puts the HTTP status and QBP's own error text in the
                // message, so this is the whole reason.
                $failures[] = $brandId . ': ' . $e->getMessage();
                Log::warning('QBP brand fetch failed', [
                    'brand_id' => $brandId,
                    'error'    => $e->getMessage(),
                ]);
                continue;
            }"""
),
(
"""        return ['Products' => array_values($byModel)];""",
"""        // MARKER-QBP-VISIBILITY — Failures rides alongside Products so the
        // sync service can surface it. extractProducts() reads Products only.
        return ['Products' => array_values($byModel), 'Failures' => $failures];"""
),
]

# ──────────────────────────── 2. sync service: terminal status for every brand
edits["app/Services/Distributors/DistributorCatalogSyncService.php"] = [
(
"""            $products = $this->extractProducts($batch);
            unset($batch);
            $res['pages']++;""",
"""            // MARKER-QBP-VISIBILITY — per-brand failures the adapter swallowed.
            $chunkFailures = [];
            foreach ((array) ($batch['Failures'] ?? []) as $f) {
                $res['errors'][] = 'brand fetch ' . $f;
                $chunkFailures[strtok((string) $f, ':')] = true;
            }

            $products = $this->extractProducts($batch);
            unset($batch);
            $res['pages']++;"""
),
(
"""            unset($byBrand);
            gc_collect_cycles();""",
"""            // MARKER-QBP-VISIBILITY — every brand in this chunk ends in a
            // terminal state. Without this a brand that returned nothing stays
            // 'pending' forever and reads as "never reached", which is what
            // made a half-finished catalog look like a mapping bug.
            foreach ($chunk as $b) {
                $name = (string) ($b['name'] ?? '');
                if ($name === '' || array_key_exists($name, $byBrand)) {
                    continue;
                }
                $this->setBrandStatus(
                    $code,
                    $name,
                    isset($chunkFailures[(string) ($b['id'] ?? '')]) ? 'failed' : 'empty',
                    0
                );
            }

            unset($byBrand);
            gc_collect_cycles();"""
),
]

# ─────────────────────────────────────────── 3. command: show what happened
edits["app/Console/Commands/DistributorsSyncCatalogCommand.php"] = [
(
"""        if (! empty($res['errors'])) {
            $this->warn(count($res['errors']) . ' error(s) (first 5):');
            foreach (array_slice($res['errors'], 0, 5) as $e) {
                $this->line("  - {$e}");
            }
        }""",
"""        // MARKER-QBP-VISIBILITY — a per-brand breakdown, so a run that reports
        // "done" cannot hide four hundred brands that returned nothing.
        $byStatus = DB::table('distributor_brand_sync_status')
            ->where('distributor_code', $code)
            ->selectRaw('status, COUNT(*) AS n')
            ->groupBy('status')->orderBy('status')->get();

        if ($byStatus->isNotEmpty()) {
            $this->line('');
            $this->info('Brands by status:');
            foreach ($byStatus as $row) {
                $this->line("  {$row->status}: {$row->n}");
            }

            $stuck = DB::table('distributor_brand_sync_status')
                ->where('distributor_code', $code)
                ->whereIn('status', ['failed', 'empty', 'pending'])
                ->orderBy('status')->orderBy('brand_name')
                ->limit(15)->pluck('brand_name', 'status');

            if ($stuck->isNotEmpty()) {
                $this->line('  e.g. ' . $stuck->implode(', '));
            }
        }

        if (! empty($res['errors'])) {
            $this->line('');
            $this->warn(count($res['errors']) . ' error(s) (first 25):');
            foreach (array_slice($res['errors'], 0, 25) as $e) {
                $this->line("  - {$e}");
            }
        }"""
),
]

total = 0
for path, pairs in edits.items():
    src = open(path, encoding="utf-8").read()
    if MARKER in src:
        print(f"  skip (already patched)  {path}")
        continue
    for old, new in pairs:
        c = src.count(old)
        if c != 1:
            print(f"  !! anchor matched {c} times (expected 1) in {path}:")
            print(f"     {old.strip().splitlines()[0][:70]}")
            sys.exit(1)
        src = src.replace(old, new)
    open(path, "w", encoding="utf-8").write(src)
    print(f"  patched {len(pairs)} site(s)  {path}")
    total += len(pairs)

print(f"\n  {total} edits applied.")
PYEOF

echo
echo "  Then:  php artisan optimize"
echo "         php artisan distributors:sync-catalog QBP"
echo "         tail -n 200 storage/logs/laravel.log | grep 'QBP brand fetch failed'"
