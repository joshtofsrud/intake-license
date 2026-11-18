<?php

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
    // was the whole controller's distributor. Kept as the
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
     * one box per supported distributor.
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
            // a row created just by opening this page is
            // not a connection. It starts OFF and the shop turns it on.
            $sub = TenantDistributorCatalogSubscription::firstOrCreate(
                ['tenant_id' => $tenant->id, 'distributor_code' => $code],
                // new rows start at MSRP; the column
                // defaults to 'map' so nobody already connected shifts.
                ['is_active' => false, 'price_seed' => 'msrp'],
            );
            $creds = (array) ($sub->credentials_encrypted ?? []);

            $boxes[] = [
                'code'      => $code,
                'label'     => $registry->label($code),
                'sub'       => $sub,
                // which of the shop's vendors IS
                // this distributor. Asked here because it's the one moment the
                // answer is unambiguous.
                'vendors'   => \App\Models\Tenant\TenantVendor::where('tenant_id', tenant()->id)
                    ->where('is_active', true)->orderBy('name')->get(['id', 'name', 'distributor_code']),
                'linkedVendorId' => \App\Models\Tenant\TenantVendor::where('tenant_id', tenant()->id)
                    ->where('distributor_code', strtolower($code))->value('id'),
                'fields'    => $registry->credentialFields($code),
                // no key asked for: Intake holds the one login
                'platformSupplied' => $registry->platformSupplied($code),
                'enabled'   => (bool) $sub->is_active,
                'hasKey'    => filled($creds['api_key'] ?? null),
                'priceSeed' => $sub->price_seed ?: 'map',
                'maskedKey' => $this->mask($creds['api_key'] ?? null),
                // a hint per field, not the whole
                // joined credential under both of them.
                'hints'     => $registry->credentialHints(
                    $code, $creds['api_key'] ?? null, fn ($v) => $this->mask($v)
                ),
                'priority'  => (int) ($sub->data_priority ?? 50),
                'options'   => (array) ($creds['options'] ?? []),
                // MARKER-BOX-WAREHOUSES: its warehouses, what counts, where online orders ship from
                'wh'        => $this->boxWarehouses($tenant, $code, (array) ($creds['options'] ?? [])),
                // MARKER-SYNC-PROGRESS: a refresh running (or just finished) for this box
                'progress'  => \App\Support\DistributorSyncProgress::state((string) $tenant->id, $code),
                'linked'    => TenantInventoryItemVendor::query()
                    ->where('distributor_code', $code)
                    ->whereNotNull('distributor_catalog_id')
                    ->whereHas('item', fn ($q) => $q->where('tenant_id', $tenant->id))
                    ->count(),
            ];
        }

        // make the stored numbers say what the order
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
     * saves one distributor's box.
     *
     * The credential fields differ per distributor, so the submitted values
     * go through the registry, which knows how to collapse them into the
     * single stored api_key (BTI joins username and password with a colon).
     * A blank credential means "leave what's stored alone", so a shop can
     * change its priority without re-typing a key it can't read back.
     */
    /**
     * turn a distributor on or off for this shop.
     * Turning OFF keeps the stored credentials, so pausing does not mean
     * re-entering an API key later.
     */
    public function toggleDistributor(\Illuminate\Http\Request $request)
    {
        $this->guard();

        $data = $request->validate([
            'code'    => ['required', 'string', 'max:32'],
            'enabled' => ['nullable'],
        ]);

        $code = strtoupper($data['code']);
        if (! in_array($code, app(\App\Services\Distributors\DistributorRegistry::class)->supported(), true)) {
            abort(404);
        }

        $sub = TenantDistributorCatalogSubscription::firstOrCreate(
            ['tenant_id' => tenant()->id, 'distributor_code' => $code],
            ['is_active' => false],
        );

        $on = (bool) $request->boolean('enabled');
        $sub->update(['is_active' => $on]);

        // Intake holds the login for these, so switching on IS connecting:
        // store the marker the registry resolves to the platform login.
        $registry = app(\App\Services\Distributors\DistributorRegistry::class);
        if ($registry->platformSupplied($code)) {
            if ($on) {
                $creds = (array) ($sub->credentials_encrypted ?? []);
                $creds['api_key'] = \App\Services\Distributors\DistributorRegistry::PLATFORM_MARK;
                $creds['region']  = $creds['region'] ?? 'us';
                $sub->credentials_encrypted = $creds;
                $sub->save();
            }
            return back()->with('success', $registry->label($code) . ($on
                ? ' turned on. Your cost and availability come through on the next sync.'
                : ' turned off. Turn it back on any time.'));
        }

        return back()->with('success', $on
            ? $code . ' turned on. Add your account key to unlock your cost and availability.'
            : $code . ' turned off. Your key is kept — turn it back on any time.');
    }

    public function saveKey(Request $request): RedirectResponse
    {
        $this->guard();
        $data = $request->validate([
            'distributor_code' => ['required', 'string', 'max:32'],
            'api_key'          => ['nullable', 'string', 'max:255'],
            // QBP's second key. Absent from this list it
            // was stripped before packCredentials ever saw it, so the field
            // rendered, accepted a paste, and saved nothing.
            'cls_key'          => ['nullable', 'string', 'max:255'],
            'username'         => ['nullable', 'string', 'max:128'],
            'password'         => ['nullable', 'string', 'max:255'],
            'account_number'   => ['nullable', 'string', 'max:64'],
            'vendor_id'        => ['nullable', 'string', 'max:64'],
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

        // hand the stored value in so a blank field
        // keeps its part instead of discarding the whole credential.
        $packed = $registry->packCredentials($code, $data, $before);
        if ($packed !== null) {
            $creds['api_key'] = $packed;
            $creds['region'] = $creds['region'] ?? 'us';
        }

        // if another vendor is already carrying this
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

        // say plainly when the credential didn't move.
        // Reporting "saved" on a no-op is what hid the discarded password.
        $after = (string) ($sub->credentials_encrypted['api_key'] ?? '');
        $label = $registry->label($code);

        if ($before !== '' && $after === $before) {
            return back()->with('success', $label . ' updated. The saved credential is unchanged.');
        }

        return back()->with('success', $label . ' credentials saved. Test to confirm access.');
    }

    /**
     * move a distributor up or down the data order.
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
     * show what absorbing the old vendor will do.
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

    /** which list price this distributor's new items start at. */
    public function savePricing(Request $request): RedirectResponse
    {
        $this->guard();
        $data = $request->validate([
            'distributor_code' => ['required', 'string', 'max:32'],
            'price_seed'       => ['required', 'in:msrp,map'],
        ]);

        TenantDistributorCatalogSubscription::where('tenant_id', tenant()->id)
            ->where('distributor_code', strtoupper($data['distributor_code']))
            ->update(['price_seed' => $data['price_seed'], 'updated_at' => now()]);

        return back()->with('success', strtoupper($data['distributor_code']) . ': new items will start at '
            . strtoupper($data['price_seed']) . '.');
    }

    /**
     * Shop switches that are not credentials, kept with the subscription's
     * credentials so the adapter receives them. Only listed options exist.
     */
    public const OPTIONS = [
        // JBI's Florida Closeout warehouse (111). Off by default: closeout
        // stock is limited and often end-of-line.
        'JBI' => ['include_closeout'],
    ];

    /**
     * MARKER-BOX-WAREHOUSES: the Warehouses list in a distributor box. JBI's
     * closeout warehouse is always listed: it only reaches the feed when the
     * shop includes it, so its switch is that option.
     */
    private function boxWarehouses(\App\Models\Tenant $tenant, string $code, array $options): array
    {
        $box = \App\Services\Distributors\WarehouseRegistry::forBox($tenant, $code, \App\Support\PreferredWarehouses::for($tenant, $code));
        if ($code === 'JBI') {
            $closeoutOn = ! empty($options['include_closeout']);
            $cc = \App\Services\Distributors\JbiClient::CLOSEOUT;
            $found = false;
            foreach ($box['rows'] as &$r) {
                if ($r['code'] === $cc) { $r['closeout'] = true; $r['counts'] = $closeoutOn && $r['counts']; $found = true; }
            }
            unset($r);
            if (! $found) {
                $box['rows'][] = [
                    'id' => null, 'code' => $cc, 'full_name' => 'JBI — ' . \App\Services\Distributors\JbiClient::WAREHOUSES[$cc][1],
                    'name' => \App\Models\DistributionWarehouse::defaultLabel('JBI — ' . \App\Services\Distributors\JbiClient::WAREHOUSES[$cc][1], $cc),
                    'in_stock' => 0, 'counts' => $closeoutOn, 'ship' => null, 'ship_pos' => null, 'closeout' => true,
                ];
            }
        }
        $box['closeout_on'] = $code === 'JBI' && ! empty($options['include_closeout']);
        $box['counted'] = count(array_filter($box['rows'], fn ($r) => $r['counts']));
        return $box;
    }

    /** "Count in my stock" for one warehouse (the register's star, from the box). */
    public function warehouseCount(Request $request): \Illuminate\Http\JsonResponse
    {
        $this->guard();
        $data = $request->validate([
            'distributor_code' => ['required', 'string', 'max:32'],
            'warehouse'        => ['required', 'string', 'max:60'],
            'on'               => ['required', 'boolean'],
        ]);
        $code = strtoupper($data['distributor_code']);
        abort_unless(app(DistributorRegistry::class)->isSupported($code), 404);
        $tenant = tenant();
        $sub = TenantDistributorCatalogSubscription::where('tenant_id', $tenant->id)->where('distributor_code', $code)->first();
        $box = $this->boxWarehouses($tenant, $code, (array) (((array) ($sub?->credentials_encrypted ?? []))['options'] ?? []));

        // the closeout row's switch is the include option, not a star
        $codes = array_column(array_filter($box['rows'], fn ($r) => empty($r['closeout']) || $box['closeout_on']), 'code');
        abort_unless(in_array($data['warehouse'], array_column($box['rows'], 'code'), true), 404);
        abort_if($code === 'JBI' && $data['warehouse'] === \App\Services\Distributors\JbiClient::CLOSEOUT, 422); // its switch is the include option

        $prefs = \App\Support\PreferredWarehouses::count($tenant, $code, $data['warehouse'], (bool) $data['on'], $codes);
        if ($prefs === null) {
            return response()->json(['ok' => false, 'message' => 'At least one warehouse has to count. Switch another on first.'], 422);
        }
        return response()->json(['ok' => true]);
    }

    /** "Ship from here": may online orders ship from this warehouse. */
    public function warehouseShip(Request $request): \Illuminate\Http\JsonResponse
    {
        $this->guard();
        $data = $request->validate([
            'warehouse_id' => ['required', 'string', 'max:64'],
            'on'           => ['required', 'boolean'],
        ]);
        $ok = \App\Services\Distributors\WarehouseRegistry::setShopChoice(tenant(), $data['warehouse_id'], (bool) $data['on']);
        return $ok
            ? response()->json(['ok' => true])
            : response()->json(['ok' => false, 'message' => 'Shipping from this warehouse is not available to your shop.'], 422);
    }

    public function saveOption(Request $request): RedirectResponse|\Illuminate\Http\JsonResponse
    {
        $this->guard();
        $data = $request->validate([
            'distributor_code' => ['required', 'string', 'max:32'],
            'option'           => ['required', 'string', 'max:64'],
            'value'            => ['required', 'in:0,1'],
        ]);
        $code = strtoupper($data['distributor_code']);
        abort_unless(in_array($data['option'], self::OPTIONS[$code] ?? [], true), 404);

        $sub = TenantDistributorCatalogSubscription::where('tenant_id', tenant()->id)
            ->where('distributor_code', $code)->firstOrFail();
        $creds = (array) ($sub->credentials_encrypted ?? []);
        $creds['options'] = array_merge((array) ($creds['options'] ?? []), [$data['option'] => $data['value'] === '1']);
        $sub->credentials_encrypted = $creds;
        $sub->save();

        $msg = 'Saved.';
        if ($data['option'] === 'include_closeout') {
            // a shop that counts only some JBI warehouses: closeout joins or
            // leaves that list with the switch
            $cc = \App\Services\Distributors\JbiClient::CLOSEOUT;
            if (\App\Support\PreferredWarehouses::for(tenant(), $code)) {
                \App\Support\PreferredWarehouses::star(tenant(), $code, $cc, $data['value'] === '1');
            }
            $msg = $data['value'] === '1'
                ? 'Florida Closeout stock is counted in your JBI stock from the next refresh.'
                : 'Florida Closeout stock is left out of your JBI stock from the next refresh.';
        }
        return $request->wantsJson()
            ? response()->json(['ok' => true, 'message' => $msg])
            : back()->with('success', $msg);
    }

    /** where this distributor's items sit today. */
    public function pricingCheck(Request $request, \App\Services\Distributors\DistributorPricingService $pricing): \Illuminate\Http\JsonResponse
    {
        $this->guard();
        $code = strtoupper((string) $request->query('code', ''));
        $sub = TenantDistributorCatalogSubscription::where('tenant_id', tenant()->id)
            ->where('distributor_code', $code)->first();
        if (! $sub) {
            return response()->json(['ok' => false, 'error' => 'Not connected.'], 404);
        }

        $target = $sub->price_seed ?: 'map';

        return response()->json([
            'ok'      => true,
            'code'    => $code,
            'running' => cache()->has(\App\Jobs\SweepDistributorPricingJob::runningKey(tenant()->id, $code)),
            'stats'   => $pricing->stats(tenant()->id, $code, $target),
        ]);
    }

    /** move every untouched price to the chosen list price. */
    public function pricingSweep(Request $request, \App\Services\Distributors\DistributorPricingService $pricing): RedirectResponse
    {
        $this->guard();
        $data = $request->validate(['distributor_code' => ['required', 'string', 'max:32']]);
        $code = strtoupper($data['distributor_code']);
        $sub = TenantDistributorCatalogSubscription::where('tenant_id', tenant()->id)
            ->where('distributor_code', $code)->firstOrFail();
        $target = $sub->price_seed ?: 'map';

        $n = $pricing->stats(tenant()->id, $code, $target)['change'];
        if ($n === 0) {
            return back()->with('error', 'Nothing to change — every untouched price is already at ' . strtoupper($target) . '.');
        }

        cache()->put(\App\Jobs\SweepDistributorPricingJob::runningKey(tenant()->id, $code), true, now()->addHours(2));
        \App\Jobs\SweepDistributorPricingJob::dispatch(
            tenant()->id, $code, $target,
            optional($request->user())->id, optional($request->user())->email,
        );

        return back()->with('success', $code . ': moving ' . number_format($n) . ' prices to '
            . strtoupper($target) . ' in the background. It can be put back from Catalog changes.');
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

        // move it in the list, then renumber the whole
        // list 1..N. Swapping the two stored values kept the order right but
        // left the numbers arbitrary (two defaults both at 50, or a 1 beside
        // a 50), so the stored value never stated the position.
        $list = $subs->values()->all();
        [$list[$i], $list[$j]] = [$list[$j], $list[$i]];

        $this->renumber(collect($list));

        return back();
    }

    /**
     * the tenant's subscriptions in priority order,
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
     * rewrite priorities as 1..N in their current
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
     * the distributor the request is about.
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
        // was self::CODE, so every box tested HLC.
        [$code, $label, $sub] = $this->requestedSub($request);

        $creds = (array) ($sub->credentials_encrypted ?? []);
        if (blank($creds['api_key'] ?? null)) {
            return back()->with('error', 'Enter your ' . $label . ' credentials first.');
        }

        try {
            // through the subscription, so the account number and the shop's
            // own switches reach the adapter exactly as a sync would pass them
            $adapter = app(DistributorRegistry::class)->forSubscription($sub);
            $res = $adapter->testConnection();
            $ok = (bool) ($res['ok'] ?? false);

            // every failure used to be recorded as
            // auth_failed, so a 503, a timeout or DNS trouble all displayed as
            // "credentials rejected" and sent someone to re-enter a password
            // that was already correct.
            $status = (int) ($res['status'] ?? 0);
            $isAuth = $res['auth'] ?? in_array($status, [401, 403], true);

            $sub->last_sync_status = $ok
                ? 'connected'
                : ($isAuth ? 'auth_failed' : 'unreachable');
            $sub->save();

            // JBI checks two things (FTP files and the stock API) and says
            // which of them answered; its own words beat a status code
            if ($code === 'JBI' && filled($res['body'] ?? null)) {
                return back()->with($ok ? 'success' : 'error', $label . ': ' . $res['body']);
            }

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

    // this one distributor only: cost, stock and warehouses for its linked
    // items. The page follows the run live (syncProgress), so the answer is a
    // run id for the box to watch, not "give it a minute".
    public function refreshSync(Request $request): RedirectResponse|\Illuminate\Http\JsonResponse
    {
        $this->guard();
        [$code, $label, $sub] = $this->requestedSub($request);

        $creds = (array) ($sub->credentials_encrypted ?? []);
        $refuse = ! $sub->is_active ? 'Turn ' . $label . ' on before refreshing it.'
            : (blank($creds['api_key'] ?? null) ? 'Connect ' . $label . ' before refreshing.' : null);
        if ($refuse) {
            return $request->wantsJson()
                ? response()->json(['ok' => false, 'message' => $refuse], 422)
                : back()->with('error', $refuse);
        }

        $runId = $this->startRun($code);
        if ($request->wantsJson()) {
            return response()->json(['ok' => true, 'run' => $runId,
                'progress' => \App\Support\DistributorSyncProgress::state((string) tenant()->id, $code)]);
        }
        return back()->with('success', 'Refreshing ' . $label . ' — progress shows in its box.');
    }

    /**
     * Queue a logged run (one distributor, or all of them when $code is null)
     * and mark it queued so the page shows it before a worker picks it up.
     */
    private function startRun(?string $code, bool $dry = false, string $trigger = 'manual', int $delaySeconds = 0): string
    {
        $runId = (string) \Illuminate\Support\Str::uuid();
        $tenantId = (string) tenant()->id;
        \App\Support\DistributorSyncProgress::queued($tenantId, $runId, $code);
        $job = \App\Jobs\RunTenantDistributorSyncJob::dispatch($tenantId, $dry, $trigger, $runId, $code);
        if ($delaySeconds > 0) {
            $job->delay(now()->addSeconds($delaySeconds));
        }
        return $runId;
    }

    /** MARKER-SYNC-PROGRESS: what a box (?code=) or Catalog attention (no code) shows now. */
    public function syncProgress(Request $request): \Illuminate\Http\JsonResponse
    {
        $this->guard();
        $code = strtoupper(trim((string) $request->query('code', '')));
        if ($code !== '') {
            abort_unless(app(DistributorRegistry::class)->isSupported($code), 404);
        }
        return response()->json(\App\Support\DistributorSyncProgress::state((string) tenant()->id, $code ?: null));
    }

    public function import(\Illuminate\Http\Request $request): \Illuminate\Contracts\View\View
    {
        $this->guard();

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

        $code = $this->importCode($data['code'] ?? null);

        $filters = array_filter([
            'brand'              => $data['brand'] ?? null,
            'category'           => $data['category'] ?? null,
            'include_unsellable' => ! empty($data['include_unsellable']),
        ], fn ($v) => $v !== null && $v !== '' && $v !== false);

        $view = $this->importFilterOptions($code, $filters['brand'] ?? null);
        $view['filters'] = $filters;
        $view['mode'] = $data['mode'];

        // no brand and no category now means the
        // WHOLE catalog. The old guard refused with view()->with('error'),
        // which sets a view variable while the view reads session('error') —
        // so the refusal rendered as nothing at all.
        $importer = app(\App\Services\Distributors\DistributorCatalogImportService::class);
        $total    = $importer->candidateCount($code, $filters, tenant()->id);

        if ($total === 0) {
            $view['error'] = 'Nothing in the ' . $code . ' catalog matches those filters.';
            return view('tenant.distributors.import', $view);
        }

        if ($data['mode'] !== 'commit') {
            // the preview runs the import itself as a dry run, every match
            // test included, so "new" means new: an item the shop already
            // carries from another distributor shows as getting a second
            // source, not as an import. The row-by-row plan is kept briefly
            // for the list under the counts.
            $plan = $importer->plan(tenant()->id, $code, $filters);
            $key  = 'import_plan:' . tenant()->id . ':' . sha1($code . json_encode($filters));
            \Illuminate\Support\Facades\Cache::put($key, $plan['rows'], now()->addMinutes(30));

            $c = $plan['counts'];
            $view['result'] = [
                'matched_catalog' => $total,
                'created'         => $c['new'],
                'merged'          => $c['merge'],
                'skipped'         => $c['linked'],
                'by_barcode'      => $c['by_barcode'],
                'by_part'         => $c['by_part'],
                'by_sku'          => $c['by_sku'],
                'dry_run'         => true,
                'candidate_total' => $total,
            ];
            $view['planKey'] = $key;

            return view('tenant.distributors.import', $view);
        }

        // Commit: queued, however large.
        $batch = \App\Models\Tenant\CatalogChangeBatch::create([
            'tenant_id'      => tenant()->id,
            'action'         => 'import',
            'filter'         => $filters + ['code' => $code],
            'item_count'     => 0,
            'run_by'         => auth('tenant')->id(),
            'status'         => 'running',
            'progress_done'  => 0,
            'progress_total' => $total,
            'progress_stage' => 'importing',
        ]);

        \App\Jobs\ImportDistributorCatalogJob::dispatch(tenant()->id, $code, $filters, $batch->id);

        $view['queued'] = ['total' => $total, 'code' => $code];

        return view('tenant.distributors.import', $view);
    }

    /**
     * One page of the preview's row-by-row plan: what Import would do with
     * each catalog row, searchable so a shop can check a specific product.
     */
    public function importPlan(\Illuminate\Http\Request $request): \Illuminate\Http\JsonResponse
    {
        $this->guard();
        $key = (string) $request->query('key', '');
        // a key is only ever this shop's own plan
        abort_unless(str_starts_with($key, 'import_plan:' . tenant()->id . ':'), 404);

        $rows = \Illuminate\Support\Facades\Cache::get($key);
        if (! is_array($rows)) {
            return response()->json(['ok' => false, 'error' => 'This preview has expired. Press Preview again.'], 410);
        }

        $tab = (string) $request->query('tab', 'all');
        $q   = mb_strtolower(trim((string) $request->query('q', '')));
        $qd  = preg_replace('/[^a-z0-9]/', '', $q);

        $rows = array_values(array_filter($rows, function ($r) use ($tab, $q, $qd) {
            if (in_array($tab, ['new', 'merge', 'linked'], true) && $r['action'] !== $tab) {
                return false;
            }
            if ($q === '') {
                return true;
            }
            $hay = mb_strtolower($r['name'] . ' ' . $r['brand'] . ' ' . ($r['item_name'] ?? ''));
            if (str_contains($hay, $q)) {
                return true;
            }
            // part numbers and barcodes match with or without dashes, dots or spaces
            foreach ([$r['part'], $r['mpn'], $r['code']] as $id) {
                $idd = preg_replace('/[^a-z0-9]/', '', mb_strtolower((string) $id));
                if ($qd !== '' && $idd !== '' && str_contains($idd, $qd)) {
                    return true;
                }
            }
            return false;
        }));

        $per   = 50;
        $total = count($rows);
        $pages = max(1, (int) ceil($total / $per));
        $page  = max(1, min($pages, (int) $request->query('page', 1)));
        $slice = array_slice($rows, ($page - 1) * $per, $per);

        foreach ($slice as &$r) {
            $r['item_url'] = ($r['item'] && $r['item'] !== 'dry') ? route('tenant.inventory.show', $r['item']) : null;
        }
        unset($r);

        return response()->json(['ok' => true, 'rows' => $slice, 'total' => $total, 'page' => $page, 'pages' => $pages]);
    }

    /**
     * Import one catalog row now, from the preview list. Runs the same import
     * as the bulk button, so it still joins an item the shop already carries
     * instead of duplicating it.
     */
    public function importOne(\Illuminate\Http\Request $request): \Illuminate\Http\JsonResponse
    {
        $this->guard();
        $data = $request->validate([
            'code' => ['required', 'string', 'max:32'],
            'id'   => ['required', 'string', 'max:64'],
            'key'  => ['nullable', 'string', 'max:200'],
        ]);
        $code = $this->importCode($data['code']);

        $res = app(\App\Services\Distributors\DistributorCatalogImportService::class)
            ->import(tenant()->id, $code, ['ids' => [$data['id']], 'include_unsellable' => true]);

        if (($res['matched_catalog'] ?? 0) === 0) {
            return response()->json(['ok' => false, 'error' => 'That item is not available to import. Its brand may be switched off for your shop.'], 422);
        }
        if (! empty($res['errors'])) {
            return response()->json(['ok' => false, 'error' => 'That item could not be added. It has been reported.'], 500);
        }

        $itemId = \App\Models\Tenant\TenantInventoryItem::query()->where('tenant_id', tenant()->id)
            ->where(function ($w) use ($data) {
                $w->where('distributor_catalog_id', $data['id'])
                  ->orWhereHas('vendors', fn ($v) => $v->where('tenant_inventory_item_vendors.distributor_catalog_id', $data['id']));
            })->value('id');

        // the cached plan learns it, so paging back shows it as linked
        $key = (string) ($data['key'] ?? '');
        if ($itemId && str_starts_with($key, 'import_plan:' . tenant()->id . ':') && is_array($rows = \Illuminate\Support\Facades\Cache::get($key))) {
            foreach ($rows as &$r) {
                if ($r['id'] === $data['id']) { $r['action'] = 'linked'; $r['item'] = (string) $itemId; }
            }
            unset($r);
            \Illuminate\Support\Facades\Cache::put($key, $rows, now()->addMinutes(30));
        }

        // the bulk import refreshes stock when it finishes; one item must too,
        // or it sits with no cost or stock from this distributor until tonight.
        // A refresh covers every linked item, so items imported one after
        // another share one: it waits 30 seconds, and a refresh still waiting
        // to start picks up the ones added meanwhile.
        if (($res['created'] ?? 0) + ($res['merged'] ?? 0) > 0
            && \App\Support\DistributorSyncProgress::state((string) tenant()->id, $code)['state'] !== 'queued') {
            $this->startRun($code, false, 'import', 30);
        }

        $what = ($res['created'] ?? 0) > 0 ? 'created' : (($res['merged'] ?? 0) > 0 ? 'merged' : 'linked');
        return response()->json(['ok' => true, 'did' => $what,
            'url' => $itemId ? route('tenant.inventory.show', $itemId) : null]);
    }

    /**
     * which brands this shop is offered on its import screen,
     * per distributor. Turning a brand off only decides what can be IMPORTED
     * next: nothing already in the shop's inventory is touched, so anything
     * in stock stays and stays sellable.
     */
    public function brands(\Illuminate\Http\Request $request): \Illuminate\Contracts\View\View
    {
        $this->guard();

        $bc       = app(\App\Services\Distributors\BrandControl::class);
        $registry = app(\App\Services\Distributors\DistributorRegistry::class);

        $panels = [];
        foreach ($this->importableCodes() as $code) {
            $panels[] = [
                'code'   => $code,
                'label'  => $registry->label($code),
                'brands' => $bc->brandsForTenant(tenant()->id, $code),
            ];
        }

        return view('tenant.distributors.brands', ['panels' => $panels]);
    }

    public function saveBrands(\Illuminate\Http\Request $request): \Illuminate\Http\RedirectResponse
    {
        $this->guard();

        $data = $request->validate([
            'code'  => ['required', 'string', 'max:32'],
            'on'    => ['nullable', 'array'],
            'on.*'  => ['string', 'max:128'],
        ]);

        // refuses a distributor the shop has not connected, same as import.
        $code = $this->importCode($data['code']);

        $bc  = app(\App\Services\Distributors\BrandControl::class);
        $on  = array_flip(array_map(fn ($k) => \App\Services\Distributors\BrandControl::key($k), $data['on'] ?? []));
        $who = (string) auth('tenant')->id();

        $changed = 0;
        foreach ($bc->brandsForTenant(tenant()->id, $code) as $b) {
            if ($b['locked']) {
                continue;   // set by Intake support; the shop cannot change it
            }
            $want = isset($on[$b['key']]);
            if ($want !== $b['enabled']) {
                $bc->setTenant(tenant()->id, $code, $b['name'], $want, 'tenant', $who);
                $changed++;
            }
        }

        return back()->with('success', $changed === 0
            ? 'No changes to save.'
            : app(\App\Services\Distributors\DistributorRegistry::class)->label($code) . ' brands saved. Items you already carry are untouched.');
    }

    /** Brand / category options + catalog size for the import filter. */
    /**
     * brands, categories and the item count for
     * ONE distributor. Was pinned to self::CODE, which left a shop with BTI
     * connected unable to import any of its 24,643 items.
     */
    private function importFilterOptions(string $code, ?string $brand = null): array
    {
        $base = \App\Models\PlatformDistributorCatalog::query()
            ->where('distributor_code', $code)->where('is_active', true);
        // brands switched off by the platform or by this shop are not offered.
        app(\App\Services\Distributors\BrandControl::class)->constrain($base, $code, tenant()->id);

        return [
            'importCode' => $code,
            'importCodes' => $this->importableCodes(),
            'brands' => (clone $base)->whereNotNull('manufacturer')->whereRaw("TRIM(manufacturer) <> ''")
                ->distinct()->orderBy('manufacturer')->pluck('manufacturer'),
            // categories narrow to the chosen brand so
            // the picker never offers a category the brand has no items in.
            'categories' => (clone $base)->whereNotNull('category')->whereRaw("TRIM(category) <> ''")
                ->when($brand !== null && $brand !== '', fn ($q) => $q->where('manufacturer', $brand))
                ->distinct()->orderBy('category')->pluck('category'),
            'catalogTotal' => (clone $base)->count(),
        ];
    }

    /** categories for one brand, for the live picker. */
    public function importCategories(\Illuminate\Http\Request $request): \Illuminate\Http\JsonResponse
    {
        $this->guard();

        $code  = $this->importCode($request->query('code'));
        $brand = trim((string) $request->query('brand', ''));

        $catQuery = \App\Models\PlatformDistributorCatalog::query()
            ->where('distributor_code', $code)->where('is_active', true);
        app(\App\Services\Distributors\BrandControl::class)->constrain($catQuery, $code, tenant()->id);

        $categories = $catQuery
            ->whereNotNull('category')->whereRaw("TRIM(category) <> ''")
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

        // only distributors THIS shop has connected. The
        // platform catalog is shared, so without this a shop was offered
        // catalogs (and dealer cost, MAP and MSRP) for accounts it does not
        // hold. Credentials as well as the toggle: the switch alone is the
        // shop's intent, the key is the evidence.
        $connected = \App\Models\Tenant\TenantDistributorCatalogSubscription::query()
            ->where('tenant_id', tenant()->id)
            ->where('is_active', true)
            ->get(['distributor_code', 'credentials_encrypted'])
            ->filter(function ($s) {
                $creds = (array) ($s->credentials_encrypted ?? []);
                return filled($creds['api_key'] ?? null);
            })
            ->pluck('distributor_code')
            ->map(fn ($c) => strtoupper($c))
            ->all();

        if (! $connected) {
            return [];
        }

        return \App\Models\PlatformDistributorCatalog::query()
            ->whereIn('distributor_code', $supported)
            ->whereIn('distributor_code', $connected)
            ->where('is_active', true)
            ->select('distributor_code')->distinct()
            ->orderBy('distributor_code')
            ->pluck('distributor_code')->all();
    }

    /**
     * The distributor being imported from, defaulting to the first available.
     *
     * every import path (the screen, importRun, the
     * category picker) resolves its code through here, so refusing an
     * unconnected distributor here refuses it everywhere. A hidden option is
     * not a gate; a crafted POST must fail too.
     */
    private function importCode(?string $requested): string
    {
        $codes = $this->importableCodes();

        if ($requested !== null && $requested !== ''
            && ! in_array(strtoupper($requested), array_map('strtoupper', $codes), true)) {
            abort(403, 'Your shop has not connected that distributor.');
        }
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

        // reason priority in SQL so pagination keeps
        // the queue order, then newest first within a reason.
        $rankSql = "CASE reason WHEN 'title_changed' THEN 0 WHEN 'below_map' THEN 1 WHEN 'off_msrp' THEN 2"
            . " WHEN 'map_vanished' THEN 3 WHEN 'msrp_vanished' THEN 4 WHEN 'cost_vanished' THEN 5 ELSE 9 END";
        $perPage = (int) $request->query('per', 100);
        if (! in_array($perPage, [50, 100, 250], true)) {
            $perPage = 100;
        }
        $flags = $q->orderByRaw($rankSql)->orderByDesc('created_at')
            ->paginate($perPage)->withQueryString();

        // chip counts reflect ALL open flags (not the
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
            'details'   => (int) ($allBy['details_changed'] ?? 0), // was uncounted, hiding thousands from the header
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

        // latest sync run for the header line
        $lastSyncRun = \Illuminate\Support\Facades\DB::table('tenant_distributor_sync_runs')
            ->where('tenant_id', $tenant->id)->orderByDesc('started_at')->first();

        // the nightly check logs to its own table; without it "Last checked"
        // showed the last button press even when last night's check ran
        $lastNightly = null;
        try {
            $lastNightly = \Illuminate\Support\Facades\DB::table('distributor_sync_runs')
                ->where('tenant_id', $tenant->id)->where('status', 'done')->orderByDesc('finished_at')
                ->first(['finished_at', 'processed']);
        } catch (\Throwable) { /* table not migrated yet */ }
        $syncProgress = \App\Support\DistributorSyncProgress::state((string) $tenant->id);
        $nightlyAt = \Carbon\Carbon::parse('06:30', config('app.timezone', 'UTC'));

        // shown in the page legend.
        $titleThresholdPct = (int) round(((float) config('distributors.title_change_min_ratio', 0.15)) * 100);

        return view('tenant.distributors.attention', compact(
            'flags', 'counts', 'stock', 'filters', 'brandOptions', 'categoryOptions', 'lastSyncRun', 'perPage', 'titleThresholdPct',
            'lastNightly', 'syncProgress', 'nightlyAt'
        ));
    }

    /**
     * POST /attention/sync
     * Queue a tenant distributor sync (real or dry-run) and bounce back;
     * the page shows the run row when the worker finishes.
     */
    public function attentionSync(\Illuminate\Http\Request $request): \Illuminate\Http\RedirectResponse|\Illuminate\Http\JsonResponse
    {
        $this->guard();
        $tenant = tenant();
        $dry = $request->input('mode') === 'dry';
        $runId = $this->startRun(null, $dry);
        if ($request->wantsJson()) {
            return response()->json(['ok' => true, 'run' => $runId,
                'progress' => \App\Support\DistributorSyncProgress::state((string) $tenant->id)]);
        }
        return back()->with('success', $dry
            ? 'Preview started — progress shows above.'
            : 'Check started — progress shows above.');
    }

    /**
     * what bulk changes have been made, and undoing them.
     */
    public function catalogHistory(\Illuminate\Http\Request $request)
    {
        $this->guard();

        $batches = \App\Models\Tenant\CatalogChangeBatch::where('tenant_id', tenant()->id)
            ->orderByDesc('created_at')
            ->limit(60)
            ->get();

        return view('tenant.distributors.history', compact('batches'));
    }

    public function catalogHistoryShow(\Illuminate\Http\Request $request, string $batchId)
    {
        $this->guard();

        $batch = \App\Models\Tenant\CatalogChangeBatch::where('tenant_id', tenant()->id)
            ->findOrFail($batchId);

        // The table is capped: a 4,520-item batch is not a page anyone reads to
        // the end of, and loading it whole helps nobody.
        $rows = \App\Models\Tenant\CatalogChangeItem::where('batch_id', $batch->id)
            ->orderBy('id')
            ->limit(200)
            ->get();

        $items = \App\Models\Tenant\TenantInventoryItem::whereIn('id', $rows->pluck('item_id'))
            ->get()->keyBy('id');

        return view('tenant.distributors.history-show', compact('batch', 'rows', 'items'));
    }

    public function catalogUndo(\Illuminate\Http\Request $request, string $batchId)
    {
        $this->guard();

        $batch = \App\Models\Tenant\CatalogChangeBatch::where('tenant_id', tenant()->id)
            ->findOrFail($batchId);

        if (! $batch->isReversible()) {
            return back()->with('error', 'That batch cannot be undone.');
        }

        $result = (new \App\Services\Tenant\CatalogChangeReverser($batch))->revert();

        $msg = number_format($result['restored']) . ' put back';
        if ($result['kept'] > 0) {
            $msg .= ' · ' . number_format($result['kept']) . ' kept because they were edited since';
        }

        return back()->with('success', $msg);
    }

    public function catalogUndoItem(\Illuminate\Http\Request $request, string $batchId, string $itemId)
    {
        $this->guard();

        $batch = \App\Models\Tenant\CatalogChangeBatch::where('tenant_id', tenant()->id)
            ->findOrFail($batchId);

        $result = (new \App\Services\Tenant\CatalogChangeReverser($batch))->revert($itemId);

        return back()->with(
            $result['restored'] ? 'success' : 'error',
            $result['restored'] ? 'Put back.' : 'Left alone — it has been edited since.'
        );
    }

    public function attentionResolve(\Illuminate\Http\Request $request): \Illuminate\Http\RedirectResponse
    {
        $this->guard();
        $data = $request->validate([
            'action'     => ['required', 'in:raise_map,match_msrp,acknowledge,adopt_title,keep_title,adopt_details,keep_details'],
            'flag_ids'   => ['nullable', 'array'],
            'row_flag'   => ['nullable', 'string'], // per-row one-click action
            'flag_ids.*' => ['string'],
            'select_all' => ['nullable', 'boolean'],
            'f_brand'    => ['nullable', 'string', 'max:128'],
            'f_category' => ['nullable', 'string', 'max:64'],
            'f_reason'   => ['nullable', 'string', 'max:32'],
            'f_stock'    => ['nullable', 'string', 'in:all,in,out'],
        ]);

        $action = $data['action'];

        // a row button targets exactly one flag,
        // regardless of checkboxes or the apply-all toggle.
        if (filled($data['row_flag'] ?? null)) {
            $data['flag_ids'] = [$data['row_flag']];
            $request->merge(['select_all' => false]);
        }

        // the same work, in a service both this
        // request and the queued job call. Over a couple of hundred flags it
        // cannot finish inside a request (nginx cuts it at 60s), so it queues.
        $sel = [
            'select_all' => $request->boolean('select_all'),
            'f_brand'    => $data['f_brand'] ?? null,
            'f_category' => $data['f_category'] ?? null,
            'f_reason'   => $data['f_reason'] ?? null,
            'f_stock'    => $data['f_stock'] ?? 'all',
            'flag_ids'   => $data['flag_ids'] ?? [],
        ];

        $resolver = app(\App\Services\Tenant\PricingAttentionResolver::class);
        $count = $resolver->query(tenant()->id, $sel)->count();
        if ($count === 0) {
            return back()->with('error', 'Nothing selected.');
        }

        $verb = [
            'raise_map'     => 'Raised to MAP',
            'match_msrp'    => 'Matched to MSRP',
            'acknowledge'   => 'Dismissed',
            'adopt_title'   => 'Adopted new title',
            'keep_title'    => 'Kept your title',
            'adopt_details' => 'Adopted new details',
            'keep_details'  => 'Kept your details',
        ][$action];

        if ($count > 200) {
            cache()->put(\App\Jobs\ResolvePricingAttentionJob::runningKey(tenant()->id), true, now()->addHours(2));
            \App\Jobs\ResolvePricingAttentionJob::dispatch(
                tenant()->id, $action, $sel,
                optional($request->user())->id, optional($request->user())->email,
            );

            return back()->with('success', $verb . ': applying to ' . number_format($count)
                . ' items in the background. This list empties as they finish — refresh to see progress.');
        }

        $res = $resolver->run(
            tenant()->id, $action, $sel,
            optional($request->user())->id, optional($request->user())->email,
        );
        $msg = "{$verb}: {$res['applied']} item(s)." . ($res['skipped'] ? " {$res['skipped']} skipped." : '');

        return back()->with('success', $msg);
    }

    /**
     * What a saved secret looks like in its field's placeholder. It used to
     * show the first 8 and last 5 characters, so anything 13 characters or
     * shorter (most passwords) appeared in full. Short secrets now show only
     * that one is saved; long keys and tokens keep 4 at each end so a shop can
     * tell which key it is.
     */
    private function mask(?string $key): ?string
    {
        if (blank($key)) {
            return null;
        }
        $len = mb_strlen($key);
        if ($len < 24) {
            return 'saved · ' . str_repeat('•', 8);
        }
        return mb_substr($key, 0, 4) . str_repeat('•', 12) . mb_substr($key, -4);
    }
}
