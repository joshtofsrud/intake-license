<?php
// MARKER-SALES-FIND

namespace App\Filament\Pages;

use App\Models\SalesPlacesSearch;
use App\Models\SalesSetting;
use App\Services\Sales\PlacesClient;
use App\Services\Sales\ShopFinder;
use App\Support\AdminAccess;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Support\Facades\Auth;

/**
 * Find shops — search Google Places from inside master admin, see which results
 * are already prospects or tenants, add the rest, and let territory rules
 * assign them to a rep. No CSV.
 */
class SalesFindShops extends Page
{
    use \App\Support\UsesAdminNav;
    use \Livewire\WithFileUploads; // MARKER-SALES-UPLOAD
    protected static ?string $navigationIcon  = 'heroicon-o-magnifying-glass-circle';
    protected static ?string $navigationLabel = 'Find shops';
    protected static ?string $navigationGroup = 'Sales';
    protected static ?int    $navigationSort  = 5;
    protected static string  $view            = 'filament.pages.sales-find-shops';
    protected static ?string $slug            = 'sales-find-shops';
    protected static ?string $title           = 'Find shops';

    public string $industry    = 'bicycle_store';
    public string $customQuery = '';
    public string $place       = '';
    public int    $radius      = 25;
    public bool   $autoAssign  = true;
    public bool   $hideKnown   = false;

    public array  $results  = [];
    public array  $selected = [];
    public ?string $located = null;
    public ?string $error   = null;
    public int    $lastCostCents = 0;

    // setup card
    public string $placesKey    = '';
    public int    $budgetDollars = 50;

    // MARKER-SALES-UPLOAD — shop list upload
    public $shopList = null;
    public ?array $uploadPreview = null;
    public bool   $uploadAssign  = true;
    public ?array $uploadResult  = null;
    public string $confirmUndo   = '';

    public static function canAccess(): bool
    {
        return AdminAccess::allows(Auth::guard('web')->user(), 'crm');
    }

    public function mount(): void
    {
        abort_unless(static::canAccess(), 403);
        $this->budgetDollars = (int) round(SalesSetting::placesBudgetCents() / 100);
    }

    public function industries(): array { return ShopFinder::INDUSTRIES; }
    public function configured(): bool  { return (bool) SalesSetting::placesKey(); }
    public function monthToDateCents(): int { return SalesPlacesSearch::monthToDateCents(); }
    public function estimateCents(): int { return (1 + max(1, (int) ceil($this->radius / 10))) * SalesSetting::placesCostCents(); }
    public function recentSearches() { return SalesPlacesSearch::latest()->limit(6)->get(); }

    public function search(): void
    {
        $this->validate(['place' => ['required', 'string', 'max:191'], 'radius' => ['integer', 'min:5', 'max:30']]);
        $this->error = null; $this->selected = []; $this->results = [];

        if ($this->monthToDateCents() >= SalesSetting::placesBudgetCents()) {
            $this->error = 'Monthly Places budget reached. Raise it below to keep searching.';
            return;
        }
        $out = (new ShopFinder())->search($this->industry, $this->customQuery, $this->place, $this->radius, Auth::id());
        $this->error   = $out['error'];
        $this->located = $out['located'];
        $this->results = $out['results'];
        $this->lastCostCents = $out['cost_cents'];
        $this->dispatch('places-updated');

        if (! $this->error) {
            $n = count(array_filter($this->results, fn ($r) => $r['status'] === 'new'));
            Notification::make()->title(count($this->results) . ' shops found')
                ->body("$n new · " . (count($this->results) - $n) . ' already known · ' . $this->money($out['cost_cents']))
                ->success()->send();
        }
    }

    public function toggle(string $placeId): void
    {
        $r = $this->row($placeId);
        if (! $r || $r['status'] !== 'new') return;
        if (in_array($placeId, $this->selected, true)) {
            $this->selected = array_values(array_diff($this->selected, [$placeId]));
        } else {
            $this->selected[] = $placeId;
        }
        $this->dispatch('places-updated');
    }

    public function selectAllNew(): void
    {
        $this->selected = array_values(array_map(fn ($r) => $r['place_id'], array_filter($this->results, fn ($r) => $r['status'] === 'new')));
        $this->dispatch('places-updated');
    }

