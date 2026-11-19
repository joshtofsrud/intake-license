<?php

namespace App\Filament\Pages;

use App\Jobs\SyncDistributorCatalogJob;
use App\Models\Tenant;
use App\Services\Distributors\DistributorRegistry;
use App\Services\Distributors\BrandStockFile;
use App\Support\AdminAccess;
use App\Support\BrandDirect;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Livewire\WithFileUploads;

/**
 * MARKER-BRAND-DIRECT: master admin for brands sold direct to their dealers
 * (Kona first). Where the brand's data comes from, which shops may see it,
 * the brand's stock file (checked against the catalog, then loaded for every shop), the clean-up rules,
 * and a preview of the catalog as shops will get it.
 */
class BrandSuppliers extends Page
{
    use \App\Support\UsesAdminNav;
    use \App\Support\GatedByAdminArea;
    use WithFileUploads;

    protected static string $adminArea = 'catalog';

    protected static ?string $navigationIcon  = 'heroicon-o-tag';
    protected static ?string $navigationLabel = 'Brand suppliers';
    protected static ?string $navigationGroup = 'Distribution';
    protected static ?int    $navigationSort  = 12;
    protected static ?string $title           = 'Brand suppliers';
    protected static ?string $slug            = 'brand-suppliers';
    protected static string  $view            = 'filament.pages.brand-suppliers';

    #[\Livewire\Attributes\Url]
    public string $code = '';
    public string $shopSearch = '';
    public string $previewSearch = '';
    public string $oneBike = '';
    /** the catalog row open in the Inspect panel (its part number) */
    public ?string $inspect = null;
    /** the shop the Intake previews are shown as */
    public string $previewShop = '';
    public int $previewPage = 1;
    /** how gently the brand's site is read (Kona: list gap, page gap, pages per run, refresh days) */
    public array $pace = [];
    /** @var \Livewire\Features\SupportFileUploads\TemporaryUploadedFile|null */
    public $stockFile = null;
    public ?array $check = null;
    public string $checkName = '';
    /** MARKER-BRAND-STOCK: the checked file waiting to be loaded (a stored copy), and the brand's FTP folder */
    public string $checkPath = '';
    /** MARKER-BS-TABS: which tab is open, and the preview's filters */
    #[\Livewire\Attributes\Url]
    public string $tab = 'read';
    public string $previewCategory = '';
    public string $previewFilter = '';
    /** MARKER-BRAND-KINDS: the brand's kinds, one per line, in the order they win */
    public string $kindsText = '';
    public bool $ftpOpen = false;
    public array $ftp = ['host' => '', 'user' => '', 'password' => '', 'folder' => ''];

    public function mount(): void
    {
        $codes = $this->codes();
        // a brand named in the address (after Add a brand) opens straight away
        $this->code = in_array(strtoupper($this->code), $codes, true) ? strtoupper($this->code) : ($codes[0] ?? '');
        $this->loadPace();
        $this->loadKinds();
    }

    public function updatedCode(): void
    {
        $this->check = null;
        $this->checkName = '';
        $this->inspect = null;
        $this->previewPage = 1;
        $this->previewCategory = '';
        $this->previewFilter = '';
        $this->ftpOpen = false;
        $this->loadPace();
        $this->loadKinds();
    }

    public function updatedPreviewSearch(): void
    {
        $this->previewPage = 1;
    }

    public function updatedPreviewCategory(): void
    {
        $this->previewPage = 1;
    }

    public function updatedPreviewFilter(): void
    {
        $this->previewPage = 1;
    }

    public function setPreviewFilter(string $f): void
    {
        $this->previewFilter = $this->previewFilter === $f ? '' : $f;
        $this->previewPage = 1;
    }

    /** Handles read with the current reader, or null when the brand's reader can't say. */
    private function currentHandles(): ?array
    {
        $class = $this->adapter();
        return $class && method_exists($class, 'currentHandles') ? $class::currentHandles($this->code) : null;
    }

    /** The header's numbers, shown on every tab. */
    public function headerStats(): array
    {
        $rows = DB::table('platform_distributor_catalogs')->where('distributor_code', $this->code)->whereNull('deleted_at');
        $products = (clone $rows)->distinct()->count('distributor_product_no');
        $cur = $this->currentHandles();
        $ps = $this->pagesStatus();
        $of = max((int) ($ps['of'] ?? 0), $products);
        $file = $this->brandFile();
        $eligible = Tenant::query()->where('is_active', true)->get()->filter(fn (Tenant $t) => (bool) $t->distributor_sync_enabled)->count();
        return [
            'products' => $products, 'parts' => (clone $rows)->count(),
            'read' => $cur !== null ? min(count($cur), $of) : null, 'of' => $of,
            'file' => $file && $file->loaded_at ? $file : null,
            'shops' => $this->onCount(), 'eligible' => $eligible,
        ];
    }

