<?php
// MARKER-PATCH-HLC7A

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Jobs\TenantDistributorSyncJob;
use App\Models\Tenant\TenantDistributorCatalogSubscription;
use App\Models\Tenant\TenantInventoryItemVendor;
use App\Models\Tenant\TenantPricingAttentionFlag;
use App\Services\Distributors\DistributorRegistry;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Tenant distributor surface. Phase 1: Connection & Sync — the shop's own key,
 * test, status, and on-demand refresh. Import + Pricing Attention follow.
 */
class DistributorController extends Controller
{
    // MARKER-DIST-MULTI — was the whole controller's distributor. Kept as the
    // default for the routes that still assume one (import, attention), which
    // remain HLC-only until those screens are generalised too.
    private const CODE = 'HLC';

    private function guard(): void
    {
        abort_unless(tenant()->distributor_sync_enabled, 403);
    }

    private function subscription(): TenantDistributorCatalogSubscription
    {
        return TenantDistributorCatalogSubscription::firstOrCreate(
            ['tenant_id' => tenant()->id, 'distributor_code' => self::CODE],
            ['is_active' => true],
        );
    }

    /**
     * MARKER-DIST-MULTI — one box per supported distributor.
     *
     * Reads DistributorRegistry::supported(), so a newly registered adapter
     * appears here with no change to this method or the view.
     */
    public function connection(): View
    {
        $this->guard();
        $tenant = tenant();
        $registry = app(\App\Services\Distributors\DistributorRegistry::class);

        $boxes = [];
        foreach ($registry->supported() as $code) {
            $sub = TenantDistributorCatalogSubscription::firstOrCreate(
                ['tenant_id' => $tenant->id, 'distributor_code' => $code],
                ['is_active' => true],
            );
            $creds = (array) ($sub->credentials_encrypted ?? []);

            $boxes[] = [
                'code'      => $code,
                'label'     => $registry->label($code),
                'sub'       => $sub,
                // MARKER-DIST-VENDOR-PROMPT — which of the shop's vendors IS
                // this distributor. Asked here because it's the one moment the
                // answer is unambiguous.
                'vendors'   => \App\Models\Tenant\TenantVendor::where('tenant_id', tenant()->id)
                    ->where('is_active', true)->orderBy('name')->get(['id', 'name', 'distributor_code']),
                'linkedVendorId' => \App\Models\Tenant\TenantVendor::where('tenant_id', tenant()->id)
                    ->where('distributor_code', strtolower($code))->value('id'),
                'fields'    => $registry->credentialFields($code),
                'hasKey'    => filled($creds['api_key'] ?? null),
                'maskedKey' => $this->mask($creds['api_key'] ?? null),
                // MARKER-PARTIAL-CREDS — a hint per field, not the whole
                // joined credential under both of them.
                'hints'     => $registry->credentialHints(
                    $code, $creds['api_key'] ?? null, fn ($v) => $this->mask($v)
                ),
                'priority'  => (int) ($sub->data_priority ?? 50),
                'linked'    => TenantInventoryItemVendor::query()
                    ->where('distributor_code', $code)
                    ->whereNotNull('distributor_catalog_id')
                    ->whereHas('item', fn ($q) => $q->where('tenant_id', $tenant->id))
                    ->count(),
            ];
        }

        // MARKER-PRIORITY-FIX — make the stored numbers say what the order
        // is, then read them back. Ties break on code so the result is
        // stable rather than whatever the database returns today.
        usort($boxes, fn ($a, $b) => [$a['priority'], $a['code']] <=> [$b['priority'], $b['code']]);
        $this->renumber($this->orderedSubs());
        foreach ($boxes as $k => $box) {
            $boxes[$k]['priority'] = $k + 1;
        }

        $legacy = $this->subscription();
        $legacyCreds = (array) ($legacy->credentials_encrypted ?? []);

        return view('tenant.distributors.connection', [
            'boxes'        => $boxes,
            'sub'          => $legacy,
            'hasKey'       => filled($legacyCreds['api_key'] ?? null),
            'maskedKey'    => $this->mask($legacyCreds['api_key'] ?? null),
            'accountNo'    => $legacy->account_number,
            'linkedCount'  => TenantInventoryItemVendor::query()
                ->where('distributor_code', self::CODE)
                ->whereNotNull('distributor_catalog_id')
                ->whereHas('item', fn ($q) => $q->where('tenant_id', $tenant->id))
                ->count(),
            'openFlags'    => TenantPricingAttentionFlag::query()
                ->where('tenant_id', $tenant->id)->where('status', 'open')->count(),
        ]);
    }

