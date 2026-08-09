#!/usr/bin/env bash
set -euo pipefail
# apply-qbp-brand-totals.sh — MARKER-BRAND-TOTALS
# Per-brand progress tells the truth:
#   - by-brand (QBP) path writes `total` the moment a brand's products are fetched
#   - single-brand Sync button writes `total` too, so one click heals a 0-total row
#   - delta runs track skipped-unchanged per brand (new additive `skipped` column)
#   - a brand where delta wrote nothing but skipped >0 ends as 'fresh' ("up to date"),
#     not done-with-zero
#   - panel renders fresh / failed / empty states and "· N unchanged"

SVC=app/Services/Distributors/DistributorCatalogSyncService.php
PANEL=resources/views/filament/pages/distributors.blade.php
MIG=database/migrations/2026_08_09_100000_add_skipped_to_distributor_brand_sync_status.php

for f in "$SVC" "$PANEL"; do
  [ -f "$f" ] || { echo "MISSING $f — run from the repo root"; exit 1; }
done

if grep -q "MARKER-BRAND-TOTALS" "$SVC"; then
  echo "Already applied (MARKER-BRAND-TOTALS present) — no-op."
  exit 0
fi

# ---------------------------------------------------------------- migration
if [ -f "$MIG" ]; then
  echo "ok   migration already present"
else
  cat <<'EOF' > "$MIG"
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// MARKER-BRAND-TOTALS — additive only (expand/contract rule): delta runs need
// somewhere truthful to put "seen but unchanged".
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('distributor_brand_sync_status', function (Blueprint $table) {
            $table->unsignedInteger('skipped')->default(0)->after('written');
        });
    }

    public function down(): void
    {
        Schema::table('distributor_brand_sync_status', function (Blueprint $table) {
            $table->dropColumn('skipped');
        });
    }
};
EOF
  echo "ok   migration created"
fi

# ---------------------------------------------------------------- service
python3 - "$SVC" <<'PY'
import sys
path = sys.argv[1]
src = open(path).read()

def edit(old, new, label):
    global src
    n = src.count(old)
    if n != 1:
        print(f"FAIL {label}: anchor found {n} times"); sys.exit(1)
    src = src.replace(old, new, 1)
    print(f"ok   {label}")

# 1) setBrandStatus learns total + skipped
edit("""    private function setBrandStatus(string $code, string $brandName, string $status, ?int $written): void
    {
        $vals = ['status' => $status, 'updated_at' => now()];
        if ($written !== null) {
            $vals['written'] = $written;
        }
        DB::table('distributor_brand_sync_status')""",
"""    // MARKER-BRAND-TOTALS — total/skipped write only when known, so the
    // panel's denominator is real on the by-brand path and delta runs can
    // say "unchanged" instead of looking like a write failure.
    private function setBrandStatus(string $code, string $brandName, string $status, ?int $written, ?int $total = null, ?int $skipped = null): void
    {
        $vals = ['status' => $status, 'updated_at' => now()];
        if ($written !== null) {
            $vals['written'] = $written;
        }
        if ($total !== null) {
            $vals['total'] = $total;
        }
        if ($skipped !== null) {
            $vals['skipped'] = $skipped;
        }
        DB::table('distributor_brand_sync_status')""",
"setBrandStatus signature")

# 2) seed rows carry skipped = 0
edit("""                'total' => $total, 'written' => 0, 'status' => 'pending',""",
"""                'total' => $total, 'written' => 0, 'skipped' => 0, 'status' => 'pending',""",
"seed skipped column")

# 3) full-catalog loop (HLC/BTI) — track skipped, 'fresh' terminal state.
#    Totals here are already real from seedBrandStatuses.
edit("""        foreach ($byBrand as $brandName => $brandProducts) {
            $this->setBrandStatus($code, $brandName, 'syncing', null);
            $brandWritten = 0;
""",
"""        foreach ($byBrand as $brandName => $brandProducts) {
            $this->setBrandStatus($code, $brandName, 'syncing', null);
            $brandWritten = 0;
            $brandSkipped = 0; // MARKER-BRAND-TOTALS
""",
"full path: skip counter")

edit("""                    if ($since !== null && $this->isUnchanged($variant, $product, $since)) {
                        $res['skipped_delta']++;
                        continue;
                    }
""",
"""                    if ($since !== null && $this->isUnchanged($variant, $product, $since)) {
                        $res['skipped_delta']++;
                        $brandSkipped++; // MARKER-BRAND-TOTALS
                        continue;
                    }
""",
"full path: count skips")

edit("""                    if ($brandWritten % 100 === 0) {
                        $this->setBrandStatus($code, $brandName, 'syncing', $brandWritten);
                        $this->markProgress($code, $res['written']);
                    }
""",
"""                    if ($brandWritten % 100 === 0) {
                        $this->setBrandStatus($code, $brandName, 'syncing', $brandWritten, null, $brandSkipped);
                        $this->markProgress($code, $res['written']);
                    }
""",
"full path: periodic skipped")

edit("""            $this->setBrandStatus($code, $brandName, 'done', $brandWritten);
            $this->markProgress($code, $res['written']);
            unset($brandProducts);
""",
"""            // MARKER-BRAND-TOTALS — 'fresh' = delta looked and nothing changed.
            $this->setBrandStatus(
                $code,
                $brandName,
                ($brandWritten === 0 && $brandSkipped > 0) ? 'fresh' : 'done',
                $brandWritten,
                null,
                $brandSkipped
            );
            $this->markProgress($code, $res['written']);
            unset($brandProducts);
""",
"full path: fresh terminal")