    /** Top-level categories in this brand's catalog ("Bikes" for "Bikes > Gravel"), biggest first. */
    public function previewCategories(): array
    {
        return DB::table('platform_distributor_catalogs')->where('distributor_code', $this->code)->whereNull('deleted_at')
            ->selectRaw("COALESCE(NULLIF(TRIM(SUBSTRING_INDEX(category_path, ' > ', 1)), ''), '(none)') as c, COUNT(*) as n")->groupBy('c')->orderByDesc('n')
            ->pluck('n', 'c')->all();
    }

    private function previewQuery()
    {
        $q = trim($this->previewSearch);
        $query = DB::table('platform_distributor_catalogs as pdc')
            ->where('pdc.distributor_code', $this->code)->whereNull('pdc.deleted_at')
            ->when($q !== '', fn ($w) => $w->where(fn ($x) => $x->where('pdc.distributor_variant_no', 'like', '%' . $q . '%')
                ->orWhere('pdc.display_name', 'like', '%' . $q . '%')->orWhere('pdc.name', 'like', '%' . $q . '%')))
            ->when($this->previewCategory !== '', fn ($w) => $this->previewCategory === '(none)'
                ? $w->where(fn ($x) => $x->whereNull('pdc.category_path')->orWhere('pdc.category_path', ''))
                : $w->where(fn ($x) => $x->where('pdc.category_path', $this->previewCategory)->orWhere('pdc.category_path', 'like', str_replace(['%', '_'], ['\\%', '\\_'], $this->previewCategory) . ' > %')))
            ->when($this->previewFilter === 'nocolor', fn ($w) => $w->where(fn ($x) => $x->whereNull('pdc.color')->orWhere('pdc.color', '')));
        if ($this->previewFilter === 'unread' && ($cur = $this->currentHandles()) !== null) {
            $query->whereNotIn('pdc.distributor_product_no', $cur ?: ['']);
        }
        return $query;
    }

    private function adapter(): ?string
    {
        return app(DistributorRegistry::class)->adapterClass($this->code);
    }

    /** A brand reader's cache key ('progress', 'pages', 'recent', 'stop'), or null when it keeps none. */
    private function readerKey(string $what): ?string
    {
        $class = $this->adapter();
        return $class && method_exists($class, 'key') ? $class::key($what, $this->code) : null;
    }

    private function loadPace(): void
    {
        $class = $this->adapter();
        $this->pace = $class && method_exists($class, 'pace') ? array_map('strval', $class::pace($this->code)) : [];
    }

    private function loadKinds(): void
    {
        $this->kindsText = implode("\n", \App\Support\BrandKinds::for($this->code));
    }

    /** Whether this brand's reader takes its kinds from tags. */
    public function usesKinds(): bool
    {
        $class = $this->adapter();
        return $class && method_exists($class, 'tagCounts');
    }

    /** Tags the brand's products carry that aren't kinds yet, most used first. */
    public function otherTags(): array
    {
        $class = $this->adapter();
        if (! $class || ! method_exists($class, 'tagCounts')) {
            return [];
        }
        $mine = array_map('mb_strtolower', \App\Support\BrandKinds::for($this->code));
        return array_slice(array_filter($class::tagCounts($this->code), fn ($n, $t) => ! in_array(mb_strtolower((string) $t), $mine, true), ARRAY_FILTER_USE_BOTH), 0, 40, true);
    }

    public function saveKinds(): void
    {
        abort_unless($this->canEdit(), 403);
        \App\Support\BrandKinds::set($this->code, preg_split('/\r?\n/', $this->kindsText));
        $this->loadKinds();
        Notification::make()->success()->title('Kinds saved')
            ->body('Every ' . $this->label() . ' product is re-sorted on the next read. Press Read catalog now to apply it straight away; products already read are not fetched again.')->send();
    }

    public function addKind(string $tag): void
    {
        abort_unless($this->canEdit(), 403);
        $this->kindsText = rtrim($this->kindsText) . ($this->kindsText !== '' ? "\n" : '') . $tag;
    }

