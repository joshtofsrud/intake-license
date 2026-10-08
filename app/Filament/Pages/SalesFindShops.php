<?php

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
    use \Livewire\WithFileUploads;
    protected static ?string $navigationIcon  = 'heroicon-o-magnifying-glass-circle';
    protected static ?string $navigationLabel = 'Find shops';
    protected static ?string $navigationGroup = 'Sales';
    protected static ?int    $navigationSort  = 5;
    protected static string  $view            = 'filament.pages.sales-find-shops';
    protected static ?string $slug            = 'sales-find-shops';
    protected static ?string $title           = 'Find shops';

    public string $industry    = ''; // an Industries id, or 'custom'
    public string $uploadIndustry = '';
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


    // shop list upload
    public $shopList = null;
    public ?array $uploadPreview = null;
    public bool   $uploadAssign  = true;
    public ?array $uploadResult  = null;
    public string $confirmUndo   = '';
    // mapping step
    public array  $uploadHeaders = [];
    public array  $uploadSample  = [];
    public array  $columnMap     = [];
    public ?string $uploadError  = null;
    public bool   $showLoader    = false; // panel open state lives here, not in a <details> the morph can close

    public static function canAccess(): bool
    {
        return AdminAccess::allows(Auth::guard('web')->user(), 'crm');
    }

    public function mount(): void
    {
        abort_unless(static::canAccess(), 403);
        $this->industry = (string) (array_key_first($this->industries()) ?? 'custom');
    }

    /** chips come from the Industries page; active ones first. */
    public function industries(): array
    {
        $out = [];
        foreach (\App\Models\SalesChannel::query()
            ->whereNotNull('places_query')->where('places_query', '!=', '')->where('status', '!=', 'stub')
            ->orderByRaw("CASE WHEN status = 'active' THEN 0 ELSE 1 END")->orderBy('name')->get(['id', 'name']) as $c) {
            $out[$c->id] = ['label' => $c->name];
        }
        $out['custom'] = ['label' => 'Custom…'];
        return $out;
    }

    /** Industries a shop list can be loaded into. */
    public function uploadIndustries(): array
    {
        return \App\Models\SalesChannel::query()->where('status', '!=', 'stub')->orderBy('name')->pluck('name', 'id')->all();
    }
    public function configured(): bool  { return (bool) SalesSetting::placesKey(); }
    public function monthToDateCents(): int { return SalesPlacesSearch::monthToDateCents(); }
    public function estimateCents(): int { return (1 + max(1, (int) ceil($this->radius / 10))) * SalesSetting::placesCostCents(); }
    public function recentSearches() { return SalesPlacesSearch::latest()->limit(6)->get(); }

    // website pass progress and Pause/Resume.
    public function siteScanStats(): array
    {
        $base = \App\Models\SalesProspect::query()->whereNull('tenant_id');
        $site = (clone $base)->whereNotNull('website')->where('website', '!=', '');
        $by = (clone $base)->whereNotNull('site_scanned_at')->selectRaw('site_scan_status s, COUNT(*) n')->groupBy('site_scan_status')->pluck('n', 's')->all();
        return [
            'with_site' => (clone $site)->count(),
            'left'      => (clone $site)->whereNull('site_scanned_at')->count(),
            'read'      => array_sum($by),
            'by'        => $by,
            'emails'    => (clone $base)->whereNotNull('site_scanned_at')->whereNotNull('email')->where('email', '!=', '')->count(),
            'socials'   => (clone $base)->whereNotNull('socials')->count(),
            'brands'    => (clone $base)->whereNotNull('brands')->count(),
            'last'      => (clone $base)->max('site_scanned_at'),
            'paused'    => \App\Models\SalesSetting::get('site_scan_paused') === '1',
        ];
    }

    public function toggleSiteScan(): void
    {
        $paused = \App\Models\SalesSetting::get('site_scan_paused') === '1';
        \App\Models\SalesSetting::put('site_scan_paused', $paused ? '0' : '1');
        Notification::make()->title($paused ? 'Website pass resumed' : 'Website pass paused')->success()->send();
    }

    public function search(): void
    {
        // the message now shows under Where (it used to fail silently).
        $this->validate(
            ['place' => ['required', 'string', 'max:191'], 'radius' => ['integer', 'min:5', 'max:30']],
            ['place.required' => 'Enter a city or address to search.']
        );
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
            $p = $finder->add($r, $this->autoAssign, $this->place, $by, $this->industry !== 'custom' ? $this->industry : null);
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

    // ----------------------------------------------------------------
    public function updatedShopList(): void
    {
        $this->uploadPreview = null; $this->uploadResult = null; $this->uploadError = null;
        $this->uploadHeaders = []; $this->uploadSample = []; $this->columnMap = [];
        $this->showLoader = true;
        if (! $this->shopList) return;
        try {
            $this->validate(['shopList' => ['file', 'max:51200']]);
            $i = (new \App\Services\Sales\ShopListImporter())->inspect($this->shopList->getRealPath());
            if ($i['error']) { $this->uploadError = $i['error']; return; }
            $this->uploadHeaders = $i['headers']; $this->uploadSample = $i['sample'];
            $this->columnMap = \App\Services\Sales\ShopListImporter::guessMap($i['headers']);
        } catch (\Illuminate\Validation\ValidationException $e) {
            $this->uploadError = implode(' ', $e->validator->errors()->all());
        } catch (\Throwable $e) {
            $this->uploadError = 'Could not read the file: ' . $e->getMessage();
        }
    }

    public function fields(): array { return \App\Services\Sales\ShopListImporter::FIELDS; }

    public function mapProblem(): ?string
    {
        if (! $this->uploadHeaders) return null;
        if (empty($this->columnMap['shop']))  return 'Pick the column that holds the shop name.';
        if (empty($this->columnMap['state'])) return 'Pick the column that holds the state.';
        return null;
    }

    public function previewUpload(): void
    {
        $this->validate(['shopList' => ['required', 'file', 'max:51200']]); // 50 MB
        $this->uploadResult = null;
        if ($this->mapProblem()) { Notification::make()->title($this->mapProblem())->warning()->send(); return; }
        $this->uploadPreview = (new \App\Services\Sales\ShopListImporter())->import($this->shopList->getRealPath(), null, $this->uploadAssign, true, null, $this->columnMap, $this->uploadIndustry ?: null);
        if ($this->uploadPreview['error']) Notification::make()->title($this->uploadPreview['error'])->danger()->send();
    }

    public function importUpload(): void
    {
        $this->validate(['shopList' => ['required', 'file', 'max:51200']]);
        if ($this->mapProblem()) { Notification::make()->title($this->mapProblem())->warning()->send(); return; }
        $r = (new \App\Services\Sales\ShopListImporter())->import($this->shopList->getRealPath(), null, $this->uploadAssign, false, null, $this->columnMap, $this->uploadIndustry ?: null);
        try { $this->shopList->delete(); } catch (\Throwable $e) {}
        $this->shopList = null; $this->uploadPreview = null; $this->uploadResult = $r; $this->uploadHeaders = []; $this->uploadSample = []; $this->columnMap = [];
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
