<?php
// MARKER-SALES-ROUTE

namespace App\Filament\Pages;

use App\Models\SalesProspect;
use App\Models\SalesRep;
use App\Models\SalesSetting;
use App\Services\Sales\PlacesClient;
use App\Services\Sales\ShopFinder;
use App\Support\AdminAccess;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Support\Facades\Auth;

/**
 * Route day — the prospects due today (plus nearby A's if wanted), ordered
 * into a drive from a start point. Coordinates come from Places search or a
 * Pull details; shops without them are listed separately, never silently lost.
 */
class SalesRouteDay extends Page
{
    use \App\Support\UsesAdminNav;
    protected static ?string $navigationIcon  = 'heroicon-o-truck';
    protected static ?string $navigationLabel = 'Route day';
    protected static ?string $navigationGroup = 'Sales';
    protected static ?int    $navigationSort  = 9;
    protected static string  $view            = 'filament.pages.sales-route-day';
    protected static ?string $slug            = 'sales-route-day';
    protected static ?string $title           = 'Route day';

    public const MAX_STOPS = 12;

    public string $startLabel = '';
    public ?float $startLat = null;
    public ?float $startLng = null;
    public string $repId = '';
    public bool   $includeOverdue = true;
    public bool   $includeNearbyA = false;
    public int    $nearbyMiles = 15;
    public int    $maxStops = 8;

    /** @var array<int,string> ordered prospect ids */
    public array $route = [];
    public array $done  = [];

    public static function canAccess(): bool
    {
        return AdminAccess::allows(Auth::guard('web')->user(), 'crm');
    }

    public function mount(): void
    {
        abort_unless(static::canAccess(), 403);
        $this->startLabel = (string) SalesSetting::get('route_start_label', '');
        $this->startLat   = SalesSetting::get('route_start_lat') !== null ? (float) SalesSetting::get('route_start_lat') : null;
        $this->startLng   = SalesSetting::get('route_start_lng') !== null ? (float) SalesSetting::get('route_start_lng') : null;
    }

    public function reps() { return SalesRep::query()->with('agency')->where('status', 'active')->orderBy('name')->get(); }

    /** Candidates before ordering. */
    public function candidates()
    {
        $q = SalesProspect::query()->open()->with('rep')
            ->when($this->repId, fn ($q) => $q->where('sales_rep_id', $this->repId));
        $due = (clone $q)->whereNotNull('next_action_on')
            ->when($this->includeOverdue, fn ($q) => $q->whereDate('next_action_on', '<=', now()), fn ($q) => $q->whereDate('next_action_on', now()))
            ->orderBy('next_action_on')->get();
        if ($this->includeNearbyA && $this->startLat !== null) {
            $near = (clone $q)->where('priority', 'A')->whereNotNull('lat')
                ->where(fn ($w) => $w->whereNull('next_action_on')->orWhereDate('next_action_on', '>', now()))
                ->get()->filter(fn ($p) => ShopFinder::miles($this->startLat, $this->startLng, (float) $p->lat, (float) $p->lng) <= $this->nearbyMiles);
            $due = $due->concat($near)->unique('id');
        }
        return $due;
    }

    public function setStart(): void
    {
        $this->validate(['startLabel' => ['required', 'string', 'max:191']]);
        try {
            $c = new PlacesClient();
            if (! $c->configured()) {
                Notification::make()->title('Add a Google Places key on Find shops to place the start point')->warning()->send(); return;
            }
            $loc = $c->locate($this->startLabel);
            if (! $loc) { Notification::make()->title("Couldn't find that address")->warning()->send(); return; }
            $this->startLat = $loc['lat']; $this->startLng = $loc['lng']; $this->startLabel = $loc['label'];
            SalesSetting::put('route_start_label', $this->startLabel);
            SalesSetting::put('route_start_lat', (string) $this->startLat);
            SalesSetting::put('route_start_lng', (string) $this->startLng);
            Notification::make()->title('Start point saved')->success()->send();
        } catch (\Throwable $e) {
            Notification::make()->title('Places failed')->body($e->getMessage())->danger()->send();
        }
    }