    public function savePace(): void
    {
        abort_unless($this->canEdit(), 403);
        if (! $this->pace) {
            return;
        }
        $this->validate([
            'pace.page_gap_s'    => ['required', 'integer', 'min:1', 'max:600'],
            'pace.refresh_days'  => ['required', 'integer', 'min:1', 'max:365'],
        ]);
        $ps = \App\Models\PlatformSettings::current();
        $ds = (array) ($ps->distribution_settings ?? []);
        $ds['brand_read'][$this->code] = array_map('intval', $this->pace);
        $ps->distribution_settings = $ds;
        $ps->save();
        \App\Models\PlatformSettings::forget();
        $this->loadPace();
        Notification::make()->success()->title('Saved')->body('Used from the next read of ' . $this->label() . '\'s site.')->send();
    }

    /** How many bikes have a current spec page, and what one run takes. */
    public function pagesStatus(): ?array
    {
        $class = $this->adapter();
        if (! $class || ! ($pk = $this->readerKey('pages'))) {
            return null;
        }
        $st = (array) Cache::get($pk);
        // counted live from what has been read, not left over from the last finished read
        if (method_exists($class, 'currentHandles')) {
            $st['have'] = count($class::currentHandles($this->code));
            $st['of'] = max((int) ($st['of'] ?? 0), (int) $st['have']);
        }
        $gap = (int) ($this->pace['page_gap_s'] ?? 0) + 4; // the gap, plus the bike's two requests and the pause between them
        $left = isset($st['of'], $st['have']) ? max(0, $st['of'] - $st['have']) : null;
        return [
            'have' => $st['have'] ?? null, 'of' => $st['of'] ?? null,
            'left_minutes' => $left !== null ? (int) ceil($left * $gap / 60) : null,
        ];
    }

    public function canEdit(): bool
    {
        return AdminAccess::level(Auth::guard('web')->user(), 'catalog') === 'full';
    }

    /** @return string[] brand-direct codes the registry supports */
    public function codes(): array
    {
        $supported = app(DistributorRegistry::class)->supported();
        return array_values(array_filter(BrandDirect::codes(), fn ($c) => in_array($c, $supported, true)));
    }

    /** One row per brand supplier, for the list at the top. */
    public function brandRows(): array
    {
        $out = [];
        foreach ($this->codes() as $c) {
            $synced = DB::table('distributor_sync_state')->where('distributor_code', $c)
                ->where('source_ref', 'catalog')->value('last_synced_at');
            $out[] = [
                'code'   => $c,
                'label'  => $this->label($c),
                'count'  => DB::table('platform_distributor_catalogs')->where('distributor_code', $c)->whereNull('deleted_at')->count(),
                'shops'  => count(BrandDirect::shopsFor($c)),
                'synced' => $synced ? \Carbon\Carbon::parse($synced, 'UTC')->diffForHumans() : null,
                'shop_ready' => BrandDirect::shopSideReady($c),
            ];
        }
        return $out;
    }

    public function label(?string $code = null): string
    {
        return app(DistributorRegistry::class)->label($code ?: $this->code);
    }

    // ------------------------------------------------------------ catalog

