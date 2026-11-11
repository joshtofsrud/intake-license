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

        return view('tenant.storefront.settings', [
            'cfg' => [
                'enabled'             => (bool) ($s['enabled'] ?? true),
                'browse_layout'       => (string) ($s['browse_layout'] ?? 'chips'),
                'out_display'         => ($s['out_display'] ?? 'show') === 'hide' ? 'hide' : 'show',
                // fulfillment
                'pickup_enabled'      => $oc['pickup_enabled'],
                'pickup_instructions' => $oc['pickup_instructions'],
                'local_delivery'      => $oc['local_delivery'],
                'delivery_fee'        => $cents($oc['delivery_fee_cents']),
                'free_over'           => $cents($oc['free_delivery_over_cents']),
                'delivery_zips'       => implode(', ', $oc['delivery_zips']),
                'install_offer'       => $oc['install_offer'],
                'install_label'       => (string) ($s['install_label'] ?? ''),
                'install_default'     => \App\Services\Tenant\OrderService::DEFAULT_INSTALL_LABEL,
                // tax / payments
                'tax_delivery_fee'    => $oc['tax_delivery_fee'],
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
     * MARKER-ECOM-SETTINGS: one tab at a time. Each tab only writes its own
     * keys, so an unticked box on one tab can never switch off another tab's setting.
     */
    public function update(Request $request): RedirectResponse
    {
        $this->guard();
        $section = (string) $request->input('section', '');
        abort_unless(in_array($section, ['fulfillment', 'tax', 'payments', 'wording', 'general'], true), 422);

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
                ]);
                if (! $request->boolean('pickup_enabled') && ! $request->boolean('local_delivery')) {
                    return back()->withInput()->with('ecom_tab', $tab)
                        ->with('error', 'Turn on pickup or local delivery. With both off, nobody could check out.');
                }
                $sf = array_merge($sf, [
                    'pickup_enabled'           => $request->boolean('pickup_enabled'),
                    'pickup_instructions'      => trim((string) ($data['pickup_instructions'] ?? '')),
                    'local_delivery'           => $request->boolean('local_delivery'),
                    'delivery_fee_cents'       => (int) ($cents($data['delivery_fee'] ?? null) ?? 0),
                    'free_delivery_over_cents' => $cents($data['free_over'] ?? null),
                    'delivery_zips'            => implode(', ', \App\Services\Tenant\OrderService::parseZips((string) ($data['delivery_zips'] ?? ''))),
                    'install_offer'            => $request->boolean('install_offer'),
                    'install_label'            => trim((string) ($data['install_label'] ?? '')),
                ]);
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

            case 'general':
                $data = $request->validate([
                    'browse_layout' => ['nullable', 'in:chips,sidebar'],
                    'out_display'   => ['nullable', 'in:show,hide'],
                ]);
                $sf['enabled']       = $request->boolean('enabled');
                $sf['browse_layout'] = $data['browse_layout'] ?? 'chips';
                $sf['out_display']   = $data['out_display'] ?? 'show';
                $msg = 'Store settings saved.';
                break;
        }

        $settings['storefront'] = $sf;
        $tenant->settings = $settings;
        $tenant->save();

        return back()->with('success', $msg)->with('ecom_tab', $tab);
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
