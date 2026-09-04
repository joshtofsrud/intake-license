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
    use \App\Support\UsesAdminNav; // MARKER-NAV-ORDER
    use \App\Support\GatedByAdminArea; // MARKER-ADMIN-NAV-GATE
    protected static string $adminArea = 'catalog';

    use WithPagination;

    protected static ?string $navigationIcon  = 'heroicon-o-link';
    protected static ?string $navigationLabel = 'Catalog matches';
    protected static ?string $navigationGroup = 'Distribution';
    protected static ?int    $navigationSort  = 50;
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
