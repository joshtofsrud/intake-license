#!/bin/bash
# catalog-match-review — master admin screen for the held matches.
#
#   The matcher auto-linked 4,440 pairs and held 152: msrp_gap 89,
#   mpn_only 54, msrp_far 6, ambiguous 3. This is the screen for those 152.
#
#   Small by design. The Catalog Titles page had to become a bulk-operations
#   machine because it faced 1,284 scopes; this faces 152 rows and is a
#   side-by-side comparison with two buttons. Building it for scale it will
#   never need would be the wrong instinct.
#
#   What the first six msrp_far pairs showed, and what the screen therefore
#   has to make visible:
#     · pack size    "Axle Kit" $6.38 vs "Replacement Axles" $20.70
#     · granularity  one HLC row against TWO BTI rows differing only by
#                    crank length — no automatic answer exists
#     · thin titles  "Storm CL" vs "160mm Rotor, CL TA, 2.0mm, Storm" —
#                    possibly the same rotor, the title can't say
#   So each side shows title, MSRP, MPN, barcodes, category and attribute
#   count, and the row flags when its counterpart also matches others.
#
#   Fixes a real gap in the matcher while here: a HELD pair doesn't claim
#   its rows, so a one-to-many like the crank pair was never labelled
#   ambiguous. The count understated it. Rather than change the matcher's
#   claiming (which would hold MORE pairs and need re-measuring), the screen
#   computes sibling counts live and shows them — the information a person
#   needs to decide, without moving the tiers underneath.
#
#   Confirm and reject write status + decided_at, which the matcher already
#   refuses to overwrite on re-runs.
# NO MIGRATION. Server: optimize:clear && php artisan filament:cache-components
set -e
if [ -f app/Filament/Pages/CatalogMatchReview.php ]; then
  echo "catalog-match-review already applied — aborting."; exit 1
fi
if [ ! -f app/Models/CatalogMatch.php ]; then
  echo "catalog-matches must be applied first — aborting."; exit 1
fi

# ------------------------------------------------------------------ page
cat > 'app/Filament/Pages/CatalogMatchReview.php' <<'CMR_0_EOF'
<?php

// MARKER-MATCH-REVIEW

namespace App\Filament\Pages;

use App\Models\CatalogMatch;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Support\Facades\DB;
use Livewire\WithPagination;

/**
 * Review queue for catalog matches the matcher wouldn't auto-link.
 *
 * Deliberately small: 152 rows across HLC x BTI, so this is a side-by-side
 * comparison with confirm/reject, not a bulk-operations surface.
 */
class CatalogMatchReview extends Page
{
    use WithPagination;

    protected static ?string $navigationIcon  = 'heroicon-o-link';
    protected static ?string $navigationLabel = 'Catalog matches';
    protected static ?string $navigationGroup = 'Distribution';
    protected static ?int    $navigationSort  = 22;
    protected static ?string $title = 'Catalog matches';

    protected static string $view = 'filament.pages.catalog-match-review';

    public string $reason = 'all';      // all | msrp_gap | msrp_far | mpn_only | ambiguous
    public string $status = 'held';     // held | confirmed | rejected | auto

    public function updatingReason(): void { $this->resetPage(); }
    public function updatingStatus(): void { $this->resetPage(); }

    public function getMatchesProperty()
    {
        return CatalogMatch::query()
            ->with(['rowA', 'rowB'])
            ->where('status', $this->status)
            ->when($this->reason !== 'all', fn ($q) => $q->where('hold_reason', $this->reason))
            ->orderByRaw("FIELD(hold_reason,'ambiguous','msrp_far','msrp_gap','mpn_only')")
            ->orderByDesc('msrp_spread_pct')
            ->paginate(15);
    }

