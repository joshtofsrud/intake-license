<?php

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Models\Tenant\TenantInventoryItem;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * E-commerce settings center (was Storefront settings, Online Retail Wave 5b).
 * The tenant-facing control panel: Availability (see StorefrontAvailabilityController),
 * Fulfillment, Tax, Payments, Messages and General.
 * Config lives in settings['storefront'] — the same block OrderService
 * and the public storefront already read.
 */
class StorefrontSettingsController extends Controller
{
    private function guard(): void
    {
        abort_unless(tenant()->online_store_enabled, 404);
        $me = Auth::guard('tenant')->user();
        // MARKER-SEC-SECTIONS: Storefront is an area in Roles & access now
        abort_unless($me && $me->canAccessSection('storefront'), 403);
    }

    public function show()
    {
        $this->guard();
        $tenant = tenant();
        $s = (array) (($tenant->settings['storefront'] ?? []) ?: []);
        $oc = \App\Services\Tenant\OrderService::forTenant($tenant)->config();
        $w  = \App\Services\Tenant\OrderService::wording($tenant);
        $cents = fn ($c) => $c === null ? '' : number_format(((int) $c) / 100, 2, '.', '');

        $itemBase = TenantInventoryItem::query()
            ->where('tenant_id', $tenant->id)
            ->where('is_active', true);

        // MARKER-SHIPPING-RATES: how shipping is priced, in the units a shopkeeper thinks in
        $rc = \App\Services\Shipping\ShippingRates::config($tenant);
        $raw = (array) ($s['carriers'] ?? []);
        $ship = [
            'mode'       => $rc['mode'],
            'from_zip'   => (string) ($s['ship_from_zip'] ?? ''),
            'location_zip' => (string) ($tenant->defaultLocation?->postal_code ?? ''),
            'default_lb' => rtrim(rtrim(number_format($rc['default_oz'] / 16, 2, '.', ''), '0'), '.'),
            'boxes'      => array_map(fn ($b) => ['name' => $b['name'], 'l' => $b['l'], 'w' => $b['w'], 'h' => $b['h'],
                                'max_lb' => round($b['max_oz'] / 16, 2), 'tare_oz' => $b['tare_oz']], $rc['boxes']),
            'table'      => array_map(fn ($r) => ['upto_lb' => round($r['upto_oz'] / 16, 2), 'price' => $cents($r['cents'])], $rc['table']),
            'markup_pct' => $rc['markup_pct'] ?: '',
            'markup'     => $rc['markup_cents'] ? $cents($rc['markup_cents']) : '',
            'carriers'   => collect(\App\Services\Shipping\CarrierClient::registry())->map(function ($cls, $code) use ($rc, $raw) {
                $fields = [];
                foreach ($cls::fields() as $k => $f) {
                    $fields[$k] = $f + ['value' => empty($f['secret']) ? (string) ($raw[$code][$k] ?? '') : '', 'saved' => ! empty($f['secret']) && ($raw[$code][$k] ?? '') !== ''];
                }
                return ['label' => $cls::label(), 'enabled' => $rc['carriers'][$code]['enabled'], 'sandbox' => $rc['carriers'][$code]['sandbox'],
                        'services' => $rc['carriers'][$code]['services'], 'all_services' => $cls::services(), 'fields' => $fields];
            })->all(),
        ];

        return view('tenant.storefront.settings', [
            'ship' => $ship,
            'cfg' => [
                'enabled'             => (bool) ($s['enabled'] ?? true),
                'browse_layout'       => (string) ($s['browse_layout'] ?? 'chips'),
                'out_display'         => ($s['out_display'] ?? 'show') === 'hide' ? 'hide' : 'show',
                'share_on'            => (bool) ($s['share_on'] ?? true), // MARKER-ITEM-SEO
                // MARKER-GOOGLE-FEED
                'feed_on'             => (bool) ($s['feed_on'] ?? false),
                'ga_id'               => \App\Support\ShopGa4::id($tenant),
                'ga_ecom_on'          => (bool) (($tenant->settings ?? [])['analytics_ecom_on'] ?? true),
                'ga_has_secret'       => \App\Support\ShopGa4::secret($tenant) !== null,
                'ga_raw_id'           => (string) ($tenant->settings['analytics_ga4_id'] ?? ''),
                'feed_url'            => \App\Services\Feeds\GoogleFeed::url($tenant),
                'feed_ship'           => ($oc['ship_enabled'] ? $rc['mode'] : 'off'),
                'google_cats'         => \App\Models\Tenant\TenantInventoryCategory::where('tenant_id', $tenant->id)->orderBy('name')->get(['id', 'name', 'google_category'])->map(fn ($c) => ['id' => $c->id, 'name' => $c->name, 'g' => (string) $c->google_category])->all(),
                // fulfillment
                'pickup_enabled'      => $oc['pickup_enabled'],
                'pickup_instructions' => $oc['pickup_instructions'],
                'local_delivery'      => $oc['local_delivery'],
                'delivery_fee'        => $cents($oc['delivery_fee_cents']),
                'free_over'           => $cents($oc['free_delivery_over_cents']),
                'delivery_zips'       => implode(', ', $oc['delivery_zips']),
                // MARKER-ORDER-SHIPMENTS
                'ship_enabled'        => $oc['ship_enabled'],
                'ship_fee'            => $cents($oc['ship_fee_cents']),
                'ship_fee_supplier'   => $cents($oc['ship_fee_supplier_cents']),
                'ship_free_over'      => $cents($oc['ship_free_over_cents']),
                'install_offer'       => $oc['install_offer'],
                'install_label'       => (string) ($s['install_label'] ?? ''),
                'install_default'     => \App\Services\Tenant\OrderService::DEFAULT_INSTALL_LABEL,
                // tax / payments
                'tax_delivery_fee'    => $oc['tax_delivery_fee'],
                // MARKER-DESTINATION-TAX
                'tax_mode'            => $oc['tax_mode'],
                'stripe_tax_code'     => (string) ($s['stripe_tax_code'] ?? ''),
                'dp_enabled'          => (bool) $tenant->direct_payments_enabled,
                'tax_test_zip'        => \App\Services\Shipping\ShippingRates::zip((string) ($tenant->defaultLocation?->postal_code ?? '')),
                'hold_hours'          => $oc['hold_hours'],
                'auto_refund'         => $oc['auto_refund'],
                // messages + wording
                'ready_text'          => (string) ($s['ready_text'] ?? ''),
                'ready_default'       => '{shop_name}: Your order {order_number} is ready for pickup! Reply STOP to opt out.',
                'words'               => [
                    'stock_label'    => (string) ($s['stock_label'] ?? ''),
                    'special_label'  => (string) ($s['special_label'] ?? ''),
                    'out_label'      => (string) ($s['out_label'] ?? ''),
                    'special_notice' => (string) ($s['special_notice'] ?? ''),
                    'intro'          => (string) ($s['intro'] ?? ''),
                ],
                'words_default'       => $w,
            ],
            // MARKER-DISTRIBUTION-WAREHOUSES
            'dist' => [
                'access'     => \App\Services\Distributors\WarehouseRegistry::access($tenant),
                'cfg'        => \App\Services\Distributors\WarehouseRegistry::shopConfig($tenant),
                'warehouses' => \App\Services\Distributors\WarehouseRegistry::forShop($tenant),
            ],
            'info' => [
                'tax_rate'    => (float) ($tenant->default_tax_rate ?? 0),
                'exempt'      => (clone $itemBase)->where('tax_class_code', 'exempt')->count(),
                'card_ready'  => filled((new \App\Services\Tenant\DirectPaymentsService($tenant))->publishableKey()),
                'shop_name'   => $tenant->name,
            ],
            'counts' => [
                'online'   => (clone $itemBase)->where('show_online', true)->count(),
                'linkable' => (clone $itemBase)->whereNotNull('distributor_catalog_id')->count(),
                'active'   => (clone $itemBase)->count(),
                'out'      => (clone $itemBase)->where('show_online', true)->where('computed_stock_count', '<=', 0)->where('allow_oversell', false)->count(),
            ],
        ]);
    }

