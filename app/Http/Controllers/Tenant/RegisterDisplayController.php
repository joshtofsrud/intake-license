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
use App\Models\Tenant\TenantRental; // MARKER-RENTAL-WAIVER-DISPLAY-BE
use App\Models\Tenant\TenantRentalAgreementTemplate; // MARKER-RENTAL-WAIVER-DISPLAY-BE
use App\Services\Tenant\RentalAgreementService; // MARKER-RENTAL-WAIVER-DISPLAY-BE
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

        // MARKER-RENTAL-WAIVER-DISPLAY-BE — a live waiver owns the screen.
        // Checked before the cart because the register page keeps pushing
        // snapshots while this is up; those writes land in display_cart and
        // simply aren't read until the waiver clears.
        if ($register->agreementIsLive()) {
            $agreement = $this->agreementPayload($tenant, $register);
            if ($agreement !== null) {
                return response()->json(['state' => 'agreement', 'agreement' => $agreement]);
            }
            // Payload came back null — the override was stale (rental gone,
            // cancelled, already signed, or the tenant deleted its template)
            // and has been cleared. Fall through to normal mirroring so the
            // screen recovers on this same tick.
        }

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

    /**
     * MARKER-RENTAL-WAIVER-DISPLAY-BE — build the waiver payload, or clear
     * the override and return null when it can no longer be honoured.
     *
     * Self-healing is the point: any reason the waiver shouldn't be up ends
     * with the override gone, so the tablet returns to the idle greeting on
     * the next poll rather than sitting on a screen nobody can dismiss.
     */
    private function agreementPayload($tenant, TenantRegister $register): ?array
    {
        $rental = TenantRental::where('tenant_id', $tenant->id)
            ->with('customer')
            ->find($register->display_rental_id);

        if (! $rental || $rental->status === 'cancelled' || $rental->agreement_signed_at) {
            $register->clearDisplayMode();
            return null;
        }

        $template = TenantRentalAgreementTemplate::where('tenant_id', $tenant->id)
            ->orderByDesc('version')->first();

        if (! $template) {
            $register->clearDisplayMode();
            return null;
        }

        return [
            'rental_number' => $rental->rental_number,
            'title'         => $template->title,
            'version'       => (int) $template->version,
            'body'          => $template->body,
            'customer_name' => $rental->customer?->fullName(),
            'nonce'         => $register->display_sign_nonce,
        ];
    }

    /**
     * MARKER-RENTAL-WAIVER-DISPLAY-BE — the customer's signature comes back
     * here. Token is the credential; the nonce binds this POST to the push
     * that put the waiver on screen.
     *
     * Always 200. The tablet has no staff member standing over it, so every
     * refusal has to arrive as something it can render and move on from —
     * an HTTP error would leave the customer looking at a frozen screen.
     */
    public function signAgreementFromDisplay(Request $request, string $token): JsonResponse
    {
        $tenant = app('tenant');

        $register = TenantRegister::where('tenant_id', $tenant->id)
                      ->where('display_token', $token)
                      ->where('is_active', true)
                      ->first();

        if (! $register || ! $register->agreementIsLive()) {
            return response()->json(['ok' => false, 'code' => 'closed'], 200);
        }

        $data = $request->validate([
            'signer_name' => ['required', 'string', 'max:160'],
            'signature'   => ['nullable', 'string', 'max:600000'],
            'nonce'       => ['required', 'string', 'max:64'],
        ]);

        // A tab left open across a recall-and-repush must not sign the old
        // rental. Mismatched nonce reads as closed, same as an expiry.
        if (! hash_equals((string) $register->display_sign_nonce, (string) $data['nonce'])) {
            return response()->json(['ok' => false, 'code' => 'closed'], 200);
        }

        $rental = TenantRental::where('tenant_id', $tenant->id)
            ->find($register->display_rental_id);

        if (! $rental) {
            $register->clearDisplayMode();
            return response()->json(['ok' => false, 'code' => 'closed'], 200);
        }

        // Double-tap on Agree, or a desk signature that landed first: the
        // customer did nothing wrong, so this reads as success.
        if ($rental->agreement_signed_at) {
            $register->clearDisplayMode();
            return response()->json(['ok' => true, 'code' => 'already'], 200);
        }

        if ($rental->status !== 'reserved') {
            $register->clearDisplayMode();
            return response()->json(['ok' => false, 'code' => 'closed'], 200);
        }

        $template = TenantRentalAgreementTemplate::where('tenant_id', $tenant->id)
            ->orderByDesc('version')->first();

        if (! $template) {
            $register->clearDisplayMode();
            return response()->json(['ok' => false, 'code' => 'closed'], 200);
        }

        app(RentalAgreementService::class)->finalize(
            $tenant,
            $rental,
            $template,
            $data['signer_name'],
            'display',
            $data['signature'] ?? null,
            $request->ip()
        );

        $register->clearDisplayMode();

        return response()->json(['ok' => true, 'code' => 'signed'], 200);
    }
}
