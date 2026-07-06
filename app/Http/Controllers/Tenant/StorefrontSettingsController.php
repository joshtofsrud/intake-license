<?php

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Models\Tenant\TenantInventoryItem;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * MARKER-PATCH-569 — Online Retail Wave 5b: storefront settings.
 * The tenant-facing control panel: master switch, delivery toggle + fee,
 * install offer, and bulk publish/unpublish (retiring the one-time SQL).
 * Config lives in settings['storefront'] — the same block OrderService
 * and the public storefront already read.
 */
class StorefrontSettingsController extends Controller
{
    private function guard(): void
    {
        abort_unless(tenant()->online_store_enabled, 404);
        $me = Auth::guard('tenant')->user();
        abort_unless($me && $me->isManager(), 403);
    }

    public function show()
    {
        $this->guard();
        $tenant = tenant();
        $s = (array) (($tenant->settings['storefront'] ?? []) ?: []);

        $itemBase = TenantInventoryItem::query()
            ->where('tenant_id', $tenant->id)
            ->where('is_active', true);

        return view('tenant.storefront.settings', [
            'cfg' => [
                'enabled'            => (bool) ($s['enabled'] ?? true),
                'local_delivery'     => (bool) ($s['local_delivery'] ?? false),
                'delivery_fee'       => number_format(((int) ($s['delivery_fee_cents'] ?? 0)) / 100, 2, '.', ''),
                'install_offer'      => (bool) ($s['install_offer'] ?? true),
            ],
            'counts' => [
                'online'   => (clone $itemBase)->where('show_online', true)->count(),
                'linkable' => (clone $itemBase)->whereNotNull('distributor_catalog_id')->count(),
                'active'   => (clone $itemBase)->count(),
            ],
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $this->guard();
        $data = $request->validate([
            'enabled'        => ['nullable', 'boolean'],
            'local_delivery' => ['nullable', 'boolean'],
            'delivery_fee'   => ['nullable', 'numeric', 'min:0', 'max:500'],
            'install_offer'  => ['nullable', 'boolean'],
        ]);

        $tenant = tenant();
        $settings = $tenant->settings ?? [];
        $settings['storefront'] = array_merge((array) ($settings['storefront'] ?? []), [
            'enabled'            => $request->boolean('enabled'),
            'local_delivery'     => $request->boolean('local_delivery'),
            'delivery_fee_cents' => (int) round(((float) ($data['delivery_fee'] ?? 0)) * 100),
            'install_offer'      => $request->boolean('install_offer'),
        ]);
        $tenant->settings = $settings;
        $tenant->save();

        return back()->with('success', 'Storefront settings saved.');
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