# 4) by-brand chunk loop (QBP) — write total when the brand's products arrive
edit("""            foreach ($byBrand as $brandName => $brandProducts) {
                $this->setBrandStatus($code, $brandName, 'syncing', null);
                $brandWritten = 0;
""",
"""            foreach ($byBrand as $brandName => $brandProducts) {
                // MARKER-BRAND-TOTALS — products for this brand are finally in
                // hand, so the denominator can be written. Seeding left it 0.
                $brandTotal = 0;
                foreach ($brandProducts as $p) {
                    $brandTotal += count($p['Variants'] ?? []);
                }
                $this->setBrandStatus($code, $brandName, 'syncing', null, $brandTotal);
                $brandWritten = 0;
                $brandSkipped = 0;
""",
"chunk path: total at fetch")

edit("""                        if ($since !== null && $this->isUnchanged($variant, $product, $since)) {
                            $res['skipped_delta']++;
                            continue;
                        }
""",
"""                        if ($since !== null && $this->isUnchanged($variant, $product, $since)) {
                            $res['skipped_delta']++;
                            $brandSkipped++; // MARKER-BRAND-TOTALS
                            continue;
                        }
""",
"chunk path: count skips")

edit("""                        if ($brandWritten % 100 === 0) {
                            $this->setBrandStatus($code, $brandName, 'syncing', $brandWritten);
                            $this->markProgress($code, $res['written']);
                        }
""",
"""                        if ($brandWritten % 100 === 0) {
                            $this->setBrandStatus($code, $brandName, 'syncing', $brandWritten, null, $brandSkipped);
                            $this->markProgress($code, $res['written']);
                        }
""",
"chunk path: periodic skipped")

edit("""                $this->setBrandStatus($code, $brandName, 'done', $brandWritten);
                $this->markProgress($code, $res['written']);
            }
""",
"""                // MARKER-BRAND-TOTALS — 'fresh' = delta looked and nothing changed.
                $this->setBrandStatus(
                    $code,
                    $brandName,
                    ($brandWritten === 0 && $brandSkipped > 0) ? 'fresh' : 'done',
                    $brandWritten,
                    null,
                    $brandSkipped
                );
                $this->markProgress($code, $res['written']);
            }
""",
"chunk path: fresh terminal")

# 5) single-brand Sync — write the real total once products are fetched
edit("""            $res['pages'] = 1;
            $written = 0;""",
"""            $res['pages'] = 1;

            // MARKER-BRAND-TOTALS — single-brand refresh writes the real
            // denominator too, so one Sync click heals a 0-total row.
            $brandTotal = 0;
            foreach ($products as $p) {
                $brandTotal += count($p['Variants'] ?? []);
            }
            $this->setBrandStatus($code, $brandName, 'syncing', 0, $brandTotal);

            $written = 0;""",
"syncBrand: total at fetch")

open(path, 'w').write(src)
PY

# ---------------------------------------------------------------- panel
python3 - "$PANEL" <<'PY'
import sys
path = sys.argv[1]
src = open(path).read()

old = """                            @php $done = $b->status === 'done'; $sync = $b->status === 'syncing'; @endphp
                            <tr style="border-bottom:.5px solid rgba(255,255,255,.07)">
                                <td style="padding:7px 12px;font-weight:600">{{ $b->brand_name }}</td>
                                <td style="padding:7px 12px;font-family:ui-monospace,monospace;opacity:.85">{{ number_format($b->written) }} / {{ number_format($b->total) }}</td>
                                <td style="padding:7px 12px;text-align:right">
                                    @if($done)
                                        <span style="color:#BEF264">✓ done</span>
                                    @elseif($sync)
                                        <span style="color:#BEF264">● syncing</span>
                                    @else
                                        <span style="opacity:.5">pending</span>
                                    @endif
                                </td>"""
new = """                            @php /* MARKER-BRAND-TOTALS — skipped-unchanged is part of the truth */ $done = $b->status === 'done'; $sync = $b->status === 'syncing'; $bSkipped = (int) ($b->skipped ?? 0); @endphp
                            <tr style="border-bottom:.5px solid rgba(255,255,255,.07)">
                                <td style="padding:7px 12px;font-weight:600">{{ $b->brand_name }}</td>
                                <td style="padding:7px 12px;font-family:ui-monospace,monospace;opacity:.85">{{ number_format($b->written) }} / {{ number_format($b->total) }} @if($bSkipped > 0)<span style="opacity:.55">&middot; {{ number_format($bSkipped) }} unchanged</span> @endif</td>
                                <td style="padding:7px 12px;text-align:right">
                                    @if($done)
                                        <span style="color:#BEF264">✓ done</span>
                                    @elseif($sync)
                                        <span style="color:#BEF264">● syncing</span>
                                    @elseif($b->status === 'fresh')
                                        <span style="color:#BEF264;opacity:.8">✓ up to date</span>
                                    @elseif($b->status === 'failed')
                                        <span style="color:#E24B4A">✕ failed</span>
                                    @elseif($b->status === 'empty')
                                        <span style="opacity:.4">&mdash; empty</span>
                                    @else
                                        <span style="opacity:.5">pending</span>
                                    @endif
                                </td>"""
n = src.count(old)
if n != 1:
    print(f"FAIL panel row: anchor found {n} times"); sys.exit(1)
src = src.replace(old, new, 1)
print("ok   panel row (totals, unchanged, fresh/failed/empty labels)")

open(path, 'w').write(src)
PY

php -l "$SVC"

echo ""
echo "SUCCESS — apply-qbp-brand-totals applied."
echo "Deploy runs the migration automatically. Existing QBP rows stay 0-total"
echo "until the next full run or a per-brand Sync click (either heals them)."
