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
              on (
                   -- MARKER-BARCODE-TYPE: upc and ean label the SAME barcode.
                   -- One distributor files 4717784034485 as ean and another
                   -- as upc; joining on the label made identical products
                   -- invisible to each other. mpn must still meet mpn.
                   a.identifier_type = b.identifier_type
                   or (a.identifier_type in ('upc','ean')
                       and b.identifier_type in ('upc','ean'))
                 )
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