    public function addOne(string $placeId): void { $this->addMany([$placeId]); }
    public function addSelected(): void { $this->addMany($this->selected); }

    private function addMany(array $ids): void
    {
        $finder = new ShopFinder();
        $by = Auth::user()?->name;
        $n = 0;
        foreach ($this->results as &$r) {
            if (! in_array($r['place_id'], $ids, true) || $r['status'] !== 'new') continue;
            $p = $finder->add($r, $this->autoAssign, $this->place, $by);
            $r['status'] = 'prospect'; $r['prospect_id'] = $p->id; $r['stage'] = $p->stage;
            $n++;
        }
        unset($r);
        $this->selected = [];
        $this->dispatch('places-updated');
        Notification::make()->title("$n added to prospects")
            ->body($this->autoAssign ? 'Assigned by territory where a rule matched.' : 'Left unassigned.')
            ->success()->send();
    }

    public function saveSetup(): void
    {
        $this->validate(['placesKey' => ['nullable', 'string', 'max:200'], 'budgetDollars' => ['integer', 'min:0', 'max:100000']]);
        $typed = trim($this->placesKey);
        if ($typed !== '') { SalesSetting::putPlacesKey($typed); $this->placesKey = ''; }
        SalesSetting::put('places_budget_cents', (string) ($this->budgetDollars * 100));
        Notification::make()->title('Setup saved')->body($typed !== '' ? 'Key stored, encrypted.' : 'Key left unchanged.')->success()->send();
    }

    public function testKey(): void
    {
        try {
            $loc = (new PlacesClient())->locate('Spokane, WA');
            $ok = (bool) $loc;
            Notification::make()->title($ok ? 'Places connected' : 'No result')
                ->body($ok ? 'Located ' . $loc['label'] . ' · that call cost ~$0.00 (location only).' : 'The key answered but returned nothing.')
                ->{$ok ? 'success' : 'warning'}()->send();
        } catch (\Throwable $e) {
            Notification::make()->title('Places failed')->body($e->getMessage())->danger()->send();
        }
    }

    // ---------------------------------------------------------------- MARKER-SALES-UPLOAD
    public function updatedShopList(): void { $this->uploadPreview = null; $this->uploadResult = null; }

    public function previewUpload(): void
    {
        $this->validate(['shopList' => ['required', 'file', 'max:51200']]); // 50 MB
        $this->uploadResult = null;
        $this->uploadPreview = (new \App\Services\Sales\ShopListImporter())->import($this->shopList->getRealPath(), null, $this->uploadAssign, true);
        if ($this->uploadPreview['error']) Notification::make()->title($this->uploadPreview['error'])->danger()->send();
    }

    public function importUpload(): void
    {
        $this->validate(['shopList' => ['required', 'file', 'max:51200']]);
        $r = (new \App\Services\Sales\ShopListImporter())->import($this->shopList->getRealPath(), null, $this->uploadAssign, false);
        try { $this->shopList->delete(); } catch (\Throwable $e) {}
        $this->shopList = null; $this->uploadPreview = null; $this->uploadResult = $r;
        if ($r['error']) { Notification::make()->title($r['error'])->danger()->send(); return; }
        Notification::make()->title($r['inserted'] . ' shops loaded')->body("Batch {$r['batch']} · {$r['matched']} already present · {$r['assigned']} assigned to a territory")->success()->send();
    }

    public function undoBatch(string $batch): void
    {
        $r = (new \App\Services\Sales\ShopListImporter())->undo($batch);
        $this->confirmUndo = '';
        Notification::make()->title("Removed {$r['removed']} of {$r['all']}")->body($r['kept'] ? "{$r['kept']} kept because someone has worked them." : 'Batch fully removed.')->success()->send();
    }

    public function batches(): array { return (new \App\Services\Sales\ShopListImporter())->batches(); }

    private function row(string $placeId): ?array
    {
        foreach ($this->results as $r) if ($r['place_id'] === $placeId) return $r;
        return null;
    }

    public function money(int $cents): string { return '$' . number_format($cents / 100, 2); }

    /** What the list shows after the hide-known toggle. */
    public function visibleResults(): array
    {
        return $this->hideKnown ? array_values(array_filter($this->results, fn ($r) => $r['status'] === 'new')) : $this->results;
    }
}