    /** Where the catalog read stands, for the Sources row and its live line. */
    public function catalog(): array
    {
        $code = $this->code;
        $state = DB::table('distributor_sync_state')
            ->where('distributor_code', $code)->where('source_ref', 'catalog')->first();
        $rows = DB::table('platform_distributor_catalogs')->where('distributor_code', $code)->whereNull('deleted_at');
        $count = (clone $rows)->count();
        $products = (clone $rows)->distinct()->count('distributor_product_no');

        $queuedAt = Cache::get('brand_suppliers.queued.' . $code);
        $class = app(DistributorRegistry::class)->adapterClass($code);
        $prog = ($pk = $this->readerKey('progress')) ? Cache::get($pk) : null;

        $lastRun = $state?->last_run_at ? \Carbon\Carbon::parse($state->last_run_at, 'UTC') : null;
        // the run log is the truth: a read marks itself running when it
        // starts and records ok/partial/failed when it ends, however it ends.
        // The progress note only says what a running read is doing; it is
        // left behind when a read fails, so it must never decide "running".
        // 45 minutes covers the longest read (every product page); a worker
        // killed mid-read leaves "running", which this lets go of.
        $running = $state?->last_status === 'running' && $lastRun && $lastRun->gt(now()->subMinutes(45));
        // a press that no worker picked up in 10 minutes is not shown as waiting forever
        $queued = ! $running && $queuedAt && $queuedAt > now()->subMinutes(10)->timestamp
            && (! $lastRun || $lastRun->timestamp < $queuedAt);

        // what the read is doing right now
        $line = null;
        $pct = null;
        if ($running) {
            $phase = $prog['phase'] ?? '';
            if ($state?->last_status === 'running' && ($phase === 'done' || $phase === '' || ($prog['at'] ?? 0) < ($lastRun?->timestamp ?? 0) - 5)) {
                $line = 'Writing to the catalog · ' . number_format((int) $state->last_count) . ' part numbers so far';
                $pct = $count > 0 ? min(99, (int) round(100 * $state->last_count / max($count, 1))) : null;
            } elseif ($phase === 'wait') {
                $line = $this->label() . '\'s site asked us to slow down · waiting ' . (int) $prog['wait'] . ' seconds, then trying again (' . (int) $prog['attempt'] . ' of ' . (int) $prog['of'] . ')';
                $pct = null;
            } elseif ($phase === 'sitemap') {
                $line = 'Reading ' . $this->label() . '\'s product list';
                $pct = 2;
            } elseif ($phase === 'bikes') {
                $gap = (int) ($this->pace['page_gap_s'] ?? 0);
                $line = 'Reading products one at a time · ' . number_format((int) $prog['done']) . ' of ' . number_format((int) $prog['total']) . ' due'
                    . ' · ' . $gap . ' ' . ($gap === 1 ? 'second' : 'seconds') . ' apart';
                $pct = $prog['total'] ? (int) round(3 + 72 * $prog['done'] / max(1, $prog['total'])) : 3;
            } else {
                $line = 'Working…';
            }
        }

        $errors = [];
        if ($state?->last_error) {
            $e = json_decode((string) $state->last_error, true);
            $errors = is_array($e) ? $e : [(string) $state->last_error];
        }

        return [
            'state'    => $running ? 'running' : ($queued ? 'queued' : ($state?->last_status ?: 'never')),
            'line'     => $line,
            'pct'      => $pct,
            'started'  => $lastRun,
            'synced'   => $state?->last_synced_at ? \Carbon\Carbon::parse($state->last_synced_at, 'UTC') : null,
            'last'     => (int) ($state?->last_count ?? 0),
            'count'    => $count,
            'products' => $products,
            'errors'   => array_slice($errors, 0, 5),
            'live'     => $running || $queued,
        ];
    }

    public function readCatalog(): void
    {
        abort_unless($this->canEdit(), 403);
        if ($this->code === '') {
            return;
        }
        $this->clearStop();
        $this->clearProgress();
        Cache::put('brand_suppliers.queued.' . $this->code, now()->timestamp, now()->addHour());
        SyncDistributorCatalogJob::dispatch($this->code);
        Notification::make()->success()->title('Reading ' . $this->label() . '\'s catalog')
            ->body('Progress shows in the Sources row.')->send();
    }

    /** Stop a catalog read: it stops before its next request to the brand's site. */
    public function stopRead(): void
    {
        abort_unless($this->canEdit(), 403);
        $key = $this->stopKey();
        if (! $key) {
            return;
        }
        Cache::put($key, true, now()->addHours(2));
        Cache::forget('brand_suppliers.queued.' . $this->code);
        Notification::make()->success()->title('Stopping the ' . $this->label() . ' read')
            ->body('It stops before its next request to ' . $this->label() . '\'s site. Pages already read are kept.')->send();
    }

    private function stopKey(): ?string
    {
        $class = app(DistributorRegistry::class)->adapterClass($this->code);
        return $this->readerKey('stop');
    }

    /** A new read starts with a clean progress note, not the last one's. */
    private function clearProgress(): void
    {
        $class = app(DistributorRegistry::class)->adapterClass($this->code);
        if ($pk = $this->readerKey('progress')) {
            Cache::forget($pk);
        }
    }

    private function clearStop(): void
    {
        if ($key = $this->stopKey()) {
            Cache::forget($key);
        }
    }

    public function stopping(): bool
    {
        $key = $this->stopKey();
        return $key !== null && (bool) Cache::get($key);
    }

    /** Read one product (a handle or its page URL) before reading them all. */
    public function readOne(): void
    {
        abort_unless($this->canEdit(), 403);
        $h = trim($this->oneBike);
        if ($h === '') {
            Notification::make()->warning()->title('Paste a product page address first')
                ->body('For example ' . $this->exampleUrl())->send();
            return;
        }
        $this->clearStop();
        $this->clearProgress();
        Cache::put('brand_suppliers.queued.' . $this->code, now()->timestamp, now()->addHour());
        SyncDistributorCatalogJob::dispatch($this->code, false, [$h]);
        Notification::make()->success()->title('Reading one ' . $this->label() . ' product')
            ->body('Progress shows in the Sources row; it lands in the Preview when done.')->send();
    }

    public function supportsOne(): bool
    {
        $class = app(DistributorRegistry::class)->adapterClass($this->code);
        return $class !== null && method_exists($class, 'only');
    }

