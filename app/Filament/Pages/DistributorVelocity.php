<?php

namespace App\Filament\Pages;

use App\Models\PlatformSettings;
use App\Services\Distributors\StockVelocityRecorder;
use App\Support\AdminAccess;
use Carbon\Carbon;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * MARKER-VELOCITY: how fast each distributor's stock is moving, days left at
 * that pace, and the next arrival date. Read from distributor_stock_summary,
 * which the nightly per-shop sync keeps current. No shop sales data is used.
 */
class DistributorVelocity extends Page
{
    use \App\Support\UsesAdminNav;
    use \App\Support\GatedByAdminArea;
    protected static string $adminArea = 'catalog';

    protected static ?string $navigationIcon  = 'heroicon-o-arrow-trending-down';
    protected static ?string $navigationLabel = 'Distributor velocity';
    protected static ?string $navigationGroup = 'Distribution';
    protected static ?int    $navigationSort  = 65;
    protected static ?string $title           = 'Distributor velocity';
    protected static ?string $slug            = 'distributor-velocity';
    protected static string  $view            = 'filament.pages.distributor-velocity';

    public const VIEWS = ['run' => 'Running out', 'fast' => 'Fastest moving', 'arrival' => 'Arrival due', 'slow' => 'Slow or still'];
    public const SHOP_SWITCHES = [
        'show_days_left' => ['Show days left to shops', "On the shop's inventory list and special-order lookup, for items the shop already carries.", false],
        'show_arrival'   => ['Show next arrival date', 'Only where the distributor sends one.', false],
        'require_good'   => ['Hide estimates until history is Good', 'Shops see "Not enough history" instead of a number until an item has 21 days of readings.', true],
        'warn_on_po'     => ['Warn on low cover when ordering', 'An amber note on a purchase order line when days left is under the vendor lead time.', false],
    ];

    public string $tab = 'items';
    public string $vendor = '';
    public string $mode = 'run';
    public string $search = '';
    public bool $hideEarly = false;
    public int $limit = 100;
    public ?int $selected = null;
    public array $shop = [];

    public function mount(): void
    {
        $saved = (array) (PlatformSettings::current()->velocity_settings ?? []);
        foreach (self::SHOP_SWITCHES as $k => $d) {
            $this->shop[$k] = (bool) ($saved[$k] ?? $d[2]);
        }
        $codes = $this->codes();
        $on = $saved['vendors'] ?? $codes;
        $this->shop['vendors'] = array_fill_keys($codes, false);
        foreach ($codes as $c) {
            $this->shop['vendors'][$c] = in_array($c, (array) $on, true);
        }
    }

    public function canEdit(): bool
    {
        return AdminAccess::level(Auth::guard('web')->user(), 'catalog') === 'full';
    }

    /** Distributor codes with readings, plus any with catalog rows. */
    public function codes(): array
    {
        $a = DB::table('distributor_stock_summary')->distinct()->pluck('distributor_code')->all();
        $b = DB::table('platform_distributor_catalogs')->where('is_active', true)->distinct()->pluck('distributor_code')->all();
        $all = array_values(array_unique(array_merge($a, $b)));
        sort($all);
        return $all;
    }

    public function updated($name): void
    {
        if (in_array($name, ['search', 'hideEarly', 'vendor', 'mode'], true)) {
            $this->limit = 100;
        }
    }

    public function setTab(string $t): void { if (in_array($t, ['items', 'coverage', 'shops'], true)) $this->tab = $t; }
    public function setView(string $v): void { if (isset(self::VIEWS[$v])) { $this->mode = $v; $this->limit = 100; } }
    public function setVendor(string $c): void { $this->vendor = $c; $this->limit = 100; }
    public function showMore(): void { $this->limit += 100; }
    public function select(int $id): void { $this->selected = $id; }
    public function closeDetail(): void { $this->selected = null; }
    public function clearFilters(): void { $this->search = ''; $this->hideEarly = false; $this->vendor = ''; $this->mode = 'run'; $this->limit = 100; }

    public function saveShop(): void
    {
        abort_unless($this->canEdit(), 403);
        $out = [];
        foreach (self::SHOP_SWITCHES as $k => $d) {
            $out[$k] = (bool) ($this->shop[$k] ?? false);
        }
        $out['vendors'] = array_keys(array_filter((array) ($this->shop['vendors'] ?? [])));
        $ps = PlatformSettings::current();
        $ps->velocity_settings = $out;
        $ps->save();
        Notification::make()->title('Shop visibility saved')->success()->send();
    }