    /**
     * MARKER-DIST-MULTI — saves one distributor's box.
     *
     * The credential fields differ per distributor, so the submitted values
     * go through the registry, which knows how to collapse them into the
     * single stored api_key (BTI joins username and password with a colon).
     * A blank credential means "leave what's stored alone", so a shop can
     * change its priority without re-typing a key it can't read back.
     */
    public function saveKey(Request $request): RedirectResponse
    {
        $this->guard();
        $data = $request->validate([
            'distributor_code' => ['required', 'string', 'max:32'],
            'api_key'          => ['nullable', 'string', 'max:255'],
            // MARKER-CLS-RENDER — QBP's second key. Absent from this list it
            // was stripped before packCredentials ever saw it, so the field
            // rendered, accepted a paste, and saved nothing.
            'cls_key'          => ['nullable', 'string', 'max:255'],
            'username'         => ['nullable', 'string', 'max:128'],
            'password'         => ['nullable', 'string', 'max:255'],
            'account_number'   => ['nullable', 'string', 'max:64'],
            'vendor_id'        => ['nullable', 'string', 'max:64'], // MARKER-DIST-VENDOR-PROMPT
        ]);

        $registry = app(\App\Services\Distributors\DistributorRegistry::class);
        $code = strtoupper($data['distributor_code']);
        abort_unless($registry->isSupported($code), 404);

        $sub = TenantDistributorCatalogSubscription::firstOrCreate(
            ['tenant_id' => tenant()->id, 'distributor_code' => $code],
            ['is_active' => true],
        );

        $creds = (array) ($sub->credentials_encrypted ?? []);
        $before = (string) ($creds['api_key'] ?? '');

        // MARKER-PARTIAL-CREDS — hand the stored value in so a blank field
        // keeps its part instead of discarding the whole credential.
        $packed = $registry->packCredentials($code, $data, $before);
        if ($packed !== null) {
            $creds['api_key'] = $packed;
            $creds['region'] = $creds['region'] ?? 'us';
        }

        // MARKER-VENDOR-MERGE — if another vendor is already carrying this
        // distributor's items, linking without absorbing it splits the
        // catalog. Send the shop to a confirmation screen instead.
        $vendorId = trim((string) ($data['vendor_id'] ?? ''));
        if ($vendorId !== '') {
            $target = \App\Models\Tenant\TenantVendor::where('tenant_id', tenant()->id)
                ->where('id', $vendorId)->first();

            if ($target) {
                $merge  = app(\App\Services\Tenant\VendorMergeService::class);
                $source = $merge->currentSourceFor(tenant()->id, $code, $target->id);

                if ($source) {
                    $sub->save();

                    return redirect()->route('tenant.distributors.vendor_merge', [
                        'code' => $code, 'source' => $source->id, 'target' => $target->id,
                    ]);
                }
            }

            if ($target) {
                // One vendor per distributor per tenant. Two rows claiming the
                // same code means vendorFor() picks whichever the DB returns
                // first, so imports attach to one while existing items hang off
                // the other.
                \App\Models\Tenant\TenantVendor::where('tenant_id', tenant()->id)
                    ->where('distributor_code', strtolower($code))
                    ->where('id', '!=', $target->id)
                    ->update(['distributor_code' => null]);

                $target->update(['distributor_code' => strtolower($code)]);
            }
        }

        $sub->credentials_encrypted = $creds;
        $sub->account_number = $data['account_number'] ?? $sub->account_number;
        $sub->save();

        // MARKER-PARTIAL-CREDS — say plainly when the credential didn't move.
        // Reporting "saved" on a no-op is what hid the discarded password.
        $after = (string) ($sub->credentials_encrypted['api_key'] ?? '');
        $label = $registry->label($code);

        if ($before !== '' && $after === $before) {
            return back()->with('success', $label . ' updated. The saved credential is unchanged.');
        }

        return back()->with('success', $label . ' credentials saved. Test to confirm access.');
    }