    public function getCountsProperty(): array
    {
        $held = CatalogMatch::where('status', 'held')
            ->select('hold_reason', DB::raw('count(*) as n'))
            ->groupBy('hold_reason')->pluck('n', 'hold_reason')->all();

        return [
            'all'       => array_sum($held),
            'ambiguous' => $held['ambiguous'] ?? 0,
            'msrp_far'  => $held['msrp_far'] ?? 0,
            'msrp_gap'  => $held['msrp_gap'] ?? 0,
            'mpn_only'  => $held['mpn_only'] ?? 0,
            'confirmed' => CatalogMatch::where('status', 'confirmed')->count(),
            'rejected'  => CatalogMatch::where('status', 'rejected')->count(),
            'auto'      => CatalogMatch::where('status', 'auto')->count(),
        ];
    }

    /**
     * MARKER-MATCH-REVIEW — how many OTHER pairs each of these rows appears
     * in. A held pair doesn't claim its rows, so the matcher's `ambiguous`
     * flag misses one-to-many cases like the two crank rows that differ only
     * by length. Computed here rather than by changing the matcher's
     * claiming, which would shift the tiers we just measured.
     *
     * @return array<string,int> row id => other pair count
     */
    public function getSiblingsProperty(): array
    {
        $ids = [];
        foreach ($this->matches as $m) {
            $ids[] = $m->row_a_id;
            $ids[] = $m->row_b_id;
        }
        if (! $ids) {
            return [];
        }

        $rows = DB::table('catalog_matches')
            ->selectRaw('id_col, count(*) as n')
            ->fromSub(
                DB::table('catalog_matches')->select('row_a_id as id_col')
                    ->whereIn('row_a_id', $ids)->whereIn('status', ['held', 'auto', 'confirmed'])
                    ->unionAll(
                        DB::table('catalog_matches')->select('row_b_id as id_col')
                            ->whereIn('row_b_id', $ids)->whereIn('status', ['held', 'auto', 'confirmed'])
                    ),
                'u'
            )
            ->groupBy('id_col')->pluck('n', 'id_col')->all();

        // Subtract the pair being looked at, so 0 means "no others".
        return array_map(fn ($n) => max(0, ((int) $n) - 1), $rows);
    }

    public function confirm(int $id): void
    {
        CatalogMatch::whereKey($id)->update(['status' => 'confirmed', 'decided_at' => now()]);
        Notification::make()->success()->title('Linked')->send();
    }

    public function reject(int $id): void
    {
        CatalogMatch::whereKey($id)->update(['status' => 'rejected', 'decided_at' => now()]);
        Notification::make()->success()->title('Marked not the same')->send();
    }

    /** Undo, for when a decision was wrong. */
    public function reopen(int $id): void
    {
        CatalogMatch::whereKey($id)->update(['status' => 'held', 'decided_at' => null]);
        Notification::make()->success()->title('Back in the queue')->send();
    }
}
CMR_0_EOF

