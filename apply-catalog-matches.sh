#!/bin/bash
# catalog-matches — the matching pass, built to measured tiers.
#
#   Every threshold here came from HLC x BTI, not from judgement:
#     4,592 candidate pairs · 4,535 share an exact barcode
#     of those, 4,265 have MSRP within 15%, ~105 sit 15-40% apart,
#     10 exceed 40%, 155 have no MSRP on one side
#     57 pairs match on MPN alone · 1 row matches two rows
#
#   Barcode is the identity test. A UPC is globally unique per product, so an
#   exact agreement is strong on its own. MPN is NOT used to confirm it —
#   measured against real data that only manufactures false conflicts:
#   SRAM vs AVID for the same SRAM-owned part, BTI's CB prefix on Chamois
#   Butt'r (CBGS25 vs GS25), Clif's entirely different numbering scheme
#   (CCC56801 vs 7222525680100). All the same product.
#
#   The risk a barcode genuinely cannot catch is a 1-pack and a 2-pack
#   sharing a code. MPN never detected that. MSRP does, and unlike cost it
#   exists on the shared catalog (cost_cents is nulled there on purpose —
#   it is per-tenant).
#
#   So:
#     barcode + MSRP within 15%, or MSRP missing  -> auto
#     barcode + MSRP 15-40% apart                 -> held  (pack size?)
#     barcode + MSRP over 40% apart               -> held  (likely pack size)
#     MPN only                                    -> held  (proposal)
#     row already linked to a different row       -> held  (ambiguous)
#
#   Nothing merges catalog rows. A pair is a LINK with a status, so a wrong
#   call is one row update to undo, and a confirmed pair survives re-runs —
#   the pass never overwrites a human decision.
#
#   Pairs are stored with the lower id first so a pair is one row, not two.
# MIGRATION REQUIRED. After deploy: php artisan catalog:match --dry-run
set -e
if [ -f app/Models/CatalogMatch.php ]; then
  echo "catalog-matches already applied — aborting."; exit 1
fi

# ------------------------------------------------------------------ migration
cat > 'database/migrations/2026_07_31_000002_create_catalog_matches.php' <<'CM_0_EOF'
<?php