    /**
     * POST /storefront/carrier-test — MARKER-SHIPPING-RATES. Checks the carrier details
     * typed on the page (a blank secret falls back to the saved one) without saving them.
     */
    /** POST /storefront/tax-test — MARKER-DESTINATION-TAX. Does Stripe Tax answer for this shop? */
    public function testTax(Request $request)
    {
        $this->guard();
        $in = $request->validate(['zip' => ['required', 'regex:/^\d{5}$/'], 'state' => ['nullable', 'string', 'max:40']],
            ['zip.required' => 'Enter a ZIP code to test with.', 'zip.regex' => 'Enter a 5-digit ZIP code.']);
        if (! tenant()->direct_payments_enabled) {
            return response()->json(['ok' => false, 'message' => 'Card payments are not turned on, so Stripe Tax is not available yet.']);
        }
        return response()->json(\App\Services\Tenant\StripeTax::test(tenant(), $in['zip'], strtoupper(trim((string) ($in['state'] ?? '')))));
    }

    public function testCarrier(Request $request)
    {
        $this->guard();
        $tenant = tenant();
        $code = (string) $request->input('carrier');
        $cls  = \App\Services\Shipping\CarrierClient::registry()[$code] ?? null;
        abort_unless($cls, 422);

        $saved = \App\Services\Shipping\ShippingRates::creds($tenant, $code) ?? [];
        $creds = [];
        foreach ($cls::fields() as $k => $f) {
            $typed = trim((string) $request->input("fields.$k", ''));
            $creds[$k] = $typed !== '' ? $typed : (string) ($saved[$k] ?? '');
            if ($creds[$k] === '' && ! empty($f['required'])) {
                return response()->json(['ok' => false, 'message' => "Fill in the {$f['label']} first."]);
            }
        }
        $r = (new $cls($request->boolean('sandbox')))->test($creds);
        return response()->json($r);
    }

