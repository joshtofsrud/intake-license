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
        // MARKER-COVERAGE: links from Coverage carry a state and maybe an industry
        $qs = strtoupper((string) request()->query('state', ''));
        if (isset(\App\Services\Sales\TownCoverage::STATES[$qs])) $this->townState = $qs;
        $qi = (string) request()->query('industry', '');
        if ($qi !== '' && isset($this->industries()[$qi])) $this->industry = $qi;
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
            fn () => count((new \App\Services\Sales\DuplicateShops())->groups(false))); // MARKER-DUPES-LEAN
    }

    /** Sets with their rows, for the review list (first 100). */
    public function duplicateSets(): array
    {
        $svc = new \App\Services\Sales\DuplicateShops();
        $sets = array_slice($svc->groups(false), 0, 100);
        foreach ($sets as &$s0) $s0['keeper'] = $svc->keeperOf($s0['ids']); // only the 100 shown
        unset($s0);
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
        foreach ($svc->groups(false) as $set) { $svc->merge($set['ids']); $n++; } // merge picks the keeper itself
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
        // untouched towns first; partly covered ones (a full search) after those
        foreach (['open', 'part'] as $want) {
            foreach ($this->townData()['rows'] as $r) {
                if ($r['status'] === $want) {
                    if ($want === 'part') $this->radius = max(5, min($this->radius, 10));
                    $this->pickTown($r['name']);
                    return;
                }
            }
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

    // ---- MARKER-BG-IMPORT: big files come in through the piece uploader, then load on the server
    public string $bigFile = '';   // upload id of a file that arrived in pieces
    public string $bigName = '';

    /** The CSV being mapped / previewed: an uploaded-in-pieces file, or a classic upload. */
    public function listPath(): ?string
    {
        if ($this->bigFile !== '' && preg_match('/^[a-f0-9]{24}$/', $this->bigFile)) {
            $p = storage_path('app/shop-imports/up-' . $this->bigFile . '.csv');
            return is_file($p) ? $p : null;
        }
        return $this->shopList ? $this->shopList->getRealPath() : null;
    }

    public function useBigFile(string $id, string $name): void
    {
        $this->shopList = null; $this->bigFile = ''; $this->bigName = '';
        $this->uploadPreview = null; $this->uploadResult = null; $this->uploadError = null;
        $this->uploadHeaders = []; $this->uploadSample = []; $this->columnMap = [];
        $this->showLoader = true;
        if (! preg_match('/^[a-f0-9]{24}$/', $id)) { $this->uploadError = 'That upload could not be found. Pick the file again.'; return; }
        $this->bigFile = $id; $this->bigName = mb_substr($name, 0, 191);
        if (! $this->listPath()) { $this->bigFile = ''; $this->uploadError = 'That upload could not be found. Pick the file again.'; return; }
        try {
            $i = (new \App\Services\Sales\ShopListImporter())->inspect($this->listPath());
            if ($i['error']) { $this->uploadError = $i['error']; return; }
            $this->uploadHeaders = $i['headers']; $this->uploadSample = $i['sample'];
            $this->columnMap = \App\Services\Sales\ShopListImporter::guessMap($i['headers']);
        } catch (\Throwable $e) {
            $this->uploadError = 'Could not read the file: ' . $e->getMessage();
        }
    }

    /** Lists loading now, or finished in the last day. */
    public function importRuns(): array
    {
        if (! \Illuminate\Support\Facades\Schema::hasTable('sales_import_runs')) return [];
        return \Illuminate\Support\Facades\DB::table('sales_import_runs')
            ->where(fn ($w) => $w->whereIn('status', ['queued', 'running'])->orWhere('updated_at', '>=', now()->subDay()))
            ->orderByDesc('id')->limit(5)->get()->map(fn ($r) => (array) $r)->all();
    }

    public function dismissRun(int $id): void
    {
        \Illuminate\Support\Facades\DB::table('sales_import_runs')->where('id', $id)->whereIn('status', ['done', 'failed'])->update(['updated_at' => now()->subDays(2)]);
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
        if (! $this->listPath()) { Notification::make()->title('Pick a file first.')->warning()->send(); return; } // MARKER-BG-IMPORT
        @set_time_limit(300);
        $this->uploadResult = null;
        if ($this->mapProblem()) { Notification::make()->title($this->mapProblem())->warning()->send(); return; }
        $this->uploadPreview = (new \App\Services\Sales\ShopListImporter())->import($this->listPath(), null, $this->uploadAssign, true, null, $this->columnMap, $this->uploadIndustry ?: null);
        if ($this->uploadPreview['error']) Notification::make()->title($this->uploadPreview['error'])->danger()->send();
    }

    // MARKER-FAST-IMPORT + MARKER-BG-IMPORT: Load hands the file to the server, which loads it
    // 1,000 rows at a time every minute (sales:run-imports); the page can be closed meanwhile.
    public ?array $importRun = null; // no longer used; kept so an open page from before the update doesn't error

    public function importUpload(): void
    {
        $src = $this->listPath();
        if (! $src) { Notification::make()->title('Pick a file first.')->warning()->send(); return; }
        if ($this->mapProblem()) { Notification::make()->title($this->mapProblem())->warning()->send(); return; }
        $batch = 'list-' . admin_now()->format('Ymd-Hi');
        $dir = storage_path('app/shop-imports');
        if (! is_dir($dir)) @mkdir($dir, 0775, true);
        $path = $dir . '/' . $batch . '-' . \Illuminate\Support\Str::random(6) . '.csv';
        $moved = $this->bigFile !== '' ? @rename($src, $path) : @copy($src, $path);
        if (! $moved) { Notification::make()->title('Could not keep the file for loading. Try again.')->danger()->send(); return; }
        if ($this->shopList) { try { $this->shopList->delete(); } catch (\Throwable $e) {} }
        $id = \Illuminate\Support\Facades\DB::table('sales_import_runs')->insertGetId([
            'user_id' => Auth::id(), 'batch' => $batch, 'file_name' => $this->bigName ?: ($this->shopList ? mb_substr($this->shopList->getClientOriginalName(), 0, 191) : null),
            'path' => $path, 'size' => max(1, (int) filesize($path)), 'map' => json_encode($this->columnMap), 'assign' => $this->uploadAssign,
            'channel_id' => $this->uploadIndustry ?: null, 'expect' => (int) ($this->uploadPreview['total'] ?? 0), 'status' => 'queued',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->shopList = null; $this->bigFile = ''; $this->bigName = ''; $this->uploadPreview = null; $this->uploadResult = null;
        $this->uploadHeaders = []; $this->uploadSample = []; $this->columnMap = [];
        \App\Services\Sales\ShopListRuns::work((int) $id, 15); // first rows now; the server carries on every minute
        Notification::make()->title('Loading started')->body('You can leave this page; it keeps loading on the server.')->success()->send();
    }

    /** An open page from before the update may still call this; loading now happens on the server. */
    public function importStep(): void
    {
        $this->importRun = null;
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