# ------------------------------------------------------------------ view
cat > 'resources/views/filament/pages/catalog-match-review.blade.php' <<'CMR_1_EOF'
{{-- MARKER-MATCH-REVIEW --}}
<x-filament-panels::page>

  @php
    $c = $this->counts;
    $reasonLabels = [
      'all'       => 'Everything held',
      'ambiguous' => 'Matches more than one',
      'msrp_far'  => 'Price far apart',
      'msrp_gap'  => 'Price differs',
      'mpn_only'  => 'Part number only',
    ];
    $why = [
      'ambiguous' => 'One row matches several on the other side — usually one distributor splits a product the other keeps whole.',
      'msrp_far'  => 'Same barcode, very different price. Usually a single item against a multi-pack.',
      'msrp_gap'  => 'Same barcode, prices apart enough to be worth a look.',
      'mpn_only'  => 'No barcode agreed — same brand and part number only. Good evidence, not proof.',
    ];
  @endphp

  {{-- filters --}}
  <div class="flex flex-wrap items-center gap-2">
    @foreach ($reasonLabels as $key => $label)
      <button type="button" wire:click="$set('reason','{{ $key }}')"
        class="text-xs font-semibold rounded-full px-3 py-1.5 ring-1
          {{ $reason === $key && $status === 'held'
             ? 'bg-primary-500/15 text-primary-600 dark:text-primary-400 ring-primary-500'
             : 'text-gray-500 dark:text-gray-400 ring-gray-300 dark:ring-white/10' }}">
        {{ $label }} <span class="opacity-60">{{ number_format($c[$key] ?? 0) }}</span>
      </button>
    @endforeach

    <div class="flex-1"></div>

    <button type="button" wire:click="$set('status','confirmed')"
      class="text-xs font-semibold rounded-full px-3 py-1.5 ring-1
        {{ $status === 'confirmed' ? 'ring-primary-500 text-primary-600' : 'ring-gray-300 dark:ring-white/10 text-gray-500' }}">
      Linked by hand <span class="opacity-60">{{ number_format($c['confirmed']) }}</span>
    </button>
    <button type="button" wire:click="$set('status','rejected')"
      class="text-xs font-semibold rounded-full px-3 py-1.5 ring-1
        {{ $status === 'rejected' ? 'ring-primary-500 text-primary-600' : 'ring-gray-300 dark:ring-white/10 text-gray-500' }}">
      Not the same <span class="opacity-60">{{ number_format($c['rejected']) }}</span>
    </button>
    <button type="button" wire:click="$set('status','held')"
      class="text-xs font-semibold rounded-full px-3 py-1.5 ring-1
        {{ $status === 'held' ? 'ring-primary-500 text-primary-600' : 'ring-gray-300 dark:ring-white/10 text-gray-500' }}">
      Needs review
    </button>
  </div>

  <p class="text-xs text-gray-400">
    {{ number_format($c['auto']) }} pairs linked automatically and aren't listed here.
  </p>

  @if ($status === 'held' && $reason !== 'all' && isset($why[$reason]))
    <div class="rounded-lg bg-amber-500/10 ring-1 ring-amber-500/30 px-4 py-2.5 text-xs text-amber-700 dark:text-amber-400">
      {{ $why[$reason] }}
    </div>
  @endif

  {{-- pairs --}}
  @php $sib = $this->siblings; @endphp

  <div class="space-y-3">
    @forelse ($this->matches as $m)
      @php
        $a = $m->rowA; $b = $m->rowB;
        $sa = $sib[$m->row_a_id] ?? 0;
        $sb = $sib[$m->row_b_id] ?? 0;
      @endphp

      <div wire:key="match-{{ $m->id }}"
           class="rounded-xl bg-white dark:bg-gray-900 ring-1 ring-gray-950/5 dark:ring-white/10 overflow-hidden">

        <div class="flex items-center justify-between gap-3 px-4 py-2 bg-gray-50 dark:bg-white/5">
          <div class="flex items-center gap-2 text-[10px] uppercase tracking-wider text-gray-400">
            <span>matched on {{ $m->matched_on }}</span>
            @if ($m->msrp_spread_pct !== null)
              <span>· price differs {{ $m->msrp_spread_pct }}%</span>
            @endif
            @if ($m->hold_reason)
              <span class="rounded px-1.5 py-0.5 bg-amber-500/15 text-amber-600 dark:text-amber-400 font-bold">
                {{ $reasonLabels[$m->hold_reason] ?? $m->hold_reason }}
              </span>
            @endif
          </div>
          <div class="flex gap-2">
            @if ($m->status === 'held')
              <button wire:click="reject({{ $m->id }})"
                class="text-xs font-semibold rounded-lg px-3 py-1.5 ring-1 ring-gray-300 dark:ring-white/10">
                Not the same
              </button>
              <button wire:click="confirm({{ $m->id }})"
                class="text-xs font-semibold rounded-lg px-3 py-1.5 bg-primary-600 text-white">
                Same product
              </button>
            @else
              <button wire:click="reopen({{ $m->id }})"
                class="text-xs font-semibold rounded-lg px-3 py-1.5 ring-1 ring-gray-300 dark:ring-white/10">
                Undo
              </button>
            @endif
          </div>
        </div>

        <div class="grid md:grid-cols-2 divide-y md:divide-y-0 md:divide-x divide-gray-100 dark:divide-white/5">
          @foreach ([[$a, $m->code_a, $sa], [$b, $m->code_b, $sb]] as [$row, $code, $others])
            <div class="p-4">
              <div class="flex items-center gap-2 mb-1.5">
                <span class="text-[10px] font-bold tracking-wide rounded bg-gray-100 dark:bg-white/10 px-1.5 py-0.5">
                  {{ $code }}
                </span>
                @if ($others > 0)
                  <span class="text-[10px] font-semibold text-amber-600 dark:text-amber-400">
                    also matches {{ $others }} other{{ $others === 1 ? '' : 's' }}
                  </span>
                @endif
              </div>

              <div class="text-sm font-semibold leading-snug">{{ $row?->name ?? '—' }}</div>

              <div class="mt-2 space-y-0.5 text-xs text-gray-500 dark:text-gray-400">
                <div>
                  <span class="text-gray-400">MSRP</span>
                  <span class="font-mono ml-2">
                    {{ $row?->msrp_cents ? '$' . number_format($row->msrp_cents / 100, 2) : '—' }}
                  </span>
                </div>
                <div>
                  <span class="text-gray-400">Part no.</span>
                  <span class="font-mono ml-2">{{ $row?->manufacturer_sku ?: '—' }}</span>
                </div>
                <div>
                  <span class="text-gray-400">UPC</span>
                  <span class="font-mono ml-2">{{ $row?->upc ?: '—' }}</span>
                  @if ($row?->ean)
                    <span class="text-gray-400 ml-2">EAN</span>
                    <span class="font-mono ml-1">{{ $row->ean }}</span>
                  @endif
                </div>
                <div>
                  <span class="text-gray-400">Brand</span>
                  <span class="ml-2">{{ $row?->manufacturer ?: '—' }}</span>
                </div>
                <div>
                  <span class="text-gray-400">Category</span>
                  <span class="ml-2">{{ $row?->category_path ?: ($row?->category ?: '—') }}</span>
                </div>
                <div>
                  <span class="text-gray-400">Attributes</span>
                  <span class="ml-2">{{ count($row?->attributes ?? []) }}</span>
                </div>
              </div>
            </div>
          @endforeach
        </div>
      </div>
    @empty
      <div class="rounded-xl bg-white dark:bg-gray-900 ring-1 ring-gray-950/5 dark:ring-white/10 p-10 text-center">
        <p class="text-sm text-gray-400">Nothing here.</p>
        <p class="text-xs text-gray-400 mt-1">
          Run <span class="font-mono">catalog:match</span> after a sync to look for new pairs.
        </p>
      </div>
    @endforelse
  </div>

  <div>{{ $this->matches->links() }}</div>

</x-filament-panels::page>
CMR_1_EOF

# ------------------------------------------------------------------ register
python3 - <<'CMR_2_EOF'
import io
p = 'app/Providers/Filament/AdminPanelProvider.php'
s = io.open(p, encoding='utf-8').read()

old = """                \\App\\Filament\\Pages\\CatalogTitles::class, // MARKER-PATCH-HLCE2 title editor"""
assert s.count(old) == 1, s.count(old)
new = """                \\App\\Filament\\Pages\\CatalogTitles::class, // MARKER-PATCH-HLCE2 title editor
                // MARKER-MATCH-REVIEW \u2014 explicit registration; this panel does
                // not auto-discover, so the page has no route until listed.
                \\App\\Filament\\Pages\\CatalogMatchReview::class,"""
io.open(p, 'w', encoding='utf-8').write(s.replace(old, new))
print('registered ok')
CMR_2_EOF

php -l app/Filament/Pages/CatalogMatchReview.php
php -l app/Providers/Filament/AdminPanelProvider.php

echo
echo "catalog-match-review applied."