    /**
     * MARKER-ECOM-SETTINGS: one tab at a time. Each tab only writes its own
     * keys, so an unticked box on one tab can never switch off another tab's setting.
     */
    public function update(Request $request): RedirectResponse
    {
        $this->guard();
        $section = (string) $request->input('section', '');
        abort_unless(in_array($section, ['fulfillment', 'tax', 'payments', 'wording', 'general', 'google'], true), 422);

        $tenant   = tenant();
        $settings = $tenant->settings ?? [];
        $sf       = (array) ($settings['storefront'] ?? []);
        $cents    = fn ($v) => ($v === null || $v === '') ? null : (int) round(((float) $v) * 100);
        $tab      = $section;

        switch ($section) {
            case 'fulfillment':
                $data = $request->validate([
                    'pickup_instructions' => ['nullable', 'string', 'max:300'],
                    'delivery_fee'        => ['nullable', 'numeric', 'min:0', 'max:500'],
                    'free_over'           => ['nullable', 'numeric', 'min:0', 'max:100000'],
                    'delivery_zips'       => ['nullable', 'string', 'max:2000'],
                    'install_label'       => ['nullable', 'string', 'max:160'],
                    'ship_fee'            => ['nullable', 'numeric', 'min:0', 'max:500'],
                    'ship_fee_supplier'   => ['nullable', 'numeric', 'min:0', 'max:500'],
                    'ship_free_over'      => ['nullable', 'numeric', 'min:0', 'max:100000'],
                    // MARKER-SHIPPING-RATES
                    'ship_mode'           => ['nullable', 'in:flat,weight,carrier'],
                    'ship_from_zip'       => ['nullable', 'string', 'max:12'],
                    'ship_default_lb'     => ['nullable', 'numeric', 'min:0', 'max:150'],
                    'ship_markup_pct'     => ['nullable', 'numeric', 'min:0', 'max:100'],
                    'ship_markup'         => ['nullable', 'numeric', 'min:0', 'max:500'],
                    'ship_boxes'          => ['nullable', 'array', 'max:20'],
                    'ship_boxes.*.name'   => ['nullable', 'string', 'max:40'],
                    'ship_boxes.*.l'      => ['nullable', 'numeric', 'min:0', 'max:300'],
                    'ship_boxes.*.w'      => ['nullable', 'numeric', 'min:0', 'max:300'],
                    'ship_boxes.*.h'      => ['nullable', 'numeric', 'min:0', 'max:300'],
                    'ship_boxes.*.max_lb' => ['nullable', 'numeric', 'min:0', 'max:150'],
                    'ship_boxes.*.tare_oz' => ['nullable', 'numeric', 'min:0', 'max:500'],
                    'ship_table'          => ['nullable', 'array', 'max:30'],
                    'ship_table.*.upto_lb' => ['nullable', 'numeric', 'min:0', 'max:150'],
                    'ship_table.*.price'  => ['nullable', 'numeric', 'min:0', 'max:5000'],
                    'carrier'             => ['nullable', 'array'],
                ]);
                if (! $request->boolean('pickup_enabled') && ! $request->boolean('local_delivery') && ! $request->boolean('ship_enabled')) {
                    return back()->withInput()->with('ecom_tab', $tab)
                        ->with('error', 'Turn on pickup, local delivery or shipping. With all three off, nobody could check out.');
                }
                $sf = array_merge($sf, [
                    'pickup_enabled'           => $request->boolean('pickup_enabled'),
                    'pickup_instructions'      => trim((string) ($data['pickup_instructions'] ?? '')),
                    'local_delivery'           => $request->boolean('local_delivery'),
                    'delivery_fee_cents'       => (int) ($cents($data['delivery_fee'] ?? null) ?? 0),
                    'free_delivery_over_cents' => $cents($data['free_over'] ?? null),
                    'delivery_zips'            => implode(', ', \App\Services\Tenant\OrderService::parseZips((string) ($data['delivery_zips'] ?? ''))),
                    'ship_enabled'             => $request->boolean('ship_enabled'),
                    'ship_fee_cents'           => (int) ($cents($data['ship_fee'] ?? null) ?? 0),
                    'ship_fee_supplier_cents'  => $cents($data['ship_fee_supplier'] ?? null),
                    'ship_free_over_cents'     => $cents($data['ship_free_over'] ?? null),
                    'install_offer'            => $request->boolean('install_offer'),
                    'install_label'            => trim((string) ($data['install_label'] ?? '')),
                ]);
                // MARKER-SHIPPING-RATES: how shipping is priced
                if ($request->has('ship_mode')) {
                    $mode  = $data['ship_mode'] ?? 'flat';
                    $zip   = \App\Services\Shipping\ShippingRates::zip((string) ($data['ship_from_zip'] ?? ''));
                    $table = \App\Services\Shipping\RateTable::clean(collect($data['ship_table'] ?? [])->map(fn ($r) => [
                        'upto_oz' => (float) ($r['upto_lb'] ?? 0) * 16, 'cents' => $cents($r['price'] ?? null)])->all());
                    $boxes = \App\Services\Shipping\PackageMath::cleanBoxes(collect($data['ship_boxes'] ?? [])->map(fn ($b) => [
                        'name' => $b['name'] ?? '', 'l' => $b['l'] ?? 0, 'w' => $b['w'] ?? 0, 'h' => $b['h'] ?? 0,
                        'max_oz' => (float) ($b['max_lb'] ?? 0) * 16, 'tare_oz' => $b['tare_oz'] ?? 0])->all());
                    $carriers = [];
                    foreach (\App\Services\Shipping\CarrierClient::registry() as $code => $cls) {
                        $carriers[$code] = \App\Services\Shipping\ShippingRates::credsForSave($tenant, $code, (array) ($request->input("carrier.$code") ?? []));
                    }
                    $sf = array_merge($sf, ['ship_mode' => $mode, 'carriers' => $carriers]);   // the checks below read what was just typed
                    $tenantCheck = clone $tenant;
                    $tenantCheck->settings = array_merge((array) $settings, ['storefront' => $sf]);
                    $usable = collect($carriers)->filter(fn ($c, $code) => $c['enabled'] && $c['services'] && \App\Services\Shipping\ShippingRates::creds($tenantCheck, $code))->isNotEmpty();
                    $locZip = \App\Services\Shipping\ShippingRates::zip((string) ($tenant->defaultLocation?->postal_code ?? ''));
                    $err = null;
                    if ($request->boolean('ship_enabled') && $mode === 'weight' && ! $table) {
                        $err = 'Add at least one row to the weight table, or choose another way to price shipping.';
                    } elseif ($request->boolean('ship_enabled') && $mode === 'carrier' && ! $usable) {
                        $err = 'Turn on a carrier, fill in its details and tick at least one service, or choose another way to price shipping.';
                    } elseif ($request->boolean('ship_enabled') && $mode !== 'flat' && $zip === '' && $locZip === '') {
                        $err = 'Enter the ZIP code you ship from. Weight and carrier prices need it.';
                    }
                    if ($err) {
                        return back()->withInput()->with('ecom_tab', $tab)->with('error', $err);
                    }
                    $sf = array_merge($sf, [
                        'ship_from_zip'     => $zip,
                        'ship_default_oz'   => (float) ($data['ship_default_lb'] ?? 0) > 0 ? round((float) $data['ship_default_lb'] * 16, 2) : null,
                        'ship_markup_pct'   => (float) ($data['ship_markup_pct'] ?? 0),
                        'ship_markup_cents' => (int) ($cents($data['ship_markup'] ?? null) ?? 0),
                        'ship_table'        => $table,
                        'ship_boxes'        => $boxes,
                    ]);
                }
                // MARKER-DISTRIBUTION-WAREHOUSES: only when master admin has allowed this shop dropshipping
                if (\App\Services\Distributors\WarehouseRegistry::access($tenant) === 'on') {
                    $d = $request->validate([
                        'dropship_order_mode'     => ['nullable', 'in:list,stock'],
                        'dropship_oversell_mode'  => ['nullable', 'in:supplier,special'],
                        'dropship_handling_days'  => ['nullable', 'integer', 'min:0', 'max:60'],
                        'dropship_label'          => ['nullable', 'string', 'max:80'],
                        'wh_order'                => ['nullable', 'array'],
                        'wh_order.*'              => ['string'],
                        'wh_on'                   => ['nullable', 'array'],
                    ]);
                    $sf = array_merge($sf, [
                        'dropship_on'            => $request->boolean('dropship_on'),
                        'dropship_own_first'     => $request->boolean('dropship_own_first'),
                        'dropship_order_mode'    => $d['dropship_order_mode'] ?? 'list',
                        'dropship_oversell_mode' => $d['dropship_oversell_mode'] ?? 'supplier',
                        'dropship_handling_days' => (int) ($d['dropship_handling_days'] ?? 2),
                        'dropship_label'         => trim((string) ($d['dropship_label'] ?? '')),
                    ]);
                    \App\Services\Distributors\WarehouseRegistry::saveShopChoices($tenant, (array) ($d['wh_on'] ?? []), (array) ($d['wh_order'] ?? []));
                }
                $msg = 'Fulfillment saved.';
                break;

            case 'tax':
                $t = $request->validate([
                    'tax_mode'        => ['nullable', 'in:rate,stripe'],
                    'stripe_tax_code' => ['nullable', 'regex:/^txcd_\d{8}$/'],
                ], ['stripe_tax_code.regex' => 'A Stripe tax code looks like txcd_99999999.']);
                if (($t['tax_mode'] ?? 'rate') === 'stripe' && ! $tenant->direct_payments_enabled) {
                    return back()->withInput()->with('ecom_tab', $tab)->with('error', 'Stripe Tax needs card payments turned on first (Settings › Payments).');
                }
                $sf['tax_mode']        = $t['tax_mode'] ?? 'rate';
                $sf['stripe_tax_code'] = trim((string) ($t['stripe_tax_code'] ?? ''));
                $sf['tax_delivery_fee'] = $request->boolean('tax_delivery_fee');
                $msg = 'Tax settings saved.';
                break;

            case 'payments':
                $data = $request->validate(['hold_hours' => ['required', 'integer', 'min:1', 'max:720']]);
                $sf['hold_hours']  = (int) $data['hold_hours'];
                $sf['auto_refund'] = $request->boolean('auto_refund');
                $msg = 'Payment settings saved.';
                break;

            case 'wording':
                $data = $request->validate([
                    'stock_label'    => ['nullable', 'string', 'max:80'],
                    'special_label'  => ['nullable', 'string', 'max:80'],
                    'out_label'      => ['nullable', 'string', 'max:80'],
                    'special_notice' => ['nullable', 'string', 'max:200'],
                    'intro'          => ['nullable', 'string', 'max:200'],
                    'ready_text'     => ['nullable', 'string', 'max:300'],
                ]);
                foreach (['stock_label', 'special_label', 'out_label', 'special_notice', 'intro', 'ready_text'] as $k) {
                    $sf[$k] = trim((string) ($data[$k] ?? '')); // blank = back to the standard wording
                }
                $tab = 'msg';
                $msg = 'Wording saved.';
                break;

            case 'google':
                $data = $request->validate([
                    'google_cat'   => ['nullable', 'array'],
                    'google_cat.*' => ['nullable', 'string', 'max:300'],
                ]);
                $sf['feed_on'] = $request->boolean('feed_on');
                if ($sf['feed_on'] && (empty($sf['feed_token']) || $request->boolean('regen'))) {
                    $sf['feed_token'] = \Illuminate\Support\Str::random(40); // the long link is the key; a new one retires the old
                }
                foreach (\App\Models\Tenant\TenantInventoryCategory::where('tenant_id', $tenant->id)->get(['id']) as $c) {
                    if (! array_key_exists($c->id, (array) ($data['google_cat'] ?? []))) continue;
                    $v = trim((string) $data['google_cat'][$c->id]);
                    \App\Models\Tenant\TenantInventoryCategory::where('id', $c->id)->where('tenant_id', $tenant->id)->update(['google_category' => $v !== '' ? $v : null]);
                }
                $msg = $request->boolean('regen') && $sf['feed_on'] ? 'Google settings saved. The feed link changed: paste the new one into Merchant Center.' : 'Google settings saved.';
                break;

            case 'general':
                $data = $request->validate([
                    'browse_layout' => ['nullable', 'in:chips,sidebar'],
                    'out_display'   => ['nullable', 'in:show,hide'],
                ]);
                $sf['enabled']       = $request->boolean('enabled');
                $sf['browse_layout'] = $data['browse_layout'] ?? 'chips';
                $sf['out_display']   = $data['out_display'] ?? 'show';
                $sf['share_on']      = $request->boolean('share_on'); // MARKER-ITEM-SEO
                $msg = 'Store settings saved.';
                break;
        }

        $settings['storefront'] = $sf;
        $tenant->settings = $settings;
        $tenant->save();

        return back()->with('success', $msg)->with('ecom_tab', $tab);
    }

