<?php
// MARKER-COVERAGE: where the prospects are, and how far along they are, on a US map.

namespace App\Filament\Pages;

use App\Models\SalesChannel;
use App\Services\Sales\CoverageStats;
use App\Services\Sales\TownCoverage;
use App\Support\AdminAccess;
use Filament\Pages\Page;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class SalesCoverage extends Page
{
    use \App\Support\UsesAdminNav;
    protected static ?string $navigationIcon  = 'heroicon-o-map';
    protected static ?string $navigationLabel = 'Coverage';
    protected static ?string $navigationGroup = 'Sales';
    protected static ?int    $navigationSort  = 9;
    protected static string  $view            = 'filament.pages.sales-coverage';
    protected static ?string $slug            = 'sales-coverage';
    protected static ?string $title           = 'Coverage';

    public const METRICS = ['shops' => 'Shops', 'contacted' => 'Contacted', 'demo' => 'Demo', 'trial' => 'Trial', 'won' => 'Won', 'towns' => 'Towns searched'];
    public const STAGE_LABELS = ['prospect' => 'Prospect', 'verifying' => 'Verifying', 'contacted' => 'Contacted', 'demo_booked' => 'Demo booked',
        'demo_done' => 'Demo done', 'trial' => 'Trial', 'won' => 'Won'];
    public const STAGE_COLORS = ['prospect' => '#a1a1aa', 'verifying' => '#d4d4d8', 'contacted' => '#60a5fa', 'demo_booked' => '#c084fc',
        'demo_done' => '#a78bfa', 'trial' => '#f5b942', 'won' => '#BEF264'];

    public array  $inds   = [];      // industry ids; empty = all
    public string $metric = 'shops';
    public string $focus  = '';      // a state code when zoomed in
    public string $sort   = 'shops';
    public string $dir    = 'desc';

    private ?array $memo = null;

    public static function canAccess(): bool
    {
        return AdminAccess::allows(Auth::guard('web')->user(), 'crm');
    }

    public function mount(): void
    {
        abort_unless(static::canAccess(), 403);
    }

    /** Industry tabs: id => [name, shops], plus "No industry" when there are any. */
    public function industries(): array
    {
        $counts = DB::table('sales_prospects')->where('stage', '!=', 'lost')->selectRaw('channel_id, COUNT(*) AS n')->groupBy('channel_id')->pluck('n', 'channel_id')->all();
        $out = [];
        foreach (SalesChannel::query()->where('status', '!=', 'stub')->orderBy('name')->get(['id', 'name']) as $c) $out[$c->id] = [$c->name, (int) ($counts[$c->id] ?? 0)];
        $none = (int) ($counts[''] ?? 0);
        if ($none) $out['none'] = ['No industry', $none];
        return $out;
    }

    public function toggleInd(string $id): void
    {
        if ($id === '') { $this->inds = []; }
        elseif (in_array($id, $this->inds, true)) { $this->inds = array_values(array_diff($this->inds, [$id])); }
        else { $this->inds[] = $id; }
        $this->dispatch('coverage-updated');
    }

    public function setMetric(string $m): void
    {
        if (isset(self::METRICS[$m])) $this->metric = $m;
        $this->dispatch('coverage-updated');
    }

    public function focusState(string $st): void
    {
        $st = strtoupper($st);
        $this->focus = isset(TownCoverage::STATES[$st]) ? $st : '';
        $this->dispatch('coverage-updated');
    }

    public function clearFocus(): void
    {
        $this->focus = '';
        $this->dispatch('coverage-updated');
    }

    public function sortBy(string $k): void
    {
        $this->dir = $this->sort === $k && $this->dir === 'desc' ? 'asc' : 'desc';
        if ($k === 'name' && $this->sort !== 'name') $this->dir = 'asc';
        $this->sort = $k;
    }

    /** Everything the page shows, worked out once per request. */
    public function data(): array
    {
        if ($this->memo !== null) return $this->memo;
        $by = CoverageStats::byState($this->inds);
        $towns = CoverageStats::townsSearched($this->inds);
        $rows = [];
        foreach (array_unique(array_merge(array_keys($by), array_keys(array_filter($towns)))) as $st) {
            $c = $by[$st] ?? [];
            $r = ['st' => $st, 'name' => $st === '??' ? 'No state' : (TownCoverage::STATES[$st] ?? $st), 'towns' => $st === '??' ? null : ($towns[$st] ?? 0)];
            foreach (CoverageStats::STAGES as $s) $r[$s] = (int) ($c[$s] ?? 0);
            // reached-or-further totals live under r_ keys, apart from the stage counts of the same name
            foreach (CoverageStats::REACHED as $m => $stages) $r['r_' . $m] = array_sum(array_map(fn ($s) => $r[$s], $stages));
            $r['shops'] = $r['r_shops'];
            $rows[] = $r;
        }
        $k = $this->sort; $dir = $this->dir === 'asc' ? 1 : -1;
        usort($rows, function ($a, $b) use ($k, $dir) {
            if ($a['st'] === '??') return 1; if ($b['st'] === '??') return -1;
            return $k === 'name' ? strcmp($a['name'], $b['name']) * $dir : ((($a[$k] ?? -1) <=> ($b[$k] ?? -1)) * $dir ?: strcmp($a['name'], $b['name']));
        });
        $tot = [];
        foreach (array_keys(CoverageStats::REACHED) as $m) $tot[$m] = array_sum(array_column($rows, 'r_' . $m));
        $values = [];
        foreach ($rows as $r) if ($r['st'] !== '??') $values[$r['st']] = $this->metric === 'towns' ? (int) $r['towns'] : (int) $r['r_' . $this->metric];
        $focusRow = null;
        foreach ($rows as $r) if ($r['st'] === $this->focus) $focusRow = $r;
        return $this->memo = [
            'rows' => $rows, 'tot' => $tot, 'values' => $values, 'focusRow' => $focusRow,
            'points' => $this->focus ? CoverageStats::points($this->focus, $this->inds) : [],
            'circles' => $this->focus ? CoverageStats::circles($this->focus, $this->inds) : [],
        ];
    }

    /** Prospects (and Find shops) for one state, carrying a single chosen industry along. */
    public function prospectsUrl(string $st): string
    {
        $q = ['state' => $st] + (count($this->inds) === 1 ? ['industry' => $this->inds[0]] : []);
        return SalesPipeline::getUrl() . '?' . http_build_query($q);
    }

    public function findUrl(string $st): string
    {
        $q = ['state' => $st] + (count($this->inds) === 1 && $this->inds[0] !== 'none' ? ['industry' => $this->inds[0]] : []);
        return SalesFindShops::getUrl() . '?' . http_build_query($q);
    }
}
