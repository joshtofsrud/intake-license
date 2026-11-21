<?php

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Models\Tenant\TenantPosConnection;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * MARKER-CONNECTED-POS-SECTION: E-commerce › Connected POS. The one part of the Connected
 * POS that is about the online store: sending paid online orders to it as sales, and what
 * each sale is recorded with. Everything else lives in the Connected POS section.
 */
class PosOrdersController extends Controller
{
    private function guard(): void
    {
        abort_unless(tenant()->online_store_enabled && tenant()->connected_pos_enabled, 404);
        $me = Auth::guard('tenant')->user();
        abort_unless($me && $me->canAccessSection('storefront'), 403);
    }

    private function back(): RedirectResponse
    {
        return redirect()->route('tenant.storefront.settings', ['tab' => 'pos']);
    }

    /** Orders out, and what each sale is recorded with. (What is sent about items: Connected POS › Settings.) */
    public function settings(Request $request): RedirectResponse
    {
        $this->guard();
        $conn = TenantPosConnection::where('tenant_id', tenant()->id)->firstOrFail();
        $data = $request->validate([
            'orders_out'      => ['nullable', 'boolean'],
            'register_id'     => ['nullable', 'string', 'max:100'],
            'user_id'         => ['nullable', 'string', 'max:100'],
            'payment_type_id' => ['nullable', 'string', 'max:100'],
            'tax_id'          => ['nullable', 'string', 'max:100'],
            'registers'       => ['nullable', 'array'],
            'registers.*'     => ['nullable', 'string', 'max:100'],
        ]);
        $opts = (array) ($conn->setup_options ?? []);
        $valid = fn (string $list, ?string $id) => $id === null || $id === '' || collect($opts[$list] ?? ($list === 'payment_types' ? $conn->payment_types : []) ?? [])->pluck('id')->map(fn ($v) => (string) $v)->contains($id);
        if (! $valid('registers', $data['register_id'] ?? null) || ! $valid('users', $data['user_id'] ?? null)
            || ! $valid('payment_types', $data['payment_type_id'] ?? null) || ! $valid('taxes', $data['tax_id'] ?? null)) {
            return $this->back()->with('error', 'One of those choices is no longer in your Connected POS. Click Check connection to refresh the lists.');
        }
        $setup = array_filter([
            'register_id' => $data['register_id'] ?? null, 'user_id' => $data['user_id'] ?? null,
            'payment_type_id' => $data['payment_type_id'] ?? null, 'tax_id' => $data['tax_id'] ?? null,
        ]);
        // MARKER-POS-REGISTER-PER-OUTLET: a register for each mapped outlet, when there is more than one
        $regIds = collect($opts['registers'] ?? [])->pluck('id')->map(fn ($v) => (string) $v);
        $perOutlet = [];
        foreach ((array) ($data['registers'] ?? []) as $outlet => $reg) {
            if (filled($reg) && isset(((array) $conn->location_map)[$outlet]) && $regIds->contains((string) $reg)) {
                $perOutlet[(string) $outlet] = (string) $reg;
            }
        }
        if ($perOutlet) {
            $setup['registers'] = $perOutlet;
        }
        // kept across saves: the item online shipping is sold as
        if (! empty(((array) $conn->order_setup)['charge_item_id'])) {
            $setup['charge_item_id'] = ((array) $conn->order_setup)['charge_item_id'];
        }
        $out = (bool) ($data['orders_out'] ?? false);
        if ($out && ! $conn->isTwoWay()) {
            return $this->back()->with('error', 'Your Connected POS is Reading Only, so orders can\'t be sent to it.');
        }
        $wasOut = (bool) $conn->orders_out;
        if ($out) {
            $missing = [];
            foreach (['register_id' => 'register', 'user_id' => 'staff member', 'payment_type_id' => 'payment type'] as $k => $label) {
                if (empty($setup[$k])) { $missing[] = $label; }
            }
            if (! empty($opts['taxes']) && empty($setup['tax_id'])) { $missing[] = 'tax'; }
            if ($missing) {
                return $this->back()->with('error', 'To send online orders, choose the ' . implode(', ', $missing) . ' they are recorded with.');
            }
            if (empty($conn->location_map)) {
                return $this->back()->with('error', 'Map at least one outlet to an Intake location under Connected POS › Settings before sending orders.');
            }
        }
        $conn->forceFill(['orders_out' => $out, 'order_setup' => $setup])->save();

        $catch = $out && ! $wasOut ? \App\Services\Pos\PosPushService::catchupOrders($conn->tenant_id)->count() : 0;
        return $this->back()->with('success', 'Saved.' . ($catch ? ' ' . $catch . ' paid ' . ($catch === 1 ? 'order' : 'orders') . ' from the last 7 days went out before this was on — send them below.' : ''));
    }

    /** MARKER-POS-CATCHUP: send the paid orders from the last 7 days that went out before orders were switched on. */
    public function sendRecentOrders(\App\Services\Pos\PosPushService $push): RedirectResponse
    {
        $this->guard();
        $conn = TenantPosConnection::where('tenant_id', tenant()->id)->where('status', 'connected')->firstOrFail();
        if (! $conn->isTwoWay()) {
            return $this->back()->with('error', 'Your Connected POS is Reading Only, so orders can\'t be sent to it.');
        }
        if (! $conn->orders_out) {
            return $this->back()->with('error', 'Turn on Send paid online orders and save first.');
        }
        $n = 0;
        foreach (\App\Services\Pos\PosPushService::catchupOrders($conn->tenant_id)->orderBy('created_at')->get(['id', 'order_number']) as $o) {
            $push->queue($conn->tenant_id, 'sale', (string) $o->id, 'Online order ' . $o->order_number, false);
            $n++;
        }
        if ($n) {
            \App\Services\Pos\PosPushService::kick($conn->tenant_id);
        }
        return $this->back()->with('success', $n ? 'Sending ' . $n . ' paid ' . ($n === 1 ? 'order' : 'orders') . ' to your Connected POS. Watch them under Connected POS › Sending.' : 'Every paid order from the last 7 days is already sent.');
    }
}
