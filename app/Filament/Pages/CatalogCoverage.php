<?php

// MARKER-CATALOG-COVERAGE

namespace App\Filament\Pages;

use Filament\Pages\Page;
use Illuminate\Support\Facades\DB;

/**
 * Side-by-side coverage between two distributors, grouped by brand.
 *
 * "Matched" means a catalog_matches row with status auto or confirmed. Held
 * and rejected pairs are counted as unmatched, which is the honest reading —
 * nobody has agreed those are the same product.
 */
class CatalogCoverage extends Page
{
    use \App\Support\GatedByAdminArea; // MARKER-ADMIN-NAV-GATE
    protected static string $adminArea = 'catalog';

    protected static ?string $navigationIcon  = 'heroicon-o-scale';
    protected static ?string $navigationLabel = 'Catalog coverage';
    protected static ?string $navigationGroup = 'Distribution';
    protected static ?int    $navigationSort  = 60;
    protected static ?string $title = 'Catalog coverage';

    protected static string $view = 'filament.pages.catalog-coverage';

    public string $codeA = '';
    public string $codeB = '';
    public string $sort  = 'gap';     // gap | overlap | size | name
    public string $search = '';
    public ?string $brand = null;     // drill-down

    public function mount(): void
    {
        $codes = $this->distributorCodes();
        $this->codeA = $codes[0] ?? '';
        $this->codeB = $codes[1] ?? ($codes[0] ?? '');
    }

    /** Distributor codes that actually have catalog rows. */
    public function distributorCodes(): array
    {
        return DB::table('platform_distributor_catalogs')
            ->where('is_active', true)
            ->select('distributor_code')->distinct()
            ->orderBy('distributor_code')
            ->pluck('distributor_code')->all();
    }

    public function updatedCodeA(): void { $this->brand = null; }
    public function updatedCodeB(): void { $this->brand = null; }

    /**
     * Per-brand counts for both sides plus how many of each side's rows are
     * matched to the other.
     *
     * Grouped by each side's OWN brand string and unioned, because brands do
     * not agree across distributors — BTI files Avid under SRAM. Attributing
     * a match to both sides' brands is what makes a lopsided row readable as
     * a naming difference rather than a coverage hole.
     */
    public function getRowsProperty(): array
    {
        if ($this->codeA === '' || $this->codeB === '' || $this->codeA === $this->codeB) {
            return [];
        }

        $sql = "
            select
                brand,
                sum(in_a)      as a_rows,
                sum(in_b)      as b_rows,
                sum(matched_a) as a_matched,
                sum(matched_b) as b_matched
            from (
                select
                    coalesce(nullif(trim(c.manufacturer), ''), '(no brand)') as brand,
                    case when c.distributor_code = ? then 1 else 0 end as in_a,
                    case when c.distributor_code = ? then 1 else 0 end as in_b,
                    case when c.distributor_code = ? and m.n > 0 then 1 else 0 end as matched_a,
                    case when c.distributor_code = ? and m.n > 0 then 1 else 0 end as matched_b
                from platform_distributor_catalogs c
                left join (
                    select row_a_id as id, count(*) n from catalog_matches
                     where status in ('auto','confirmed') group by row_a_id
                    union all
                    select row_b_id as id, count(*) n from catalog_matches
                     where status in ('auto','confirmed') group by row_b_id
                ) m on m.id = c.id
                where c.is_active = 1
                  and c.distributor_code in (?, ?)
            ) t
            group by brand
        ";

        $rows = DB::select($sql, [
            $this->codeA, $this->codeB, $this->codeA, $this->codeB,
            $this->codeA, $this->codeB,
        ]);

        $out = [];
        foreach ($rows as $r) {
            $a = (int) $r->a_rows;
            $b = (int) $r->b_rows;
            $am = (int) $r->a_matched;
            $bm = (int) $r->b_matched;

            if ($this->search !== '' && stripos($r->brand, $this->search) === false) {
                continue;
            }

            $out[] = [
                'brand'     => $r->brand,
                'a'         => $a,
                'b'         => $b,
                'a_matched' => $am,
                'b_matched' => $bm,
                'a_only'    => $a - $am,
                'b_only'    => $b - $bm,
                // Share of the smaller catalog that found a counterpart. Using
                // the smaller side avoids punishing a brand simply because one
                // distributor carries far more of it.
                'rate'      => min($a, $b) > 0 ? (int) round(max($am, $bm) / min($a, $b) * 100) : null,
                'both'      => $a > 0 && $b > 0,
            ];
        }

        usort($out, function ($x, $y) {
            return match ($this->sort) {
                'overlap' => [$y['a_matched'], $y['a'] + $y['b']] <=> [$x['a_matched'], $x['a'] + $x['b']],
                'size'    => ($y['a'] + $y['b']) <=> ($x['a'] + $x['b']),
                'name'    => strcasecmp($x['brand'], $y['brand']),
                // gap: brands both carry, least matched first — the work list
                default   => [$y['both'], $y['a_only'] + $y['b_only']]
                             <=> [$x['both'], $x['a_only'] + $x['b_only']],
            };
        });

        return $out;
    }

    public function getTotalsProperty(): array
    {
        $t = ['a' => 0, 'b' => 0, 'a_matched' => 0, 'b_matched' => 0, 'shared_brands' => 0];
        foreach ($this->rows as $r) {
            $t['a'] += $r['a'];
            $t['b'] += $r['b'];
            $t['a_matched'] += $r['a_matched'];
            $t['b_matched'] += $r['b_matched'];
            if ($r['both']) { $t['shared_brands']++; }
        }
        return $t;
    }

    /** Unmatched rows for the drilled-in brand, both sides. */
    public function getDrillProperty(): array
    {
        if (! $this->brand) {
            return [];
        }

        $sql = "
            select c.distributor_code, c.distributor_variant_no, c.name,
                   c.manufacturer_sku, c.upc, c.ean, c.msrp_cents
              from platform_distributor_catalogs c
              left join (
                    select row_a_id as id from catalog_matches where status in ('auto','confirmed')
                    union all
                    select row_b_id as id from catalog_matches where status in ('auto','confirmed')
              ) m on m.id = c.id
             where c.is_active = 1
               and c.distributor_code in (?, ?)
               and coalesce(nullif(trim(c.manufacturer), ''), '(no brand)') = ?
               and m.id is null
             order by c.distributor_code, c.name
             limit 300
        ";

        return DB::select($sql, [$this->codeA, $this->codeB, $this->brand]);
    }

    public function drill(string $brand): void
    {
        $this->brand = $this->brand === $brand ? null : $brand;
    }
}
