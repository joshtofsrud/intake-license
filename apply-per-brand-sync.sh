#!/bin/bash
# apply-per-brand-sync.sh
#
# Adds an inline "Sync" button to each row of the master-admin per-brand
# progress list (Distributors page), so a single brand can be refreshed
# without a full-catalog run.
#
# Behavior by distributor (the honest part):
#   - QBP (pagesByBrand): fetches ONLY that brand from source — cheap, and the
#     point of this feature (a full QBP run pegs the shared box).
#   - HLC / BTI (no server-side brand filter): pulls the full feed and writes
#     only the target brand's rows. Correct result, but it still pays the full
#     ~25s fetch — use it to fix a stale/missing brand, not for load relief.
#
# Reuses existing machinery: DistributorRegistry (adapter), upsertVariant,
# setBrandStatus, extractProducts. One new job mirrors SyncDistributorCatalogJob.
#
# Files (all edits gated by MARKER-BRAND-SYNC; re-running is a no-op):
#   1. app/Jobs/SyncDistributorBrandJob.php                      (new)
#   2. app/Services/Distributors/DistributorCatalogSyncService.php (+syncBrand)
#   3. app/Filament/Pages/Distributors.php                       (+syncBrand method)
#   4. resources/views/filament/pages/distributors.blade.php     (+button cell)
#
# Run from the repo root on the Mac:  bash apply-per-brand-sync.sh

set -e

for p in app/Services/Distributors/DistributorCatalogSyncService.php \
         app/Filament/Pages/Distributors.php \
         resources/views/filament/pages/distributors.blade.php; do
  [ -f "$p" ] || { echo "ERROR: $p not found — run from the intake-license repo root." >&2; exit 1; }
done

# ---------------------------------------------------------------------------
# 1. The job (new file)
# ---------------------------------------------------------------------------
JOB="app/Jobs/SyncDistributorBrandJob.php"
if [ -f "$JOB" ]; then
  echo "SKIP $JOB already exists"
else
cat > "$JOB" <<'PHPEOF'
<?php
// MARKER-BRAND-SYNC

namespace App\Jobs;

use App\Models\PlatformDistributorConnection;
use App\Services\Distributors\DistributorCatalogSyncService;
use App\Services\Distributors\DistributorRegistry;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Refresh a SINGLE brand for one distributor. Efficient for pagesByBrand
 * adapters (QBP fetches only that brand); for others it pulls the full feed
 * and writes just the target brand. Mirrors SyncDistributorCatalogJob.
 */
class SyncDistributorBrandJob implements ShouldQueue, ShouldBeUnique
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 1800;
    public int $tries = 1;

    public function retryUntil(): \DateTimeInterface
    {
        return now()->addMinutes(20);
    }

    /** One refresh per (distributor, brand) at a time. */
    public int $uniqueFor = 1800;

    public function uniqueId(): string
    {
        return 'distributor-brand-sync-' . strtoupper($this->distributorCode) . '-' . $this->brandName;
    }

    public function __construct(
        public string $distributorCode,
        public string $brandName,
    ) {}

    public function handle(DistributorRegistry $registry, DistributorCatalogSyncService $sync): void
    {
        $code = strtoupper($this->distributorCode);
        $conn = PlatformDistributorConnection::forCode($code);

        if (! $conn->api_key) {
            Log::warning("SyncDistributorBrandJob: no platform API key for {$code}.");
            return;
        }

        $adapter = $registry->make($code, ['api_key' => $conn->api_key, 'region' => $conn->region ?? 'us']);
        if ($conn->auth_style && method_exists($adapter, 'setAuthStyle')) {
            $adapter->setAuthStyle($conn->auth_style);
        }

        $res = $sync->syncBrand($adapter, $this->brandName);
        Log::info("SyncDistributorBrandJob {$code}/{$this->brandName}: wrote {$res['written']} of {$res['seen']} variants.");
    }
}
PHPEOF
  echo "OK   created $JOB"