    /**
     * MARKER-PRIORITY-ORDER — move a distributor up or down the data order.
     *
     * Swaps data_priority with the adjacent distributor rather than
     * renumbering everything. Renumbering would rewrite rows the shop never
     * touched, and it would let two shops hold different numbers that mean
     * the same order — harder to reason about later, for no benefit.
     *
     * If the two happen to hold the same stored value (both still on the
     * default), the mover is nudged one below its neighbour so the order is
     * still definite.
     */
    /**
     * MARKER-VENDOR-MERGE — show what absorbing the old vendor will do.
     *
     * The merge is irreversible and deletes a vendor, so the counts and the
     * surviving name are the sanity check that the right target was picked.
     */
    public function vendorMerge(Request $request): View
    {
        $this->guard();

        $code   = strtoupper((string) $request->query('code'));
        $source = \App\Models\Tenant\TenantVendor::where('tenant_id', tenant()->id)
            ->findOrFail($request->query('source'));
        $target = \App\Models\Tenant\TenantVendor::where('tenant_id', tenant()->id)
            ->findOrFail($request->query('target'));

        return view('tenant.distributors.vendor-merge', [
            'code'    => $code,
            'source'  => $source,
            'target'  => $target,
            'preview' => app(\App\Services\Tenant\VendorMergeService::class)->preview($source, $target),
        ]);
    }

    public function vendorMergeRun(Request $request): RedirectResponse
    {
        $this->guard();

        $code   = strtoupper((string) $request->input('code'));
        $source = \App\Models\Tenant\TenantVendor::where('tenant_id', tenant()->id)
            ->findOrFail($request->input('source'));
        $target = \App\Models\Tenant\TenantVendor::where('tenant_id', tenant()->id)
            ->findOrFail($request->input('target'));

        $res = app(\App\Services\Tenant\VendorMergeService::class)->merge($source, $target, $code);

        return redirect()->route('tenant.distributors.connection')->with(
            'status',
            "Merged {$res['source_name']} into {$res['target_name']} — {$res['items']} items, "
            . "{$res['special_orders']} special orders and {$res['shipments']} receipts moved."
        );
    }

    public function movePriority(Request $request): RedirectResponse
    {
        $this->guard();
        $data = $request->validate([
            'distributor_code' => ['required', 'string', 'max:32'],
            'direction'        => ['required', 'in:up,down'],
        ]);

        $code = strtoupper($data['distributor_code']);

        $subs = $this->orderedSubs();

        $i = $subs->search(fn ($s) => $s->distributor_code === $code);
        if ($i === false) {
            return back();
        }

        $j = $data['direction'] === 'up' ? $i - 1 : $i + 1;
        if ($j < 0 || $j >= $subs->count()) {
            return back();          // already at the end
        }

        // MARKER-PRIORITY-FIX — move it in the list, then renumber the whole
        // list 1..N. Swapping the two stored values kept the order right but
        // left the numbers arbitrary (two defaults both at 50, or a 1 beside
        // a 50), so the stored value never stated the position.
        $list = $subs->values()->all();
        [$list[$i], $list[$j]] = [$list[$j], $list[$i]];

        $this->renumber(collect($list));

        return back();
    }

    /**
     * MARKER-PRIORITY-FIX — the tenant's subscriptions in priority order,
     * limited to distributors the registry supports.
     *
     * That limit is the fix: this used to return every subscription row,
     * while the screen lists only supported codes. A leftover row for a
     * code with no adapter (QBP was created and abandoned mid-build) holds
     * a position here, shifts every index after it, and makes an arrow move
     * a different box than the one that was clicked.
     */
    private function orderedSubs()
    {
        $codes = app(\App\Services\Distributors\DistributorRegistry::class)->supported();

        return TenantDistributorCatalogSubscription::where('tenant_id', tenant()->id)
            ->whereIn('distributor_code', $codes)
            ->orderBy('data_priority')
            ->orderBy('distributor_code')
            ->get();
    }

    /**
     * MARKER-PRIORITY-FIX — rewrite priorities as 1..N in their current
     * order, so the stored number IS the position.
     *
     * Swapping the two values kept the order right but left the numbers
     * arbitrary. Writes only where a value differs, so calling this on every
     * page load costs nothing once the list is correct — it exists on the
     * read path because rows created before this all sit at the default of
     * 50, and a shop that never touches the arrows would otherwise keep
     * numbers that mean nothing.
     *
     * @param  \Illuminate\Support\Collection $subs already in the wanted order
     */
    private function renumber($subs): void
    {
        $n = 1;
        foreach ($subs as $sub) {
            if ((int) $sub->data_priority !== $n) {
                $sub->data_priority = $n;
                $sub->save();
            }
            $n++;
        }
    }

