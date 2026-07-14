<?php

namespace App\Http\Controllers\Tenant;

// MARKER-REGISTER-RECON-DISPLAY — register management + customer-facing pay displays.
//
// Admin side (authed, register-guarded):
//   registers()        — manage page: list, pairing QR per register
//   storeRegister()    — create a register (number auto-assigned)
//   regenerateToken()  — rotate a register's display token (unpairs screens)
//   selectRegister()   — bind this staff session to a register (current_register_id)
//   displayState()     — receive debounced cart snapshots from the POS page
//
// Public side (tenant-resolved by host, token is the credential):
//   display()          — full-screen customer display bound to one register
//   displayPoll()      — JSON snapshot the display polls (~1.5s)

use App\Http\Controllers\Controller;
use App\Models\Tenant\TenantRegister;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class RegisterDisplayController extends Controller
{
    // ---------------------------------------------------------------- admin

    public function registers(Request $request)
    {
        $tenant = app('tenant');

        return view('tenant.register.registers', [
            'tenant'    => $tenant,
            'registers' => TenantRegister::where('tenant_id', $tenant->id)
                             ->orderBy('number')->get(),
            'currentRegisterId' => (int) $request->session()->get('current_register_id', 0),
        ]);
    }

    public function storeRegister(Request $request): RedirectResponse
    {
        $tenant = app('tenant');
        $data = $request->validate(['name' => ['required', 'string', 'max:80']]);

        TenantRegister::create([
            'tenant_id'     => $tenant->id,
            'number'        => TenantRegister::nextNumber($tenant->id),
            'name'          => $data['name'],
            'display_token' => TenantRegister::freshToken(),
        ]);

        return back()->with('status', 'Register added.');
    }

    public function updateRegister(Request $request, int $id): RedirectResponse
    {
        $tenant = app('tenant');
        $register = TenantRegister::where('tenant_id', $tenant->id)->findOrFail($id);
        $data = $request->validate([
            'display_logo' => ['required', 'in:auto,main,light,none'],
        ]);
        $register->update($data);

        return back()->with('status', 'Register updated.');
    }

    public function regenerateToken(Request $request, int $id): RedirectResponse
    {
        $tenant = app('tenant');
        $register = TenantRegister::where('tenant_id', $tenant->id)->findOrFail($id);
        $register->update([
            'display_token' => TenantRegister::freshToken(),
            'display_cart'  => null,
        ]);

        return back()->with('status', 'Pairing link regenerated — previously paired screens are disconnected.');
    }

    public function selectRegister(Request $request): JsonResponse
    {
        $tenant = app('tenant');
        $data = $request->validate(['register_id' => ['required', 'integer']]);

        // 0 = no register (clears the binding)
        if ((int) $data['register_id'] === 0) {
            $request->session()->forget('current_register_id');
            return response()->json(['ok' => true, 'register_id' => null]);
        }

        $register = TenantRegister::where('tenant_id', $tenant->id)
                      ->where('is_active', true)
                      ->findOrFail((int) $data['register_id']);

        $request->session()->put('current_register_id', $register->id);

        return response()->json(['ok' => true, 'register_id' => $register->id]);
    }

    public function displayState(Request $request): JsonResponse
    {
        $tenant = app('tenant');
        $registerId = (int) $request->session()->get('current_register_id', 0);
        if ($registerId === 0) {
            return response()->json(['ok' => false, 'reason' => 'no_register'], 200);
        }

        $register = TenantRegister::where('tenant_id', $tenant->id)->find($registerId);
        if (! $register) {
            $request->session()->forget('current_register_id');
            return response()->json(['ok' => false, 'reason' => 'gone'], 200);
        }

        // Snapshot is display-only data; whitelist the shape rather than
        // trusting the client blob wholesale.
        $snap = $request->validate([
            'state'                 => ['required', 'in:idle,cart,pay'],
            'items'                 => ['array', 'max:200'],
            'items.*.name'          => ['required_with:items', 'string', 'max:160'],
            'items.*.qty'           => ['required_with:items', 'numeric'],
            'items.*.line_cents'    => ['required_with:items', 'integer'],
            'items.*.refund'        => ['sometimes', 'boolean'],
            'subtotal_cents'        => ['integer'],
            'discount_cents'        => ['integer'],
            'tax_cents'             => ['integer'],
            'tax_label'             => ['nullable', 'string', 'max:40'],
            'surcharge_cents'       => ['integer'],
            'tip_cents'             => ['integer'],
            'total_cents'           => ['integer'],
            'pay_url'               => ['nullable', 'string', 'max:500'],
        ]);

        $register->update([
            'display_cart'    => $snap,
            'cart_updated_at' => now(),
        ]);

        return response()->json(['ok' => true]);
    }

    // --------------------------------------------------------------- public

    public function display(string $token)
    {
        $tenant = app('tenant');
        $register = TenantRegister::where('tenant_id', $tenant->id)
                      ->where('display_token', $token)
                      ->where('is_active', true)
                      ->firstOrFail();

        return view('tenant.register.display', [
            'tenant'   => $tenant,
            'register' => $register,
        ]);
    }

    public function displayPoll(string $token): JsonResponse
    {
        $tenant = app('tenant');
        $register = TenantRegister::where('tenant_id', $tenant->id)
                      ->where('display_token', $token)
                      ->where('is_active', true)
                      ->firstOrFail();

        $snap = $register->display_cart;

        // A snapshot older than 90s means the POS page is gone — fall back
        // to idle instead of showing a stale cart to the next customer.
        $stale = $register->cart_updated_at === null
              || $register->cart_updated_at->lt(now()->subSeconds(90));

        return response()->json([
            'state' => $stale ? 'idle' : ($snap['state'] ?? 'idle'),
            'snap'  => $stale ? null : $snap,
        ]);
    }
}