    /** GET /storefront/feed-report — what the Google feed holds and why anything is left out (MARKER-GOOGLE-FEED). */
    public function feedReport()
    {
        $this->guard();
        return response()->json(\App\Services\Feeds\GoogleFeed::report(tenant()));
    }

    /** POST /storefront/item/{id} — per-item publish toggle (from the item page). */
    public function toggleItem(string $id): RedirectResponse
    {
        $this->guard();
        $item = TenantInventoryItem::query()
            ->where('tenant_id', tenant()->id)
            ->findOrFail($id);
        $item->show_online = ! $item->show_online;
        $item->save();

        return back()->with('success', $item->show_online
            ? 'Published to the online store.'
            : 'Removed from the online store.');
    }

    /** POST /storefront/bulk — publish/unpublish item sets. */
    public function bulk(Request $request): RedirectResponse
    {
        $this->guard();
        $data = $request->validate(['op' => ['required', 'in:publish_catalog,publish_all,unpublish_all']]);

        $q = TenantInventoryItem::query()
            ->where('tenant_id', tenant()->id)
            ->where('is_active', true);

        $n = match ($data['op']) {
            'publish_catalog' => (clone $q)->whereNotNull('distributor_catalog_id')->update(['show_online' => true]),
            'publish_all'     => (clone $q)->update(['show_online' => true]),
            'unpublish_all'   => TenantInventoryItem::query()->where('tenant_id', tenant()->id)->update(['show_online' => false]),
        };

        return back()->with('success', match ($data['op']) {
            'publish_catalog' => "Published {$n} catalog-linked items to the store.",
            'publish_all'     => "Published {$n} items to the store.",
            'unpublish_all'   => 'Everything unpublished — the store shows nothing until you publish again.',
        });
    }
}