    /**
     * MARKER-POSTED-CODE — the distributor the request is about.
     *
     * No default. self::CODE as a fallback meant a request that didn't carry
     * a distributor quietly acted on HLC and reported success for it, which
     * is exactly how "Test connection on the BTI box says HLC connected"
     * stayed hidden.
     */
    private function requestedSub(Request $request): array
    {
        $registry = app(DistributorRegistry::class);
        $code = strtoupper(trim((string) $request->input('distributor_code', '')));

        abort_unless($code !== '' && $registry->isSupported($code), 404);

        $sub = TenantDistributorCatalogSubscription::firstOrCreate(
            ['tenant_id' => tenant()->id, 'distributor_code' => $code],
            ['is_active' => true],
        );

        return [$code, $registry->label($code), $sub];
    }

    public function testConnection(Request $request): RedirectResponse
    {
        $this->guard();
        // MARKER-POSTED-CODE — was self::CODE, so every box tested HLC.
        [$code, $label, $sub] = $this->requestedSub($request);

        $creds = (array) ($sub->credentials_encrypted ?? []);
        if (blank($creds['api_key'] ?? null)) {
            return back()->with('error', 'Enter your ' . $label . ' credentials first.');
        }

        try {
            $adapter = app(DistributorRegistry::class)->make($code, [
                'api_key' => $creds['api_key'],
                'region'  => $creds['region'] ?? 'us',
            ]);
            $res = $adapter->testConnection();
            $ok = (bool) ($res['ok'] ?? false);

            // MARKER-BTI-PROBE — every failure used to be recorded as
            // auth_failed, so a 503, a timeout or DNS trouble all displayed as
            // "credentials rejected" and sent someone to re-enter a password
            // that was already correct.
            $status = (int) ($res['status'] ?? 0);
            $isAuth = $res['auth'] ?? in_array($status, [401, 403], true);

            $sub->last_sync_status = $ok
                ? 'connected'
                : ($isAuth ? 'auth_failed' : 'unreachable');
            $sub->save();

            return back()->with($ok ? 'success' : 'error',
                $ok
                    ? 'Connected to ' . $label . '.'
                    : ($isAuth
                        ? ($label . ' rejected the credentials (HTTP ' . $status . ').')
                        : ('Could not reach ' . $label . ' — it answered HTTP ' . $status
                           . '. Your credentials look fine; try again shortly.')));
        } catch (\Throwable $e) {
            return back()->with('error', 'Could not reach ' . $label . ': ' . $e->getMessage());
        }
    }

    // MARKER-POSTED-CODE — the job syncs every active subscription for the
    // tenant, so the distributor here decides whose credentials are checked
    // first and what the message names, not which feed runs.
    public function refreshSync(Request $request): RedirectResponse
    {
        $this->guard();
        [$code, $label, $sub] = $this->requestedSub($request);

        $creds = (array) ($sub->credentials_encrypted ?? []);
        if (blank($creds['api_key'] ?? null)) {
            return back()->with('error', 'Connect ' . $label . ' before refreshing.');
        }

        // MARKER-PATCH-556 — same logged job as Catalog attention's Sync now,
        // so every sync (any button) appears in the run history.
        \App\Jobs\RunTenantDistributorSyncJob::dispatch(tenant()->id, false, 'manual');
        return back()->with('success', 'Refreshing your cost & availability in the background.');
    }

    public function import(\Illuminate\Http\Request $request): \Illuminate\Contracts\View\View
    {
        $this->guard();

        // MARKER-IMPORTER-PER-CODE
        $code = $this->importCode($request->query('code'));

        return view('tenant.distributors.import', array_merge(
            $this->importFilterOptions($code),
            ['filters' => []],
        ));
    }