fi

python3 - <<'PYEOF'
import sys

def load(p): return open(p).read()
def save(p, s): open(p, "w").write(s)
def repl(src, old, new, label):
    n = src.count(old)
    if n != 1:
        print(f"ERROR [{label}]: expected exactly 1 match, found {n}.", file=sys.stderr); sys.exit(1)
    return src.replace(old, new)

MARK = "MARKER-BRAND-SYNC"

# -------------------------------------------------------------------------
# 2. DistributorCatalogSyncService::syncBrand
# -------------------------------------------------------------------------
p = "app/Services/Distributors/DistributorCatalogSyncService.php"
s = load(p)
if MARK in s:
    print(f"SKIP {p} (already patched)")
else:
    anchor = "    private function syncIdentityByBrand(DistributorAdapter $adapter, ?Carbon $since, array $res): array"
    method = (
        "    // MARKER-BRAND-SYNC — refresh one brand. pagesByBrand adapters (QBP)\n"
        "    // fetch only that brand; others pull the full feed and keep just this one.\n"
        "    public function syncBrand(DistributorAdapter $adapter, string $brandName): array\n"
        "    {\n"
        "        $code = strtoupper($adapter->code());\n"
        "\n"
        "        if (empty($this->resolver->mapsFor($code))) {\n"
        "            throw new \\RuntimeException(\"No field map for {$code}. Seed DistributorFieldMapSeeder before syncing.\");\n"
        "        }\n"
        "\n"
        "        $res = [\n"
        "            'code' => $code, 'pages' => 0, 'seen' => 0, 'written' => 0,\n"
        "            'skipped_delta' => 0, 'map_vanished' => 0, 'msrp_vanished' => 0, 'errors' => [],\n"
        "        ];\n"
        "\n"
        "        DB::connection()->disableQueryLog();\n"
        "        $this->setBrandStatus($code, $brandName, 'syncing', null);\n"
        "\n"
        "        try {\n"
        "            if (method_exists($adapter, 'pagesByBrand') && $adapter->pagesByBrand()) {\n"
        "                $id = null;\n"
        "                foreach ($adapter->brands() as $b) {\n"
        "                    if (strcasecmp((string) ($b['name'] ?? ''), $brandName) === 0) { $id = $b['id'] ?? null; break; }\n"
        "                }\n"
        "                if ($id === null) {\n"
        "                    $res['errors'][] = \"brand '{$brandName}' not found in {$code} brand list\";\n"
        "                    $this->setBrandStatus($code, $brandName, 'done', 0);\n"
        "                    return $res;\n"
        "                }\n"
        "                $products = $this->extractProducts($adapter->products(['brands' => [$id]]));\n"
        "            } else {\n"
        "                $all = $this->extractProducts($adapter->products());\n"
        "                $products = array_values(array_filter($all, function ($prod) use ($brandName) {\n"
        "                    return strcasecmp((string) ($prod['Brand'] ?? ''), $brandName) === 0;\n"
        "                }));\n"
        "                unset($all);\n"
        "            }\n"
        "\n"
        "            $res['pages'] = 1;\n"
        "            $written = 0;\n"
        "            foreach ($products as $product) {\n"
        "                foreach (($product['Variants'] ?? []) as $variant) {\n"
        "                    $res['seen']++;\n"
        "                    try {\n"
        "                        $this->upsertVariant($code, $adapter->name(), $variant, $product, $res);\n"
        "                        $res['written']++;\n"
        "                        $written++;\n"
        "                    } catch (\\Throwable $e) {\n"
        "                        $res['errors'][] = ($variant['sku'] ?? $variant['VariantNo'] ?? '?') . ': ' . $e->getMessage();\n"
        "                    }\n"
        "                    if ($written % 100 === 0) {\n"
        "                        $this->setBrandStatus($code, $brandName, 'syncing', $written);\n"
        "                    }\n"
        "                }\n"
        "            }\n"
        "            unset($products);\n"
        "\n"
        "            $this->setBrandStatus($code, $brandName, 'done', $written);\n"
        "        } catch (\\Throwable $e) {\n"
        "            $res['errors'][] = $e->getMessage();\n"
        "            $this->setBrandStatus($code, $brandName, 'done', $res['written']);\n"
        "        }\n"
        "\n"
        "        return $res;\n"
        "    }\n"
        "\n"
        + anchor
    )
    s = repl(s, anchor, method, "service.syncBrand")
    save(p, s)
    print(f"OK   {p}")