    /** Headline numbers for the Items tab. */
    public function stats(): array
    {
        $q = DB::table('distributor_stock_summary')->when($this->vendor !== '', fn ($q) => $q->where('distributor_code', $this->vendor));
        $today = now()->toDateString();
        $r = $q->selectRaw('COUNT(*) AS items,
                SUM(rate_per_day IS NOT NULL) AS with_rate,
                SUM(days_left IS NOT NULL AND days_left < 14) AS under14,
                SUM(next_arrival_on IS NOT NULL AND next_arrival_on <= ?) AS due7,
                MAX(last_reading_at) AS last_read', [Carbon::parse($today)->addDays(7)->toDateString()])->first();
        return [
            'items' => (int) $r->items, 'with_rate' => (int) $r->with_rate, 'under14' => (int) $r->under14,
            'due7' => (int) $r->due7, 'last_read' => $r->last_read,
        ];
    }

    public function vendorCounts(): array
    {
        return DB::table('distributor_stock_summary')->selectRaw('distributor_code, COUNT(*) AS n')
            ->groupBy('distributor_code')->pluck('n', 'distributor_code')->map(fn ($n) => (int) $n)->all();
    }

    private function baseQuery()
    {
        $q = DB::table('distributor_stock_summary as s')
            ->leftJoin('platform_distributor_catalogs as c', 'c.id', '=', 's.distributor_catalog_id')
            ->select('s.*', DB::raw('COALESCE(c.display_name, c.name) AS title'), 'c.manufacturer AS brand', 'c.manufacturer_sku AS mpn')
            ->when($this->vendor !== '', fn ($q) => $q->where('s.distributor_code', $this->vendor))
            ->when($this->hideEarly, fn ($q) => $q->where('s.readings', '>=', 3)->whereNotNull('s.rate_per_day'));

        $term = trim($this->search);
        if ($term !== '') {
            $like = '%' . str_replace(['%', '_'], ['\%', '\_'], $term) . '%';
            $q->where(function ($w) use ($like) {
                $w->where('c.display_name', 'like', $like)->orWhere('c.name', 'like', $like)
                  ->orWhere('c.manufacturer', 'like', $like)->orWhere('c.manufacturer_sku', 'like', $like)
                  ->orWhere('s.distributor_variant_no', 'like', $like);
            });
        }

        switch ($this->mode) {
            case 'fast':
                $q->where('s.rate_per_day', '>', 0)->orderByDesc('s.rate_per_day');
                break;
            case 'arrival':
                $q->whereNotNull('s.next_arrival_on')->orderBy('s.next_arrival_on')->orderBy('s.days_left');
                break;
            case 'slow':
                $q->where('s.readings', '>=', 3)->where('s.observed_days', '>=', 1)->where('s.avail', '>', 0)
                  ->where(fn ($w) => $w->whereNull('s.rate_per_day')->orWhere('s.rate_per_day', '<', 0.1))
                  ->orderByDesc('s.avail');
                break;
            default:
                $q->whereNotNull('s.days_left')->orderBy('s.days_left');
        }
        return $q;
    }

    /** @return array{rows: array, total: int} */
    public function rows(): array
    {
        $total = (clone $this->baseQuery())->reorder()->count();
        $rows = $this->baseQuery()->limit($this->limit)->get();

        $catIds = $rows->pluck('distributor_catalog_id')->filter()->unique()->values()->all();
        $shops = $catIds ? DB::table('tenant_inventory_item_vendors as v')
            ->join('tenant_inventory_items as i', 'i.id', '=', 'v.inventory_item_id')
            ->whereIn('v.distributor_catalog_id', $catIds)
            ->selectRaw('v.distributor_catalog_id, COUNT(DISTINCT i.tenant_id) AS n')
            ->groupBy('v.distributor_catalog_id')->pluck('n', 'distributor_catalog_id')->all() : [];

        $since = now()->subDays(30);
        $hist = [];
        if ($rows->isNotEmpty()) {
            DB::table('distributor_stock_changes')
                ->whereIn('distributor_variant_no', $rows->pluck('distributor_variant_no')->all())
                ->where('recorded_at', '>=', $since)
                ->orderBy('recorded_at')
                ->get(['distributor_code', 'distributor_variant_no', 'avail', 'recorded_at'])
                ->each(function ($c) use (&$hist) { $hist[$c->distributor_code . '|' . $c->distributor_variant_no][] = $c; });
        }

        $out = [];
        foreach ($rows as $r) {
            $r->shops = (int) ($shops[$r->distributor_catalog_id] ?? 0);
            $r->conf = StockVelocityRecorder::confidence($r);
            $r->spark = $this->spark($hist[$r->distributor_code . '|' . $r->distributor_variant_no] ?? [], (int) $r->avail, $since, 80, 22);
            $out[] = $r;
        }
        return ['rows' => $out, 'total' => $total];
    }

    public function detail(): ?object
    {
        if (! $this->selected) return null;
        $r = DB::table('distributor_stock_summary as s')
            ->leftJoin('platform_distributor_catalogs as c', 'c.id', '=', 's.distributor_catalog_id')
            ->select('s.*', DB::raw('COALESCE(c.display_name, c.name) AS title'), 'c.manufacturer AS brand', 'c.manufacturer_sku AS mpn')
            ->where('s.id', $this->selected)->first();
        if (! $r) return null;
        $since = now()->subDays(StockVelocityRecorder::KEEP_DAYS);
        $changes = DB::table('distributor_stock_changes')
            ->where('distributor_code', $r->distributor_code)->where('distributor_variant_no', $r->distributor_variant_no)
            ->where('recorded_at', '>=', $since)->orderBy('recorded_at')->get(['avail', 'recorded_at'])->all();
        $r->chart = $this->spark($changes, (int) $r->avail, $since, 420, 96);
        $r->change_count = count($changes);
        $r->conf = StockVelocityRecorder::confidence($r);
        $r->shops = $r->distributor_catalog_id ? (int) DB::table('tenant_inventory_item_vendors as v')
            ->join('tenant_inventory_items as i', 'i.id', '=', 'v.inventory_item_id')
            ->where('v.distributor_catalog_id', $r->distributor_catalog_id)->distinct()->count('i.tenant_id') : 0;
        return $r;
    }

    /**
     * Step line of stock over the window as an SVG polyline's points. Before
     * the first change in the window the line holds at that change's previous
     * value (or the first value we have).
     */
    private function spark(array $changes, int $current, Carbon $since, int $w, int $h): string
    {
        $now = now();
        $span = max(1, $since->diffInSeconds($now));
        $pts = [];
        if (! $changes) {
            $pts = [[0, $current], [$w, $current]];
        } else {
            $first = $changes[0];
            $pts[] = [0, (int) $first->avail];
            foreach ($changes as $c) {
                $x = round($since->diffInSeconds(Carbon::parse($c->recorded_at, 'UTC')) / $span * $w, 1);
                $x = max(0, min($w, $x));
                if ($pts) $pts[] = [$x, end($pts)[1]];
                $pts[] = [$x, (int) $c->avail];
            }
            $pts[] = [$w, $current];
        }
        $max = max(1, max(array_column($pts, 1)));
        return implode(' ', array_map(fn ($p) => $p[0] . ',' . round($h - 2 - ($p[1] / $max) * ($h - 4), 1), $pts));
    }

    /** Recent sweep runs, newest first (MARKER-VELOCITY-SWEEP). */
    public function runs(): array
    {
        if (! Schema::hasTable('distributor_velocity_runs')) return [];
        return DB::table('distributor_velocity_runs')->orderByDesc('started_at')->limit(12)->get()
            ->map(function ($r) { $r->results = json_decode((string) $r->results, true) ?: []; return $r; })->all();
    }

    /** Data coverage tab. */
    public function coverage(): array
    {
        $today = now()->toDateString();
        $per = DB::table('distributor_stock_summary')->selectRaw('distributor_code,
                COUNT(*) AS items, SUM(rate_per_day IS NOT NULL) AS with_rate,
                SUM(next_arrival_on IS NOT NULL) AS with_arrival, MAX(last_reading_at) AS last_read,
                SUM(baseline_avail IS NOT NULL) AS seeded')
            ->groupBy('distributor_code')->orderBy('distributor_code')->get()->keyBy('distributor_code');
        $changes = DB::table('distributor_stock_changes')->selectRaw('distributor_code, COUNT(*) AS n, MIN(recorded_at) AS oldest')
            ->groupBy('distributor_code')->get()->keyBy('distributor_code');

        $mb = null;
        try {
            $mb = DB::table('information_schema.TABLES')->where('TABLE_SCHEMA', DB::getDatabaseName())
                ->whereIn('TABLE_NAME', ['distributor_stock_summary', 'distributor_stock_changes'])
                ->selectRaw('ROUND(SUM(DATA_LENGTH + INDEX_LENGTH) / 1048576, 1) AS mb')->value('mb');
        } catch (\Throwable $e) {
            // storage figure is informational only
        }

        return ['per' => $per, 'changes' => $changes, 'mb' => $mb, 'today' => $today];
    }
}