    public function importRun(\Illuminate\Http\Request $request): \Illuminate\Contracts\View\View
    {
        $this->guard();

        $data = $request->validate([
            'mode'               => ['required', 'in:preview,commit'],
            'code'               => ['nullable', 'string', 'max:32'],
            'brand'              => ['nullable', 'string', 'max:128'],
            'category'           => ['nullable', 'string', 'max:64'],
            'include_unsellable' => ['nullable'],
        ]);

        // MARKER-IMPORTER-PER-CODE
        $code = $this->importCode($data['code'] ?? null);

        $filters = array_filter([
            'brand'              => $data['brand'] ?? null,
            'category'           => $data['category'] ?? null,
            'include_unsellable' => ! empty($data['include_unsellable']),
        ], fn ($v) => $v !== null && $v !== '' && $v !== false);

        $view = $this->importFilterOptions($code, $filters['brand'] ?? null); // MARKER-SSEL-SCOPE
        $view['filters'] = $filters;
        $view['mode'] = $data['mode'];

        if (blank($filters['brand'] ?? null) && blank($filters['category'] ?? null)) {
            return view('tenant.distributors.import', $view)
                ->with('error', 'Choose at least a brand or a category.');
        }

        $view['result'] = app(\App\Services\Distributors\DistributorCatalogImportService::class)
            ->import(tenant()->id, $code, $filters, $data['mode'] !== 'commit', 2000);

        return view('tenant.distributors.import', $view);
    }

    /** Brand / category options + catalog size for the import filter. */
    /**
     * MARKER-IMPORTER-PER-CODE — brands, categories and the item count for
     * ONE distributor. Was pinned to self::CODE, which left a shop with BTI
     * connected unable to import any of its 24,643 items.
     */
    private function importFilterOptions(string $code, ?string $brand = null): array
    {
        $base = \App\Models\PlatformDistributorCatalog::query()
            ->where('distributor_code', $code)->where('is_active', true);

        return [
            'importCode' => $code,
            'importCodes' => $this->importableCodes(),
            'brands' => (clone $base)->whereNotNull('manufacturer')
                ->distinct()->orderBy('manufacturer')->pluck('manufacturer'),
            // MARKER-SSEL-SCOPE — categories narrow to the chosen brand so
            // the picker never offers a category the brand has no items in.
            'categories' => (clone $base)->whereNotNull('category')
                ->when($brand !== null && $brand !== '', fn ($q) => $q->where('manufacturer', $brand))
                ->distinct()->orderBy('category')->pluck('category'),
            'catalogTotal' => (clone $base)->count(),
        ];
    }

    /** MARKER-SSEL-SCOPE — categories for one brand, for the live picker. */
    public function importCategories(\Illuminate\Http\Request $request): \Illuminate\Http\JsonResponse
    {
        $this->guard();

        $code  = $this->importCode($request->query('code'));
        $brand = trim((string) $request->query('brand', ''));

        $categories = \App\Models\PlatformDistributorCatalog::query()
            ->where('distributor_code', $code)->where('is_active', true)
            ->whereNotNull('category')
            ->when($brand !== '', fn ($q) => $q->where('manufacturer', $brand))
            ->distinct()->orderBy('category')->pluck('category');

        return response()->json(['categories' => $categories]);
    }

    /**
     * Distributors the registry supports AND that have catalog rows. A
     * supported distributor with an empty catalog would present as a broken
     * filter rather than as "nothing synced yet".
     *
     * @return array<int,string>
     */
    private function importableCodes(): array
    {
        $supported = app(\App\Services\Distributors\DistributorRegistry::class)->supported();

        return \App\Models\PlatformDistributorCatalog::query()
            ->whereIn('distributor_code', $supported)
            ->where('is_active', true)
            ->select('distributor_code')->distinct()
            ->orderBy('distributor_code')
            ->pluck('distributor_code')->all();
    }

    /** The distributor being imported from, defaulting to the first available. */
    private function importCode(?string $requested): string
    {
        $codes = $this->importableCodes();
        $requested = strtoupper((string) $requested);

        return in_array($requested, $codes, true)
            ? $requested
            : ($codes[0] ?? self::CODE);
    }

