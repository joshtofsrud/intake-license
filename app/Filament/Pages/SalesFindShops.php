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
    public string $customName  = ''; // MARKER-CUSTOM-INDUSTRY: the industry a Custom search is saved as
    public string $customChannelId = '';
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
        $this->townState = $this->findPref('state', 'WA');
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
            'paused'    => \App\Models\SalesSetting::get('site_scan_on') !== '1',
            // MARKER-DEEP-PASS
            'mode'       => \App\Models\SalesSetting::get('site_scan_mode', 'standard') === 'deep' ? 'deep' : 'standard',
            'deep_left'  => \App\Console\Commands\ScanProspectSites::deepQueue()->reorder()->count(),
            'deep_read'  => (clone $base)->whereNotNull('deep_scanned_at')->where('deep_scan_status', '!=', 'skipped')->count(),
            'deep_found' => (clone $base)->where('deep_scan_status', 'found')->count(),
        ];
    }

    public function setScanSpeed(string $speed): void
    {
        if (! isset(\App\Console\Commands\ScanProspectSites::SPEEDS[$speed])) return;
        \App\Models\SalesSetting::put('site_scan_speed', $speed);
        Notification::make()->title('Website pass speed: ' . \App\Console\Commands\ScanProspectSites::SPEEDS[$speed] . ' shops every five minutes'
            . (\App\Models\SalesSetting::get('site_scan_mode') === 'deep' ? ' (' . \App\Console\Commands\ScanProspectSites::DEEP_SPEEDS[$speed] . ' in the deep pass)' : ''))->success()->send();
    }

    // MARKER-DEEP-PASS: Standard reads each new site once; Deep then goes back over the gaps.
    public function setScanMode(string $mode): void
    {
        if (! in_array($mode, ['standard', 'deep'], true)) return;
        \App\Models\SalesSetting::put('site_scan_mode', $mode);
        Notification::make()->title($mode === 'deep'
            ? 'Deep pass on: once new sites are read, it goes back over shops still missing an email or socials'
            : 'Standard pass: each new site is read once')->success()->send();
    }

    public function toggleSiteScan(): void
    {
        $on = \App\Models\SalesSetting::get('site_scan_on') === '1';
        \App\Models\SalesSetting::put('site_scan_on', $on ? '0' : '1');
        Notification::make()->title($on ? 'Website pass stopped' : 'Website pass started; the first shops are read within five minutes')->success()->send();
    }

    // Duplicate shops — the same shop entered twice (same phone, or same website at the same place).
    public bool $showDupes = false;
    public bool $confirmMergeAll = false;

    public function duplicateCount(): int
    {
        return (int) \Illuminate\Support\Facades\Cache::remember('sales:duplicate-shops:count', 300,
            fn () => count((new \App\Services\Sales\DuplicateShops())->groups()));
    }

    /** Sets with their rows, for the review list (first 100). */
    public function duplicateSets(): array
    {
        $sets = array_slice((new \App\Services\Sales\DuplicateShops())->groups(), 0, 100);
        $rows = \App\Models\SalesProspect::whereIn('id', collect($sets)->pluck('ids')->flatten()->all())
            ->get(['id', 'shop', 'city', 'state', 'postcode', 'phone', 'website', 'email', 'stage'])->keyBy('id');
        foreach ($sets as &$s) {
            $s['rows'] = collect($s['ids'])->map(fn ($id) => $rows[$id] ?? null)->filter()
                ->sortBy(fn ($r) => $r->id === $s['keeper'] ? 0 : 1)->values()->all();
        }
        return $sets;
    }

    public function mergeSet(string $ids): void
    {
        $keeper = (new \App\Services\Sales\DuplicateShops())->merge(array_filter(explode(',', $ids)));
        \Illuminate\Support\Facades\Cache::forget('sales:duplicate-shops:count');
        Notification::make()->title('Merged into ' . $keeper->shop)->success()->send();
    }

    public function ignoreSet(string $ids): void
    {
        \App\Services\Sales\DuplicateShops::ignore(array_filter(explode(',', $ids)));
        \Illuminate\Support\Facades\Cache::forget('sales:duplicate-shops:count');
        Notification::make()->title('Kept as separate shops')->success()->send();
    }

    public function mergeAll(): void
    {
        $this->confirmMergeAll = false;
        $svc = new \App\Services\Sales\DuplicateShops();
        $n = 0;
        foreach ($svc->groups() as $set) { $svc->merge($set['ids']); $n++; }
        \Illuminate\Support\Facades\Cache::forget('sales:duplicate-shops:count');
        Notification::make()->title("Merged {$n} " . ($n === 1 ? 'set' : 'sets') . ' of duplicates')->success()->send();
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
        // MARKER-CUSTOM-INDUSTRY: a Custom search becomes (or joins) an industry on
        // Sales setup > Industries, so its shops land in a group everywhere.
        $this->customChannelId = '';
        if ($this->industry === 'custom') {
            $phrase = trim($this->customQuery);
            if ($phrase === '') { $this->error = 'Type what to search for.'; return; }
            $name = trim($this->customName) !== '' ? trim($this->customName) : \Illuminate\Support\Str::ucfirst($phrase);
            $c = \App\Models\SalesChannel::whereRaw('LOWER(name) = ?', [mb_strtolower($name)])->first()
                ?? \App\Models\SalesChannel::where('slug', \Illuminate\Support\Str::slug($name))->first();
            if (! $c) {
                $c = \App\Models\SalesChannel::create(['name' => mb_substr($name, 0, 120), 'status' => 'draft',
                    'places_query' => mb_substr($phrase, 0, 120), 'playbook' => \App\Models\SalesChannel::DEFAULT_PLAYBOOK]);
                Notification::make()->title('New industry "' . $c->name . '" added')->body('It is on Sales setup › Industries with the search phrase "' . $phrase . '".')->success()->send();
            } else {
                $fix = [];
                if (blank($c->places_query)) $fix['places_query'] = mb_substr($phrase, 0, 120);
                if ($c->status === 'stub') $fix['status'] = 'draft';
                if ($fix) $c->update($fix);
            }
            $this->customChannelId = $c->id;
        }
        $out = (new ShopFinder())->search($this->industry, $this->customQuery, $this->place, $this->radius, Auth::id(), $this->customChannelId ?: null);
        if ($this->customChannelId && ! $out['error']) {
            $c = \App\Models\SalesChannel::find($this->customChannelId);
            // same phrase as the industry's own: just select its chip from now on
            if ($c && mb_strtolower(trim((string) $c->places_query)) === mb_strtolower(trim($this->customQuery))) {
                $this->industry = $c->id; $this->customQuery = ''; $this->customName = '';
            }
        }
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
            $p = $finder->add($r, $this->autoAssign, $this->place, $by, $this->industry !== 'custom' ? $this->industry : ($this->customChannelId ?: null));
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
    // ---- MARKER-TOWN-LIST: towns in a state, marked covered or not for this industry
    public string  $townState  = 'WA';
    public string  $townFilter = 'open';
    public ?string $townPick   = null;
    private ?array $townCache  = null;

    public function townData(): array
    {
        if (! isset(\App\Services\Sales\TownCoverage::STATES[$this->townState])) $this->townState = 'WA';
        return $this->townCache ??= \App\Services\Sales\TownCoverage::forState($this->townState, $this->industry === 'custom' ? ($this->customChannelId ?: null) : $this->industry);
    }

    public function updatedTownState(): void
    {
        $this->townPick = null;
        $this->saveFindPref('state', $this->townState);
        $this->dispatch('places-updated');
    }

    public function updatedIndustry(): void { $this->dispatch('places-updated'); }

    public function pickTown(string $name): void
    {
        $this->townPick = $name;
        $this->place = $name . ', ' . $this->townState;
        $this->resetErrorBag('place');
        $this->dispatch('places-updated');
    }

    public function nextTown(): void
    {
        foreach ($this->townData()['rows'] as $r) {
            if ($r['status'] === 'open') { $this->pickTown($r['name']); return; }
        }
        Notification::make()->title('Every town in ' . \App\Services\Sales\TownCoverage::STATES[$this->townState] . ' is covered for this industry')->success()->send();
    }

    /** Find shops choices remembered per admin (users.admin_prefs.find). */
    private function findPref(string $key, string $default): string
    {
        $u = Auth::guard('web')->user();
        if (! $u) return $default;
        try { $all = json_decode((string) \Illuminate\Support\Facades\DB::table('users')->where('id', $u->id)->value('admin_prefs'), true); } catch (\Throwable $e) { return $default; }
        $v = $all['find'][$key] ?? null;
        return is_string($v) && $v !== '' ? $v : $default;
    }

    private function saveFindPref(string $key, string $value): void
    {
        $u = Auth::guard('web')->user();
        if (! $u) return;
        try {
            $all = json_decode((string) \Illuminate\Support\Facades\DB::table('users')->where('id', $u->id)->value('admin_prefs'), true);
            $all = is_array($all) ? $all : [];
            $all['find'] = array_merge(is_array($all['find'] ?? null) ? $all['find'] : [], [$key => $value]);
            \Illuminate\Support\Facades\DB::table('users')->where('id', $u->id)->update(['admin_prefs' => json_encode($all)]);
        } catch (\Throwable $e) { report($e); }
    }

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