    public function exampleUrl(): string
    {
        // a real product of this brand as the example, not one brand's product written into the page
        $h = DB::table('platform_distributor_catalogs')->where('distributor_code', $this->code)->whereNotNull('distributor_product_no')->value('distributor_product_no');
        return $this->baseUrl() . '/products/' . ($h ?: 'product-name');
    }

    /** The brand's store address: an added brand's own setting, else the built-in reader's config. */
    public function baseUrl(): string
    {
        $src = \App\Support\BrandSources::get($this->code);
        return rtrim((string) ($src->base_url ?? config('distributors.' . strtolower($this->code) . '.base_url', '')), '/');
    }

    // -------------------------------------------------------------- shops

    /** Shops with distributor import (the add-on), with their switch. */
    public function shops(): array
    {
        $on = BrandDirect::shopsFor($this->code);
        $q = mb_strtolower(trim($this->shopSearch));
        return Tenant::query()->where('is_active', true)->orderBy('name')->get()
            ->filter(fn (Tenant $t) => (bool) $t->distributor_sync_enabled)
            ->filter(fn (Tenant $t) => $q === '' || str_contains(mb_strtolower((string) $t->name), $q))
            ->map(fn (Tenant $t) => [
                'id' => (string) $t->id, 'name' => (string) $t->name,
                'on' => isset($on[(string) $t->id]),
                'since' => isset($on[(string) $t->id]) ? \Carbon\Carbon::parse($on[(string) $t->id])->format('M j, Y') : null,
                'items' => isset($on[(string) $t->id]) ? DB::table('tenant_inventory_item_vendors as v')
                    ->join('tenant_inventory_items as i', 'i.id', '=', 'v.inventory_item_id')
                    ->where('i.tenant_id', $t->id)->where('v.distributor_code', $this->code)->whereNotNull('v.distributor_catalog_id')->count() : null,
                'synced' => isset($on[(string) $t->id]) ? DB::table('tenant_distributor_catalog_subscriptions')->where('tenant_id', $t->id)
                    ->where('distributor_code', $this->code)->value('last_sync_at') : null,
            ])
            ->sortByDesc('on')->values()->all();
    }

    public function onCount(): int
    {
        return count(BrandDirect::shopsFor($this->code));
    }

    public function toggleShop(string $tenantId): void
    {
        abort_unless($this->canEdit(), 403);
        $t = Tenant::find($tenantId);
        if (! $t || ! $t->distributor_sync_enabled) {
            return;
        }
        $on = ! isset(BrandDirect::shopsFor($this->code)[$tenantId]);
        $by = Auth::guard('web')->user()?->email;
        BrandDirect::set($tenantId, $this->code, $on, $by);
        // switching a shop on is what connects it: its feed is on, nothing for the shop to do
        $sub = $this->shopSub($tenantId);
        $creds = (array) ($sub->credentials_encrypted ?? []);
        if ($on) {
            $creds['api_key'] = DistributorRegistry::PLATFORM_MARK;
            $creds['region'] = $creds['region'] ?? 'us';
        }
        $sub->credentials_encrypted = $creds;
        $sub->is_active = $on;
        $sub->save();

        $ready = BrandDirect::shopSideReady($this->code);
        Notification::make()->success()
            ->title($this->label() . ($on ? ' switched on for ' : ' switched off for ') . $t->name)
            ->body($on
                ? ($ready ? 'They can import from it now. Cost and stock come from the stock file below.' : 'They will see it once the shop side of ' . $this->label() . ' ships.')
                : 'Their box and new imports are hidden. Items they already have stay.')
            ->send();
    }

    // ------------------------------------- MARKER-BRAND-STOCK: the brand's one stock file

    private function shopSub(string $tenantId, bool $create = true): ?\App\Models\Tenant\TenantDistributorCatalogSubscription
    {
        $key = ['tenant_id' => $tenantId, 'distributor_code' => $this->code];
        return $create
            ? \App\Models\Tenant\TenantDistributorCatalogSubscription::firstOrCreate($key, ['is_active' => false, 'price_seed' => 'msrp'])
            : \App\Models\Tenant\TenantDistributorCatalogSubscription::where($key)->first();
    }

    /** The file in use for this brand, and any error from the last attempt. */
    public function brandFile(): ?object
    {
        return \App\Services\Distributors\BrandStockStore::file(\App\Services\Distributors\BrandStockStore::BRAND, $this->code);
    }

    public function hasFtp(): bool
    {
        return \App\Services\Distributors\BrandStockLoader::ftp($this->code) !== null;
    }