    public function attention(\Illuminate\Http\Request $request): \Illuminate\Contracts\View\View
    {
        $this->guard();
        $tenant = tenant();
        $stock = in_array($request->query('stock'), ['in', 'out'], true) ? $request->query('stock') : 'all';

        $fBrand    = trim((string) $request->query('brand', '')) ?: null;
        $fCategory = trim((string) $request->query('category', '')) ?: null;
        $fReason   = trim((string) $request->query('reason', '')) ?: null;

        $q = \App\Models\Tenant\TenantPricingAttentionFlag::query()
            ->with('item.distributorCatalog')
            ->where('tenant_id', $tenant->id)
            ->where('status', 'open');

        if ($stock === 'in') {
            $q->whereHas('item', fn ($i) => $i->where('computed_stock_count', '>', 0));
        } elseif ($stock === 'out') {
            $q->whereHas('item', fn ($i) => $i->where('computed_stock_count', '<=', 0));
        }
        if ($fBrand) {
            $q->whereHas('item.distributorCatalog', fn ($w) => $w->where('manufacturer', $fBrand));
        }
        if ($fCategory) {
            $q->whereHas('item.distributorCatalog', fn ($w) => $w->where('category', $fCategory));
        }
        if ($fReason) {
            $q->where('reason', $fReason);
        }

        // MARKER-ATTENTION-SCALE -- reason priority in SQL so pagination keeps
        // the queue order, then newest first within a reason.
        $rankSql = "CASE reason WHEN 'title_changed' THEN 0 WHEN 'below_map' THEN 1 WHEN 'off_msrp' THEN 2"
            . " WHEN 'map_vanished' THEN 3 WHEN 'msrp_vanished' THEN 4 WHEN 'cost_vanished' THEN 5 ELSE 9 END";
        $perPage = (int) $request->query('per', 100);
        if (! in_array($perPage, [50, 100, 250], true)) {
            $perPage = 100;
        }
        $flags = $q->orderByRaw($rankSql)->orderByDesc('created_at')
            ->paginate($perPage)->withQueryString();

        // MARKER-ATTENTION-SCALE -- chip counts reflect ALL open flags (not the
        // current filter), computed as SQL aggregates: this page previously
        // hydrated every open flag three times over and OOM'd at scale.
        $base = \App\Models\Tenant\TenantPricingAttentionFlag::query()
            ->where('tenant_id', $tenant->id)->where('status', 'open');
        $allBy = (clone $base)->selectRaw('reason, count(*) as c')->groupBy('reason')->pluck('c', 'reason');
        $total = (int) $allBy->sum();
        $inCount = (clone $base)
            ->whereHas('item', fn ($i) => $i->where('computed_stock_count', '>', 0))->count();
        $counts = [
            'total'     => $total,
            'in'        => $inCount,
            'out'       => $total - $inCount,
            'title'     => (int) ($allBy['title_changed'] ?? 0),
            'details'   => (int) ($allBy['details_changed'] ?? 0), // MARKER-ATTENTION-SCALE -- was uncounted, hiding thousands from the header
            'below_map' => (int) ($allBy['below_map'] ?? 0),
            'off_msrp'  => (int) ($allBy['off_msrp'] ?? 0),
            'vanished'  => (int) (($allBy['cost_vanished'] ?? 0) + ($allBy['map_vanished'] ?? 0) + ($allBy['msrp_vanished'] ?? 0)),
        ];

        // Brand / category options from the catalog rows behind the open flags,
        // without hydrating a single flag model.
        $catIds = \App\Models\Tenant\TenantInventoryItem::query()
            ->whereIn('id', (clone $base)->select('inventory_item_id'))
            ->whereNotNull('distributor_catalog_id')
            ->distinct()->pluck('distributor_catalog_id');
        $brandOptions = \App\Models\PlatformDistributorCatalog::query()
            ->whereIn('id', $catIds)->whereNotNull('manufacturer')
            ->distinct()->orderBy('manufacturer')->pluck('manufacturer');
        $categoryOptions = \App\Models\PlatformDistributorCatalog::query()
            ->whereIn('id', $catIds)->whereNotNull('category')
            ->distinct()->orderBy('category')->pluck('category');

        $filters = ['brand' => $fBrand, 'category' => $fCategory, 'reason' => $fReason];

        // MARKER-PATCH-555 — latest sync run for the header line
        $lastSyncRun = \Illuminate\Support\Facades\DB::table('tenant_distributor_sync_runs')
            ->where('tenant_id', $tenant->id)->orderByDesc('started_at')->first();

        // MARKER-TITLE-RATIO -- shown in the page legend.
        $titleThresholdPct = (int) round(((float) config('distributors.title_change_min_ratio', 0.15)) * 100);

        return view('tenant.distributors.attention', compact(
            'flags', 'counts', 'stock', 'filters', 'brandOptions', 'categoryOptions', 'lastSyncRun', 'perPage', 'titleThresholdPct'
        ));
    }

