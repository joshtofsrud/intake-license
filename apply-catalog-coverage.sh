#!/bin/bash
# catalog-coverage — compare two distributors, by brand.
#
#   What overlaps, what only one of them carries, and where the catalogs
#   disagree — organised by brand, since that's how buying decisions and
#   catalog work are actually grouped.
#
#   Reads catalog_matches, so it reflects auto-links and any decision made in
#   the review queue. Held and rejected pairs count as NOT matched, which is
#   the honest reading: nobody has agreed they're the same product.
#
#   One thing the numbers will show that is worth understanding rather than
#   fixing: brands don't line up across distributors. BTI files Avid parts
#   under SRAM, so an "AVID" row here can show HLC rows with no BTI
#   counterpart while SRAM shows the reverse, even though the products are
#   matched to each other. Rows are therefore grouped by each side's own
#   brand and the matched count is attributed to both — a brand pair that
#   looks lopsided is usually a naming difference, not missing coverage.
#   The page says so rather than hiding it.
#
#   Aggregation runs in SQL over ~39k catalog rows and ~4.6k matches, which
#   is a fraction of a second. Nothing is materialised, so it's always
#   current with the last sync and the last review decision.
# NO MIGRATION. Server: optimize:clear && php artisan filament:cache-components
set -e
if [ -f app/Filament/Pages/CatalogCoverage.php ]; then
  echo "catalog-coverage already applied — aborting."; exit 1
fi

# ------------------------------------------------------------------ page
cat > 'app/Filament/Pages/CatalogCoverage.php' <<'CC_0_EOF'
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
    protected static ?string $navigationIcon  = 'heroicon-o-scale';
    protected static ?string $navigationLabel = 'Catalog coverage';
    protected static ?string $navigationGroup = 'Distribution';
    protected static ?int    $navigationSort  = 23;
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
CC_0_EOF