    /** Make the checked file the brand's stock file. */
    public function loadChecked(): void
    {
        abort_unless($this->canEdit(), 403);
        if ($this->checkPath === '' || ! is_file($this->checkPath)) {
            Notification::make()->warning()->title('Check the file again first')->send();
            return;
        }
        try {
            $r = \App\Services\Distributors\BrandStockLoader::load($this->code, $this->checkPath, $this->checkName, 'upload');
        } catch (\Throwable $e) {
            \App\Services\Distributors\BrandStockStore::failed(\App\Services\Distributors\BrandStockStore::BRAND, $this->code, $e->getMessage());
            Notification::make()->danger()->title('Not loaded')->body($e->getMessage())->send();
            return;
        }
        @unlink($this->checkPath);
        $this->checkPath = '';
        $this->check = null;
        $this->checkName = '';
        Notification::make()->success()->title($this->label() . ' stock file loaded')
            ->body(number_format($r['rows']) . ' rows, ' . number_format($r['check']['matched']) . ' in the catalog. Updating ' . $r['shops'] . ' shop' . ($r['shops'] === 1 ? '' : 's') . ' now.')->send();
    }

    public function editFtp(): void
    {
        abort_unless($this->canEdit(), 403);
        $f = (array) (\App\Models\PlatformDistributorConnection::where('distributor_code', $this->code)->first()?->stock_ftp ?? []);
        $this->ftp = ['host' => (string) ($f['host'] ?? ''), 'user' => (string) ($f['user'] ?? ''), 'password' => '', 'folder' => (string) ($f['folder'] ?? '')];
        $this->ftpOpen = true;
    }

    public function closeFtp(): void
    {
        $this->ftpOpen = false;
    }

    /** A blank password keeps the saved one. */
    public function saveFtp(): void
    {
        abort_unless($this->canEdit(), 403);
        $this->validate(['ftp.host' => ['nullable', 'string', 'max:190'], 'ftp.user' => ['nullable', 'string', 'max:128'],
            'ftp.password' => ['nullable', 'string', 'max:255'], 'ftp.folder' => ['nullable', 'string', 'max:190']]);
        $conn = \App\Models\PlatformDistributorConnection::firstOrCreate(['distributor_code' => $this->code]);
        $old = (array) ($conn->stock_ftp ?? []);
        $conn->stock_ftp = [
            'host' => trim((string) $this->ftp['host']), 'user' => trim((string) $this->ftp['user']),
            'password' => trim((string) $this->ftp['password']) ?: (string) ($old['password'] ?? ''),
            'folder' => trim((string) $this->ftp['folder']),
        ];
        $conn->save();
        $this->ftp['password'] = '';
        $this->ftpOpen = false;
        Notification::make()->success()->title('FTP folder saved')->body('Fetched every night at 06:10. Use Fetch now to try it.')->send();
    }

    public function fetchStock(): void
    {
        abort_unless($this->canEdit(), 403);
        try {
            $r = \App\Services\Distributors\BrandStockLoader::fetch($this->code);
        } catch (\Throwable $e) {
            \App\Services\Distributors\BrandStockStore::failed(\App\Services\Distributors\BrandStockStore::BRAND, $this->code, $e->getMessage());
            Notification::make()->danger()->title('Fetch failed')->body($e->getMessage())->send();
            return;
        }
        if ($r === null) {
            Notification::make()->success()->title('Nothing new')->body('The newest file in the folder is the one already loaded.')->send();
            return;
        }
        Notification::make()->success()->title('Loaded ' . $r['file'])->body(number_format($r['rows']) . ' rows. Updating ' . $r['shops'] . ' shop' . ($r['shops'] === 1 ? '' : 's') . ' now.')->send();
    }

    public function shopSideReady(): bool
    {
        return BrandDirect::shopSideReady($this->code);
    }

    // --------------------------------------------------- stock file check

    public function updatedStockFile(): void
    {
        abort_unless($this->canEdit(), 403);
        $this->validate(['stockFile' => ['file', 'max:20480', 'mimes:xlsx,csv,txt']]);
        $name = (string) $this->stockFile->getClientOriginalName();
        try {
            $read = BrandStockFile::read($this->stockFile->getRealPath(), $name, $this->code);
        } catch (\Throwable $e) {
            $this->check = ['error' => 'Could not read that file: ' . $e->getMessage()];
            return;
        }
        if ($read['missing']) {
            $this->check = ['error' => 'That file has no ' . implode(', ', array_map(fn ($m) => BrandStockFile::fieldLabels()[$m] ?? $m, $read['missing'])) . ' column. The ' . BrandStockFile::exportName($this->code) . ' has them.'];
            return;
        }
        $this->checkName = $name;
        $this->check = BrandStockFile::check($read['rows'], $this->code);
        // kept so "Load this file" uses exactly the file that was checked
        $dir = storage_path('app/brand-stock');
        @mkdir($dir, 0775, true);
        if ($this->checkPath !== '') {
            @unlink($this->checkPath);
        }
        $this->checkPath = $dir . '/pending-' . strtolower($this->code) . '-' . \Illuminate\Support\Str::random(8) . '.' . strtolower(pathinfo($name, PATHINFO_EXTENSION));
        copy($this->stockFile->getRealPath(), $this->checkPath);
        $this->stockFile = null;
    }

