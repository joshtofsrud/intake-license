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
            'username'         => ['nullable', 'string', 'max:128'],
            'password'         => ['nullable', 'string', 'max:255'],
            'account_number'   => ['nullable', 'string', 'max:64'],
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

        $view = $this->importFilterOptions($code);
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
    private function importFilterOptions(string $code): array
    {
        $base = \App\Models\PlatformDistributorCatalog::query()
            ->where('distributor_code', $code)->where('is_active', true);

        return [
            'importCode' => $code,
            'importCodes' => $this->importableCodes(),
            'brands' => (clone $base)->whereNotNull('manufacturer')
                ->distinct()->orderBy('manufacturer')->pluck('manufacturer'),
            'categories' => (clone $base)->whereNotNull('category')
                ->distinct()->orderBy('category')->pluck('category'),
            'catalogTotal' => (clone $base)->count(),
        ];
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

        $rank = ['title_changed' => 0, 'below_map' => 1, 'off_msrp' => 2, 'map_vanished' => 3, 'msrp_vanished' => 4, 'cost_vanished' => 5];
        $flags = $q->orderByDesc('created_at')->get()
            ->sortBy(fn ($f) => $rank[$f->reason] ?? 9)->values();

        // Chip counts reflect ALL open flags (not the current filter).
        $allOpen = \App\Models\Tenant\TenantPricingAttentionFlag::query()
            ->where('tenant_id', $tenant->id)->where('status', 'open')
            ->with('item:id,computed_stock_count')->get();
        $allBy = $allOpen->countBy('reason');
        $inCount = $allOpen->filter(fn ($f) => (int) ($f->item->computed_stock_count ?? 0) > 0)->count();
        $counts = [
            'total'     => $allOpen->count(),
            'in'        => $inCount,
            'out'       => $allOpen->count() - $inCount,
            'title'     => $allBy['title_changed'] ?? 0,
            'below_map' => $allBy['below_map'] ?? 0,
            'off_msrp'  => $allBy['off_msrp'] ?? 0,
            'vanished'  => ($allBy['cost_vanished'] ?? 0) + ($allBy['map_vanished'] ?? 0) + ($allBy['msrp_vanished'] ?? 0),
        ];

        // Brand / category options from the catalog rows behind the open flags.
        $catIds = \App\Models\Tenant\TenantPricingAttentionFlag::query()
            ->where('tenant_id', $tenant->id)->where('status', 'open')
            ->with('item:id,distributor_catalog_id')->get()
            ->pluck('item.distributor_catalog_id')->filter()->unique()->values();
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

        return view('tenant.distributors.attention', compact(
            'flags', 'counts', 'stock', 'filters', 'brandOptions', 'categoryOptions', 'lastSyncRun'
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
            'action'     => ['required', 'in:raise_map,match_msrp,acknowledge,adopt_title,keep_title'],
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

        $flags = $q->get();
        if ($flags->isEmpty()) {
            return back()->with('error', 'Nothing selected.');
        }

        $applied = 0;
        $skipped = 0;
        $userId = optional($request->user())->id;
        $titleReason = \App\Models\Tenant\TenantPricingAttentionFlag::REASON_TITLE_CHANGED;

        foreach ($flags as $flag) {
            $isTitle = $flag->reason === $titleReason;
            $item = $flag->item;

            // Type guards — an action only applies to the matching flag kind.
            if (in_array($action, ['adopt_title', 'keep_title'], true) && ! $isTitle) {
                $skipped++;
                continue;
            }
            if (in_array($action, ['raise_map', 'match_msrp'], true) && $isTitle) {
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
                $item->shop_sell_price_cents = (int) $target;
                $item->save();
            } elseif ($action === 'adopt_title') {
                $cat = $item?->distributorCatalog;
                if (! $item || ! $cat || blank($cat->display_name)) {
                    $skipped++;
                    continue;
                }
                $item->name = $cat->display_name;
                $item->catalog_title_seen = $cat->display_name; // snapshot so it won't re-flag
                $item->save();
            } elseif ($action === 'keep_title') {
                // Keep the tenant's name; just acknowledge the catalog's new title
                // so the watch stops flagging it.
                $cat = $item?->distributorCatalog;
                if ($item && $cat) {
                    $item->catalog_title_seen = $cat->display_name;
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
        ][$action];
        $msg = "{$verb}: {$applied} item(s)." . ($skipped ? " {$skipped} skipped." : '');

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