// MARKER-CATALOG-MATCHES — a LINK between two distributor catalog rows for
// the same physical product. Rows are never merged; matching is reversible.

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('catalog_matches', function (Blueprint $t) {
            $t->id();

            // Ordered: lower uuid first, so one pair is one row.
            $t->uuid('row_a_id');
            $t->uuid('row_b_id');
            $t->string('code_a', 32);
            $t->string('code_b', 32);

            // auto | held | confirmed | rejected
            // confirmed/rejected are human decisions and are never
            // overwritten by a later run.
            $t->string('status', 12)->default('held');

            // barcode | mpn
            $t->string('matched_on', 12);

            // Which identifier values agreed — so review can see the evidence
            // without recomputing it.
            $t->json('evidence')->nullable();

            // Percentage gap between the two MSRPs, null when either is
            // missing. The pack-size signal.
            $t->unsignedSmallInteger('msrp_spread_pct')->nullable();

            // Why it was held, for the queue's grouping.
            $t->string('hold_reason', 32)->nullable();

            $t->timestamp('decided_at')->nullable();
            $t->timestamps();

            $t->unique(['row_a_id', 'row_b_id'], 'cmatch_pair_unique');
            $t->index(['status', 'hold_reason'], 'cmatch_status_idx');
            $t->index('row_a_id', 'cmatch_a_idx');
            $t->index('row_b_id', 'cmatch_b_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('catalog_matches');
    }
};
CM_0_EOF

# ------------------------------------------------------------------ model
cat > 'app/Models/CatalogMatch.php' <<'CM_1_EOF'
<?php

// MARKER-CATALOG-MATCHES

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CatalogMatch extends Model
{
    protected $fillable = [
        'row_a_id', 'row_b_id', 'code_a', 'code_b',
        'status', 'matched_on', 'evidence',
        'msrp_spread_pct', 'hold_reason', 'decided_at',
    ];

    protected $casts = [
        'evidence'   => 'array',
        'decided_at' => 'datetime',
    ];

    /** A human said yes or no; the matcher must not touch these again. */
    public function isDecided(): bool
    {
        return in_array($this->status, ['confirmed', 'rejected'], true);
    }

    public function rowA() { return $this->belongsTo(PlatformDistributorCatalog::class, 'row_a_id'); }
    public function rowB() { return $this->belongsTo(PlatformDistributorCatalog::class, 'row_b_id'); }
}
CM_1_EOF

# ------------------------------------------------------------------ command
cat > 'app/Console/Commands/MatchCatalogRows.php' <<'CM_2_EOF'
<?php

// MARKER-CATALOG-MATCHES

namespace App\Console\Commands;

use App\Models\CatalogMatch;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Pairs distributor catalog rows that are the same physical product.
 *
 * Thresholds are measured, not chosen. Across HLC x BTI: 4,535 of 4,592
 * candidate pairs share an exact barcode, and of those 4,265 have MSRP
 * within 15% while only 10 exceed 40%.
 *
 * Barcode is identity. MPN is deliberately NOT used to confirm a barcode
 * match — tested against real rows it only produces false conflicts, since
 * distributors file the same part under different brands (SRAM/Avid) and
 * different numbering schemes (Clif's CCC56801 vs 7222525680100).
 */
class MatchCatalogRows extends Command
{
    protected $signature = 'catalog:match
        {--dry-run : report what would happen, write nothing}
        {--rematch : also reconsider rows already auto-linked}';

    protected $description = 'Link catalog rows across distributors';

    /** MSRP gap below which a barcode match is trusted outright. */
    private const MSRP_OK_PCT = 15;

    /** Above this, a shared barcode most likely means differing pack sizes. */
    private const MSRP_SUSPECT_PCT = 40;

    public function handle(): int
    {
        $dry = (bool) $this->option('dry-run');

        $this->info('Finding candidate pairs…');
        $pairs = $this->candidates();
        $this->info(count($pairs) . ' candidate pairs');

        $stats = ['auto' => 0, 'held' => 0, 'skipped_decided' => 0, 'unchanged' => 0];
        $holds = [];

        // A row already linked elsewhere is ambiguous — the second pair is
        // held rather than silently creating a three-way merge.
        $claimed = [];
        foreach (CatalogMatch::whereIn('status', ['auto', 'confirmed'])->get() as $m) {
            $claimed[$m->row_a_id] = true;
            $claimed[$m->row_b_id] = true;
        }

        $bar = $this->output->createProgressBar(count($pairs));

        foreach ($pairs as $p) {
            $existing = CatalogMatch::where('row_a_id', $p['a'])
                ->where('row_b_id', $p['b'])->first();

            if ($existing && $existing->isDecided()) {
                $stats['skipped_decided']++;      // never overwrite a person
                $bar->advance();
                continue;
            }

            [$status, $reason] = $this->judge($p, $claimed, $existing);

            if ($status === 'auto') {
                $stats['auto']++;
                $claimed[$p['a']] = true;
                $claimed[$p['b']] = true;
            } else {
                $stats['held']++;
                $holds[$reason] = ($holds[$reason] ?? 0) + 1;
            }

            if (! $dry) {
                CatalogMatch::updateOrCreate(
                    ['row_a_id' => $p['a'], 'row_b_id' => $p['b']],
                    [
                        'code_a'          => $p['code_a'],
                        'code_b'          => $p['code_b'],
                        'status'          => $status,
                        'matched_on'      => $p['matched_on'],
                        'evidence'        => ['values' => $p['values']],
                        'msrp_spread_pct' => $p['spread'],
                        'hold_reason'     => $status === 'held' ? $reason : null,
                    ]
                );
            }

            $bar->advance();
        }

        $bar->finish();
        $this->newLine(2);

        $this->line('  auto-linked      : ' . $stats['auto']);
        $this->line('  held for review  : ' . $stats['held']);
        foreach ($holds as $r => $n) {
            $this->line('      ' . str_pad($r, 22) . $n);
        }
        $this->line('  left alone (decided by a person): ' . $stats['skipped_decided']);

        if ($dry) {
            $this->newLine();
            $this->warn('Dry run — nothing written.');
        }

        return self::SUCCESS;
    }

    /**
     * Decide a pair. Barcode carries identity; MSRP is the only sanity check
     * applied on top, because it is the one that detects a pack-size
     * mismatch, which is the failure a shared barcode can actually hide.
     *
     * @return array{0:string,1:?string}
     */
    private function judge(array $p, array $claimed, ?CatalogMatch $existing): array
    {
        // Ambiguity first: if either row is already spoken for by a
        // different pair, a person decides which is right.
        $aTaken = isset($claimed[$p['a']]) && ! ($existing && $existing->status === 'auto');
        $bTaken = isset($claimed[$p['b']]) && ! ($existing && $existing->status === 'auto');
        if ($aTaken || $bTaken) {
            return ['held', 'ambiguous'];
        }

        if ($p['matched_on'] === 'mpn') {
            // No barcode agreed. Same part number under the same brand is
            // good evidence but not proof, so it stays a proposal.
            return ['held', 'mpn_only'];
        }

        if ($p['spread'] === null) {
            // One side has no MSRP. The barcode is still an exact match and
            // a missing price is not evidence against it.
            return ['auto', null];
        }
        if ($p['spread'] <= self::MSRP_OK_PCT) {
            return ['auto', null];
        }
        if ($p['spread'] <= self::MSRP_SUSPECT_PCT) {
            return ['held', 'msrp_gap'];
        }

        return ['held', 'msrp_far'];
    }

    /**
     * Candidate pairs, barcode matches first so a pair that agrees on both a
     * barcode and an MPN is recorded as a barcode match.
     *
     * @return array<int,array<string,mixed>>
     */
    private function candidates(): array
    {
        $sql = "
            select
                least(a.distributor_catalog_id, b.distributor_catalog_id)    as a_id,
                greatest(a.distributor_catalog_id, b.distributor_catalog_id) as b_id,
                min(case when a.distributor_catalog_id < b.distributor_catalog_id
                         then a.distributor_code else b.distributor_code end) as code_a,
                min(case when a.distributor_catalog_id < b.distributor_catalog_id
                         then b.distributor_code else a.distributor_code end) as code_b,
                max(a.identifier_type in ('upc','ean')) as has_barcode,
                group_concat(distinct a.value_norm order by a.value_norm separator ' ') as vals,
                ca.msrp_cents as msrp_a,
                cb.msrp_cents as msrp_b
            from catalog_identifiers a
            join catalog_identifiers b
              on a.identifier_type = b.identifier_type
             and a.value_norm      = b.value_norm
             and a.distributor_code < b.distributor_code
            join platform_distributor_catalogs ca
              on ca.id = least(a.distributor_catalog_id, b.distributor_catalog_id)
            join platform_distributor_catalogs cb
              on cb.id = greatest(a.distributor_catalog_id, b.distributor_catalog_id)
            where ca.is_active = 1 and cb.is_active = 1
            group by a_id, b_id, msrp_a, msrp_b
        ";

        $out = [];
        foreach (DB::select($sql) as $r) {
            $spread = null;
            if ($r->msrp_a !== null && $r->msrp_b !== null
                && ((int) $r->msrp_a) > 0 && ((int) $r->msrp_b) > 0) {
                $hi = max((int) $r->msrp_a, (int) $r->msrp_b);
                $spread = (int) round(abs((int) $r->msrp_a - (int) $r->msrp_b) / $hi * 100);
            }

            $out[] = [
                'a'          => $r->a_id,
                'b'          => $r->b_id,
                'code_a'     => $r->code_a,
                'code_b'     => $r->code_b,
                'matched_on' => ((int) $r->has_barcode) === 1 ? 'barcode' : 'mpn',
                'values'     => array_slice(explode(' ', (string) $r->vals), 0, 6),
                'spread'     => $spread,
            ];
        }

        // Barcode pairs first: they claim rows before MPN-only pairs get to.
        usort($out, fn ($x, $y) => ($y['matched_on'] === 'barcode') <=> ($x['matched_on'] === 'barcode'));

        return $out;
    }
}
CM_2_EOF

php -l app/Models/CatalogMatch.php
php -l app/Console/Commands/MatchCatalogRows.php
php -l database/migrations/2026_07_31_000002_create_catalog_matches.php

echo
echo "catalog-matches applied."