    public function clearCheck(): void
    {
        if ($this->checkPath !== '') {
            @unlink($this->checkPath);
            $this->checkPath = '';
        }
        $this->check = null;
        $this->checkName = '';
    }

    /** The rows not in the catalog, as a CSV. */
    public function downloadMissing()
    {
        $rows = (array) ($this->check['missing'] ?? []);
        $name = strtolower($this->code) . '-not-in-catalog.csv';
        return response()->streamDownload(function () use ($rows) {
            $out = fopen('php://output', 'w');
            fputcsv($out, ['Inventory ID', 'Description', 'Year', 'Qty', 'Base Price', 'Retail Price', 'UPC'], ',', '"', '');
            foreach ($rows as $r) {
                fputcsv($out, [$r['id'], $r['description'], $r['year'], $r['qty'], $r['cost'], $r['retail'], $r['upc']], ',', '"', '');
            }
            fclose($out);
        }, $name, ['Content-Type' => 'text/csv']);
    }

    // ------------------------------------------------------- rules, preview

    public function rules(): array
    {
        return DB::table('distributor_field_maps')->where('distributor_code', $this->code)
            ->orderBy('sort_order')->orderBy('canonical_field')
            ->get(['id', 'canonical_field', 'source_path', 'transform', 'transform_args', 'cleanup', 'notes', 'is_active'])
            ->map(function ($r) {
                $args = json_decode((string) $r->transform_args, true) ?: [];
                $clean = json_decode((string) $r->cleanup, true) ?: [];
                $how = match ($r->transform) {
                    'fixed' => 'always "' . ($args['value'] ?? '') . '"',
                    'json_passthrough' => 'kept whole',
                    'bool' => 'yes / no',
                    default => isset($args['cast']) ? 'as ' . $args['cast'] : 'copied',
                };
                $cl = [];
                foreach ((array) ($clean['replace'] ?? []) as $rep) {
                    $cl[] = 'replace ' . ($rep['pattern'] ?? '') . ' → ' . (($rep['with'] ?? '') === '' ? 'nothing' : $rep['with']);
                }
                foreach ((array) ($clean['value_map'] ?? []) as $from => $to) {
                    $cl[] = '"' . $from . '" → "' . $to . '"';
                }
                if (! empty($clean['blank_values'])) {
                    $cl[] = 'blank when ' . implode(', ', array_map(fn ($b) => '"' . $b . '"', $clean['blank_values']));
                }
                if (! empty($clean['label_case'])) {
                    $cl[] = 'labels: ' . str_replace('_', ' ', (string) $clean['label_case']);
                }
                if (! empty($clean['value_case'])) {
                    $cl[] = 'values: ' . str_replace('_', ' ', (string) $clean['value_case']);
                }
                return [
                    'id' => $r->id, 'field' => $r->canonical_field, 'from' => $r->source_path ?: '—',
                    'how' => $how, 'cleanup' => $cl, 'notes' => $r->notes, 'active' => (bool) $r->is_active,
                    'url' => \App\Filament\Resources\DistributorFieldMapResource::getUrl('edit', ['record' => $r->id]),
                ];
            })->all();
    }