# ------------------------------------------------------------------ view
cat > 'resources/views/filament/pages/catalog-coverage.blade.php' <<'CC_1_EOF'
{{-- MARKER-CATALOG-COVERAGE --}}
<x-filament-panels::page>

  @php $codes = $this->distributorCodes(); $t = $this->totals; @endphp

  <div class="flex flex-wrap items-end gap-3">
    <div>
      <label class="block text-[10px] uppercase tracking-wider text-gray-400 mb-1">Compare</label>
      <select wire:model.live="codeA"
              class="fi-input rounded-lg border-gray-300 dark:border-white/10 dark:bg-white/5 text-sm py-1.5">
        @foreach ($codes as $c)<option value="{{ $c }}">{{ $c }}</option>@endforeach
      </select>
    </div>
    <span class="pb-2 text-gray-400 text-sm">against</span>
    <div>
      <label class="block text-[10px] uppercase tracking-wider text-gray-400 mb-1">&nbsp;</label>
      <select wire:model.live="codeB"
              class="fi-input rounded-lg border-gray-300 dark:border-white/10 dark:bg-white/5 text-sm py-1.5">
        @foreach ($codes as $c)<option value="{{ $c }}">{{ $c }}</option>@endforeach
      </select>
    </div>

    <div class="flex-1"></div>

    <input wire:model.live.debounce.400ms="search" placeholder="Find a brand…"
           class="fi-input rounded-lg border-gray-300 dark:border-white/10 dark:bg-white/5 text-sm py-1.5 w-52">

    <select wire:model.live="sort"
            class="fi-input rounded-lg border-gray-300 dark:border-white/10 dark:bg-white/5 text-sm py-1.5">
      <option value="gap">Biggest gaps first</option>
      <option value="overlap">Most overlap first</option>
      <option value="size">Largest brands first</option>
      <option value="name">A–Z</option>
    </select>
  </div>

  @if ($codeA === $codeB)
    <div class="rounded-lg bg-amber-500/10 ring-1 ring-amber-500/30 px-4 py-3 text-sm text-amber-700 dark:text-amber-400">
      Pick two different distributors.
    </div>
  @else

    {{-- totals --}}
    <div class="grid grid-cols-2 md:grid-cols-4 gap-px bg-gray-200 dark:bg-white/10 rounded-xl overflow-hidden ring-1 ring-gray-950/5 dark:ring-white/10">
      <div class="bg-white dark:bg-gray-900 p-4">
        <div class="text-xl font-bold">{{ number_format($t['a']) }}</div>
        <div class="text-xs text-gray-400 mt-0.5">{{ $codeA }} items</div>
      </div>
      <div class="bg-white dark:bg-gray-900 p-4">
        <div class="text-xl font-bold">{{ number_format($t['b']) }}</div>
        <div class="text-xs text-gray-400 mt-0.5">{{ $codeB }} items</div>
      </div>
      <div class="bg-white dark:bg-gray-900 p-4">
        <div class="text-xl font-bold text-primary-600 dark:text-primary-400">{{ number_format($t['a_matched']) }}</div>
        <div class="text-xs text-gray-400 mt-0.5">carried by both</div>
      </div>
      <div class="bg-white dark:bg-gray-900 p-4">
        <div class="text-xl font-bold">{{ number_format($t['shared_brands']) }}</div>
        <div class="text-xs text-gray-400 mt-0.5">brands in both</div>
      </div>
    </div>

    <p class="text-xs text-gray-400">
      Matched means a link a person hasn't disputed — auto-linked or confirmed. Held and rejected
      pairs count as unmatched.
      <br>
      Brands don't always agree across distributors: BTI files Avid parts under SRAM, so a
      one-sided brand row is often a naming difference rather than missing coverage.
    </p>

    {{-- brand table --}}
    <div class="rounded-xl bg-white dark:bg-gray-900 ring-1 ring-gray-950/5 dark:ring-white/10 overflow-hidden">
      <table class="w-full text-sm">
        <thead class="bg-gray-50 dark:bg-white/5 text-[10px] uppercase tracking-wider text-gray-400">
          <tr>
            <th class="text-left py-2.5 px-4">Brand</th>
            <th class="text-right py-2.5 px-3">{{ $codeA }}</th>
            <th class="text-right py-2.5 px-3">{{ $codeB }}</th>
            <th class="text-right py-2.5 px-3">Both</th>
            <th class="text-right py-2.5 px-3">{{ $codeA }} only</th>
            <th class="text-right py-2.5 px-3">{{ $codeB }} only</th>
            <th class="text-right py-2.5 px-4">Overlap</th>
          </tr>
        </thead>
        <tbody>
        @forelse ($this->rows as $r)
          <tr wire:key="brand-{{ $r['brand'] }}"
              wire:click="drill(@js($r['brand']))"
              class="border-t border-gray-100 dark:border-white/5 cursor-pointer
                     hover:bg-gray-50 dark:hover:bg-white/5
                     {{ $brand === $r['brand'] ? 'bg-primary-500/5' : '' }}">
            <td class="px-4 py-2.5 font-medium">
              {{ $r['brand'] }}
              @unless ($r['both'])
                <span class="text-[10px] uppercase tracking-wide text-gray-400 ml-2">one side only</span>
              @endunless
            </td>
            <td class="px-3 py-2.5 text-right font-mono text-xs">{{ number_format($r['a']) }}</td>
            <td class="px-3 py-2.5 text-right font-mono text-xs">{{ number_format($r['b']) }}</td>
            <td class="px-3 py-2.5 text-right font-mono text-xs text-primary-600 dark:text-primary-400">
              {{ number_format(max($r['a_matched'], $r['b_matched'])) }}
            </td>
            <td class="px-3 py-2.5 text-right font-mono text-xs text-gray-500">{{ number_format($r['a_only']) }}</td>
            <td class="px-3 py-2.5 text-right font-mono text-xs text-gray-500">{{ number_format($r['b_only']) }}</td>
            <td class="px-4 py-2.5 text-right font-mono text-xs">
              {{ $r['rate'] === null ? '—' : $r['rate'] . '%' }}
            </td>
          </tr>

          @if ($brand === $r['brand'])
            <tr wire:key="drill-{{ $r['brand'] }}">
              <td colspan="7" class="px-4 py-3 bg-gray-50 dark:bg-white/5 border-t border-gray-100 dark:border-white/5">
                <div class="text-[10px] uppercase tracking-wider text-gray-400 mb-2">
                  Unmatched in {{ $r['brand'] }} — no counterpart at the other distributor
                </div>
                @if (count($this->drill))
                  <div class="max-h-80 overflow-y-auto rounded-lg ring-1 ring-gray-200 dark:ring-white/10">
                    <table class="w-full text-xs">
                      @foreach ($this->drill as $d)
                        <tr class="border-b border-gray-100 dark:border-white/5 last:border-0">
                          <td class="px-3 py-1.5 w-14">
                            <span class="text-[10px] font-bold rounded bg-gray-100 dark:bg-white/10 px-1.5 py-0.5">
                              {{ $d->distributor_code }}
                            </span>
                          </td>
                          <td class="px-3 py-1.5">{{ $d->name }}</td>
                          <td class="px-3 py-1.5 font-mono text-gray-400">{{ $d->manufacturer_sku ?: '—' }}</td>
                          <td class="px-3 py-1.5 font-mono text-gray-400">{{ $d->upc ?: ($d->ean ?: 'no barcode') }}</td>
                          <td class="px-3 py-1.5 text-right font-mono text-gray-400">
                            {{ $d->msrp_cents ? '$' . number_format($d->msrp_cents / 100, 2) : '—' }}
                          </td>
                        </tr>
                      @endforeach
                    </table>
                  </div>
                  <p class="text-[11px] text-gray-400 mt-2">Showing up to 300.</p>
                @else
                  <p class="text-xs text-gray-400">Everything in this brand is matched.</p>
                @endif
              </td>
            </tr>
          @endif
        @empty
          <tr><td colspan="7" class="p-8 text-center text-sm text-gray-400">
            Nothing to compare. Sync both distributors, then run
            <span class="font-mono">catalog:index-identifiers</span> and
            <span class="font-mono">catalog:match</span>.
          </td></tr>
        @endforelse
        </tbody>
      </table>
    </div>
  @endif

</x-filament-panels::page>
CC_1_EOF

# ------------------------------------------------------------------ register
python3 - <<'CC_2_EOF'
import io
p = 'app/Providers/Filament/AdminPanelProvider.php'
s = io.open(p, encoding='utf-8').read()

old = """                \\App\\Filament\\Pages\\CatalogMatchReview::class,"""
assert s.count(old) == 1, s.count(old)
new = old + """
                // MARKER-CATALOG-COVERAGE — explicit registration; this panel
                // does not auto-discover.
                \\App\\Filament\\Pages\\CatalogCoverage::class,"""
io.open(p, 'w', encoding='utf-8').write(s.replace(old, new))
print('registered ok')
CC_2_EOF

echo
echo "catalog-coverage applied."