    public function build(): void
    {
        if ($this->startLat === null) { Notification::make()->title('Set a start point first')->warning()->send(); return; }
        $pool = $this->candidates()->filter(fn ($p) => $p->lat !== null && $p->lng !== null)->values()->all();
        $route = []; $cur = [$this->startLat, $this->startLng];
        while ($pool && count($route) < min($this->maxStops, self::MAX_STOPS)) {
            usort($pool, fn ($a, $b) => ShopFinder::miles($cur[0], $cur[1], (float) $a->lat, (float) $a->lng) <=> ShopFinder::miles($cur[0], $cur[1], (float) $b->lat, (float) $b->lng));
            $n = array_shift($pool); $route[] = $n->id; $cur = [(float) $n->lat, (float) $n->lng];
        }
        $this->route = $route; $this->done = [];
        $this->dispatch('route-updated');
        Notification::make()->title(count($route) . ' stops')->body(count($route) ? 'About ' . $this->totalMiles() . ' miles of driving.' : 'Nothing due with coordinates.')->success()->send();
    }

    /** @return \Illuminate\Support\Collection<int,SalesProspect> in route order */
    public function stops()
    {
        if (! $this->route) return collect();
        $by = SalesProspect::query()->with('rep')->whereIn('id', $this->route)->get()->keyBy('id');
        return collect($this->route)->map(fn ($id) => $by[$id] ?? null)->filter()->values();
    }

    public function unplaced()
    {
        return $this->candidates()->filter(fn ($p) => $p->lat === null || $p->lng === null)->values();
    }

    public function totalMiles(): int
    {
        $m = 0; $cur = [$this->startLat, $this->startLng];
        foreach ($this->stops() as $p) { $m += ShopFinder::miles($cur[0], $cur[1], (float) $p->lat, (float) $p->lng); $cur = [(float) $p->lat, (float) $p->lng]; }
        return (int) round($m * 1.3); // straight-line → road factor
    }

    /** Google Maps directions link: start → each stop in order. */
    public function mapsUrl(): ?string
    {
        $s = $this->stops(); if ($s->isEmpty() || $this->startLat === null) return null;
        $pts = $s->map(fn ($p) => $p->lat . ',' . $p->lng)->all();
        $dest = array_pop($pts);
        return 'https://www.google.com/maps/dir/?api=1&origin=' . $this->startLat . ',' . $this->startLng . '&destination=' . $dest . ($pts ? '&waypoints=' . implode('|', $pts) : '') . '&travelmode=driving';
    }

    public function logVisit(string $id): void
    {
        $p = SalesProspect::find($id); if (! $p) return;
        $p->activities()->create(['type' => 'follow_up', 'body' => 'Visit · logged from Route day by ' . (Auth::user()?->name ?? 'staff')]);
        $ch = ['last_contacted_at' => now(), 'next_action_on' => null, 'next_action' => null];
        if (in_array($p->stage, ['prospect', 'verifying'], true)) $ch['stage'] = 'contacted';
        $p->update($ch);
        $this->done[] = $id;
        Notification::make()->title('Visit logged · ' . $p->shop)->body('Set the next follow-up from the pipeline drawer.')->success()->send();
    }

    public function placeOne(string $id): void
    {
        $p = SalesProspect::find($id); if (! $p) return;
        try {
            $r = \App\Services\Sales\ProspectEnricher::enrich($p);
            Notification::make()->title($p->shop . ' · ' . $r)->success()->send();
        } catch (\Throwable $e) {
            Notification::make()->title('Places failed')->body($e->getMessage())->danger()->send();
        }
    }

    public function pipelineUrl(string $id): string
    {
        return \App\Filament\Pages\SalesPipeline::getUrl() . '?open=' . $id;
    }
}