    public function preview(): array
    {
        $cur = $this->currentHandles();
        $cur = $cur !== null ? array_flip($cur) : null;
        return $this->previewQuery()
            ->leftJoin('tenant_brand_stock as tbs', function ($j) {
                $j->on('tbs.variant_no', '=', 'pdc.distributor_variant_no')
                  ->where('tbs.tenant_id', \App\Services\Distributors\BrandStockStore::BRAND)
                  ->where('tbs.distributor_code', $this->code);
            })
            ->orderBy('pdc.name')->orderBy('pdc.distributor_variant_no')
            ->offset(($this->previewPage - 1) * 25)->limit(25)
            ->get(['pdc.distributor_variant_no', 'pdc.distributor_product_no', 'pdc.display_name', 'pdc.name', 'pdc.size', 'pdc.color', 'pdc.category_path',
                'pdc.msrp_cents', 'pdc.images', 'pdc.attributes', 'pdc.is_sellable', 'tbs.cost_cents', 'tbs.qty', 'tbs.model_year'])
            ->map(function ($r) use ($cur) {
                $attrs = json_decode((string) $r->attributes, true) ?: [];
                $year = null;
                foreach ($attrs as $a) {
                    if (mb_strtolower((string) ($a['Name'] ?? '')) === 'model year') {
                        $year = $a['Value'] ?? null;
                    }
                }
                $imgs = json_decode((string) $r->images, true) ?: [];
                return [
                    'id' => $r->distributor_variant_no, 'title' => $r->display_name ?: $r->name,
                    'size' => $r->size, 'color' => $r->color, 'category' => $r->category_path,
                    'msrp' => $r->msrp_cents !== null ? '$' . number_format($r->msrp_cents / 100, 2) : '—',
                    'cost' => $r->cost_cents !== null ? '$' . number_format($r->cost_cents / 100, 2) : null,
                    'qty' => $r->qty, 'images' => count($imgs), 'thumb' => is_string($imgs[0] ?? null) ? $imgs[0] : null,
                    'specs' => count($attrs), 'year' => $year ?: $r->model_year, 'sellable' => $r->is_sellable === null || (bool) $r->is_sellable,
                    'read' => $cur === null ? null : isset($cur[(string) $r->distributor_product_no]),
                ];
            })->all();
    }

    public function previewTotal(): int
    {
        return $this->previewQuery()->count();
    }

    public function previewPageTo(int $page): void
    {
        $this->previewPage = max(1, $page);
    }

    // ------------------------------------------------------------ inspect

    public function inspectRow(string $variantNo): void
    {
        $this->inspect = $this->inspect === $variantNo ? null : $variantNo;
    }

    /**
     * Everything about one row: what the brand's site gave us, what the
     * catalog made of it, and links to see it as a shop would.
     */
    public function inspected(): ?array
    {
        if (! $this->inspect) {
            return null;
        }
        $row = DB::table('platform_distributor_catalogs')
            ->where('distributor_code', $this->code)->where('distributor_variant_no', $this->inspect)->first();
        if (! $row) {
            return null;
        }
        $raw = null;
        $class = $this->adapter();
        if ($class && method_exists($class, 'storedEntry') && $row->distributor_product_no) {
            $raw = $class::storedEntry((string) $row->distributor_product_no, $this->code);
        }
        $catalog = [];
        foreach (['distributor_variant_no' => 'Part number', 'distributor_product_no' => 'Product', 'display_name' => 'Title',
            'display_subtitle' => 'Subtitle', 'name' => 'Model', 'manufacturer' => 'Brand', 'size' => 'Size', 'color' => 'Color',
            'category_path' => 'Category', 'msrp_cents' => 'Retail', 'upc' => 'UPC', 'is_sellable' => 'Available', 'description' => 'Description',
            'last_synced_at' => 'Written'] as $col => $label) {
            $v = $row->{$col} ?? null;
            if ($col === 'msrp_cents' && $v !== null) $v = '$' . number_format($v / 100, 2);
            if ($col === 'is_sellable') $v = $v ? 'yes' : 'no';
            $catalog[] = [$label, ($v === null || $v === '') ? '—' : (string) $v];
        }
        return [
            'row'     => $row,
            'catalog' => $catalog,
            'specs'   => json_decode((string) $row->attributes, true) ?: [],
            'images'  => array_values(array_filter((array) (json_decode((string) $row->images, true) ?: []), 'is_string')),
            'raw'     => $raw,
        ];
    }

    /** Shops the previews can be shown as: switched-on shops first. */
    public function previewShops(): array
    {
        $search = $this->shopSearch;
        $this->shopSearch = ''; // the Shops search box doesn't narrow this list
        $shops = $this->shops();
        $this->shopSearch = $search;
        return array_map(fn ($s) => ['id' => $s['id'], 'name' => $s['name'] . ($s['on'] ? '' : ' (not switched on)')], $shops);
    }

    public function previewUrl(string $kind, string $catalogId): ?string
    {
        $shops = $this->previewShops();
        $id = $this->previewShop !== '' ? $this->previewShop : ($shops[0]['id'] ?? null);
        $t = $id ? Tenant::find($id) : null;
        return $t ? \App\Support\CatalogPreviewLink::url($t, $kind, $catalogId) : null;
    }

    /** The last few products read, for the live line. */
    public function recentReads(): array
    {
        $class = $this->adapter();
        return ($rk = $this->readerKey('recent')) ? (array) Cache::get($rk, []) : [];
    }

    public function fieldMapsUrl(): string
    {
        return \App\Filament\Resources\DistributorFieldMapResource::getUrl('index');
    }
}