# -------------------------------------------------------------------------
# 3. Distributors page: public syncBrand() method
# -------------------------------------------------------------------------
p = "app/Filament/Pages/Distributors.php"
s = load(p)
if MARK in s:
    print(f"SKIP {p} (already patched)")
else:
    anchor = "    protected function dispatchSync(bool $delta): void\n    {"
    method = (
        "    // MARKER-BRAND-SYNC — queue a single-brand refresh from the brand list.\n"
        "    public function syncBrand(string $brand): void\n"
        "    {\n"
        "        $code = $this->currentCode();\n"
        "        \\App\\Jobs\\SyncDistributorBrandJob::dispatch($code, $brand);\n"
        "\n"
        "        DB::table('distributor_brand_sync_status')\n"
        "            ->where('distributor_code', $code)\n"
        "            ->where('brand_name', $brand)\n"
        "            ->update(['status' => 'syncing', 'updated_at' => now()]);\n"
        "\n"
        "        \\Filament\\Notifications\\Notification::make()\n"
        "            ->title($brand . ' sync queued')\n"
        "            ->success()->send();\n"
        "    }\n"
        "\n"
        + anchor
    )
    s = repl(s, anchor, method, "page.syncBrand")
    save(p, s)
    print(f"OK   {p}")

# -------------------------------------------------------------------------
# 4. Brand table: inline Sync button
# -------------------------------------------------------------------------
p = "resources/views/filament/pages/distributors.blade.php"
s = load(p)
if MARK in s:
    print(f"SKIP {p} (already patched)")
else:
    anchor = (
        "                                    @else\n"
        "                                        <span style=\"opacity:.5\">pending</span>\n"
        "                                    @endif\n"
        "                                </td>\n"
        "                            </tr>"
    )
    new = (
        "                                    @else\n"
        "                                        <span style=\"opacity:.5\">pending</span>\n"
        "                                    @endif\n"
        "                                </td>\n"
        "                                {{-- MARKER-BRAND-SYNC — inline single-brand refresh --}}\n"
        "                                <td style=\"padding:7px 12px;text-align:right\">\n"
        "                                    <button type=\"button\" wire:click=\"syncBrand(@js($b->brand_name))\" wire:loading.attr=\"disabled\"\n"
        "                                            style=\"font-size:11px;padding:3px 10px;border:.5px solid rgba(255,255,255,.2);border-radius:6px;background:transparent;color:#BEF264;cursor:pointer\">Sync</button>\n"
        "                                </td>\n"
        "                            </tr>"
    )
    s = repl(s, anchor, new, "view.button")
    save(p, s)
    print(f"OK   {p}")

print("\nAll edits applied.")
PYEOF

echo ""
echo "Syntax-checking PHP:"
for f in app/Jobs/SyncDistributorBrandJob.php app/Services/Distributors/DistributorCatalogSyncService.php app/Filament/Pages/Distributors.php; do
  if php -l "$f" >/dev/null 2>&1; then echo "OK   php -l  $f"; else echo "ERROR php -l  $f"; php -l "$f"; exit 1; fi
done

echo ""
echo "SUCCESS. Deploy, then: php artisan optimize:clear  (view cache for the new button)."
echo "Test: click Sync on one brand — QBP fetches just that brand; watch its row flip to syncing then done."