    /**
     * POST /attention/sync — MARKER-PATCH-555
     * Queue a tenant distributor sync (real or dry-run) and bounce back;
     * the page shows the run row when the worker finishes.
     */
    public function attentionSync(\Illuminate\Http\Request $request): \Illuminate\Http\RedirectResponse
    {
        $this->guard();
        $tenant = tenant();
        $dry = $request->input('mode') === 'dry';
        \App\Jobs\RunTenantDistributorSyncJob::dispatch($tenant->id, $dry, 'manual');
        return back()->with('success', $dry
            ? 'Dry run queued — refresh in a minute to see what would change.'
            : 'Sync queued — refresh in a minute for results.');
    }

    public function attentionResolve(\Illuminate\Http\Request $request): \Illuminate\Http\RedirectResponse
    {
        $this->guard();
        $data = $request->validate([
            'action'     => ['required', 'in:raise_map,match_msrp,acknowledge,adopt_title,keep_title,adopt_details,keep_details'],
            'flag_ids'   => ['nullable', 'array'],
            'row_flag'   => ['nullable', 'string'], // MARKER-PATCH-558 — per-row one-click action
            'flag_ids.*' => ['string'],
            'select_all' => ['nullable', 'boolean'],
            'f_brand'    => ['nullable', 'string', 'max:128'],
            'f_category' => ['nullable', 'string', 'max:64'],
            'f_reason'   => ['nullable', 'string', 'max:32'],
            'f_stock'    => ['nullable', 'string', 'in:all,in,out'],
        ]);

        $action = $data['action'];

        // MARKER-PATCH-558 — a row button targets exactly one flag,
        // regardless of checkboxes or the apply-all toggle.
        if (filled($data['row_flag'] ?? null)) {
            $data['flag_ids'] = [$data['row_flag']];
            $request->merge(['select_all' => false]);
        }

        $q = \App\Models\Tenant\TenantPricingAttentionFlag::query()
            ->with('item.distributorCatalog')
            ->where('tenant_id', tenant()->id)
            ->where('status', 'open');

        if ($request->boolean('select_all')) {
            // "Apply to all matching the filter" — re-query server-side, ignore ids.
            if (filled($data['f_brand'] ?? null)) {
                $q->whereHas('item.distributorCatalog', fn ($w) => $w->where('manufacturer', $data['f_brand']));
            }
            if (filled($data['f_category'] ?? null)) {
                $q->whereHas('item.distributorCatalog', fn ($w) => $w->where('category', $data['f_category']));
            }
            if (filled($data['f_reason'] ?? null)) {
                $q->where('reason', $data['f_reason']);
            }
            if (($data['f_stock'] ?? 'all') === 'in') {
                $q->whereHas('item', fn ($w) => $w->where('computed_stock_count', '>', 0));
            } elseif (($data['f_stock'] ?? 'all') === 'out') {
                $q->whereHas('item', fn ($w) => $w->where('computed_stock_count', '<=', 0));
            }
        } else {
            $q->whereIn('id', $data['flag_ids'] ?? []);
        }

        // MARKER-ATTENTION-SCALE -- a select-all over thousands of flags must
        // not hydrate everything at once; stream in id-ordered chunks.
        if (! (clone $q)->exists()) {
            return back()->with('error', 'Nothing selected.');
        }
        $flags = $q->lazyById(200);

        $applied = 0;
        $skipped = 0;
        $userId = optional($request->user())->id;

        // MARKER-CATALOG-HISTORY — capture what these items look like before we
        // touch them, so the batch can be put back.
        $recorder = new \App\Services\Tenant\CatalogChangeRecorder(
            tenant()->id,
            $action,
            [
                'select_all' => (bool) ($data['select_all'] ?? false),
                'brand'      => $data['f_brand'] ?? null,
                'category'   => $data['f_category'] ?? null,
                'reason'     => $data['f_reason'] ?? null,
            ],
            optional($request->user())->email,
        );
        $titleReason = \App\Models\Tenant\TenantPricingAttentionFlag::REASON_TITLE_CHANGED;
        $detailsReason = \App\Models\Tenant\TenantPricingAttentionFlag::REASON_DETAILS_CHANGED; // MARKER-DETAILS-WATCH

        foreach ($flags as $flag) {
            $isTitle = $flag->reason === $titleReason;
            $isDetails = $flag->reason === $detailsReason; // MARKER-DETAILS-WATCH
            $item = $flag->item;

            // Type guards — an action only applies to the matching flag kind.
            if (in_array($action, ['adopt_title', 'keep_title'], true) && ! $isTitle) {
                $skipped++;
                continue;
            }
            if (in_array($action, ['adopt_details', 'keep_details'], true) && ! $isDetails) {
                $skipped++;
                continue;
            }
            if (in_array($action, ['raise_map', 'match_msrp'], true) && ($isTitle || $isDetails)) {
                $skipped++;
                continue;
            }

            if ($action === 'raise_map' || $action === 'match_msrp') {
                $target = $action === 'raise_map'
                    ? ($item?->catalog_map_cents ?? ($flag->detail['prev_map_cents'] ?? null))
                    : ($item?->catalog_msrp_cents ?? ($flag->detail['prev_msrp_cents'] ?? null));
                if (! $item || ! $target) {
                    $skipped++;
                    continue;
                }
                $recorder->capture($item);          // MARKER-CATALOG-HISTORY
                $item->shop_sell_price_cents = (int) $target;
                $item->save();
                $recorder->captured($item);
            } elseif ($action === 'adopt_title') {
                $cat = $item?->distributorCatalog;
                if (! $item || ! $cat || blank($cat->display_name)) {
                    $skipped++;
                    continue;
                }
                $recorder->capture($item);          // MARKER-CATALOG-HISTORY
                $item->name = $cat->display_name;
                $item->catalog_title_seen = $cat->display_name; // snapshot so it won't re-flag
                $item->save();
                $recorder->captured($item);          // MARKER-CATALOG-HISTORY
            } elseif ($action === 'keep_title') {
                // Keep the tenant's name; just acknowledge the catalog's new title
                // so the watch stops flagging it.
                $cat = $item?->distributorCatalog;
                if ($item && $cat) {
                    $item->catalog_title_seen = $cat->display_name;
                    $item->save();
                }
            } elseif ($action === 'adopt_details') {
                // MARKER-DETAILS-WATCH — copy only non-blank catalog values;
                // the feed dropping a field never blanks the shop's own.
                $cat = $item?->distributorCatalog;
                if (! $item || ! $cat) {
                    $skipped++;
                    continue;
                }
                $recorder->capture($item);          // MARKER-CATALOG-HISTORY
                foreach (['color', 'size', 'description'] as $fld) {
                    if (filled($cat->{$fld})) {
                        $item->{$fld} = $cat->{$fld};
                    }
                }
                $item->catalog_details_seen = [
                    'color'       => $cat->color,
                    'size'        => $cat->size,
                    'description' => $cat->description,
                ];
                $item->save();
                $recorder->captured($item);          // MARKER-CATALOG-HISTORY
            } elseif ($action === 'keep_details') {
                // Keep the tenant's values; snapshot the catalog's so the
                // watch stops flagging this change.
                $cat = $item?->distributorCatalog;
                if ($item && $cat) {
                    $item->catalog_details_seen = [
                        'color'       => $cat->color,
                        'size'        => $cat->size,
                        'description' => $cat->description,
                    ];
                    $item->save();
                }
            }
            // 'acknowledge' falls through to resolve with no item change.

            $flag->status = 'resolved';
            $flag->resolved_at = now();
            $flag->resolved_by = $userId;
            $flag->save();
            $applied++;
        }

        $verb = [
            'raise_map'   => 'Raised to MAP',
            'match_msrp'  => 'Matched to MSRP',
            'acknowledge' => 'Dismissed',
            'adopt_title' => 'Adopted new title',
            'keep_title'  => 'Kept your title',
            'adopt_details' => 'Adopted new details',
            'keep_details'  => 'Kept your details',
        ][$action];
        $msg = "{$verb}: {$applied} item(s)." . ($skipped ? " {$skipped} skipped." : '');

        // MARKER-CATALOG-HISTORY — one batch per bulk action.

        $batchId = $recorder->finish();

        return back()->with('success', $msg);
    }

    private function mask(?string $key): ?string
    {
        if (blank($key)) {
            return null;
        }
        return substr($key, 0, 8) . str_repeat('•', max(0, strlen($key) - 13)) . substr($key, -5);
    }
}
