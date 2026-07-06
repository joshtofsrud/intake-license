<?php

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Models\Tenant\TenantInventoryItem;
use App\Services\Tenant\CartService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * MARKER-PATCH-564 — Online Retail Wave 3: cart page + line mutations.
 * Plain form posts with redirects; no JS dependency. The same addon gate
 * as the storefront guards every entry point.
 */
class CartController extends Controller
{
    private function guard(): void
    {
        abort_unless(
            app(\App\Services\FeatureAccessService::class)->hasAddon(tenant(), 'online_store'),
            404
        );
    }

    public function show()
    {
        $this->guard();
        $cart = CartService::forTenant(tenant())->current();
        return view('public.shop.cart', [
            'tenant' => tenant(),
            'cart'   => $cart?->load('items'),
        ]);
    }

    public function add(Request $request): RedirectResponse
    {
        $this->guard();
        $data = $request->validate([
            'item_id'  => ['required', 'string'],
            'quantity' => ['nullable', 'integer', 'min:1', 'max:99'],
        ]);

        $item = TenantInventoryItem::query()
            ->where('tenant_id', tenant()->id)
            ->where('is_active', true)
            ->where('show_online', true)
            ->with('distributorCatalog:id,images')
            ->findOrFail($data['item_id']);

        CartService::forTenant(tenant())->addItem($item, (int) ($data['quantity'] ?? 1));

        return redirect('/cart')->with('added', $item->name);
    }

    public function update(Request $request, string $lineId): RedirectResponse
    {
        $this->guard();
        $data = $request->validate(['quantity' => ['required', 'integer', 'min:0', 'max:99']]);
        $svc  = CartService::forTenant(tenant());
        if ($cart = $svc->current()) {
            $svc->updateQty($cart, $lineId, (int) $data['quantity']);
        }
        return redirect('/cart');
    }

    public function remove(string $lineId): RedirectResponse
    {
        $this->guard();
        $svc = CartService::forTenant(tenant());
        if ($cart = $svc->current()) {
            $svc->removeLine($cart, $lineId);
        }
        return redirect('/cart');
    }
}
