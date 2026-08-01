#!/usr/bin/env bash
# apply-rental-waiver-display-backend.sh
# MARKER-RENTAL-WAIVER-DISPLAY-BE — patch 2 of 3: service, endpoints, routes.
#
# Everything here is built so no screen can strand. The rules:
#
#   * Recall clears EVERY register pointing at the rental, not just the one
#     in the current session — staff can switch registers mid-flow and the
#     old screen must not keep a live waiver.
#   * The public sign endpoint always answers 200 with an {ok, code} body.
#     A closed/expired/recalled waiver renders a readable message on the
#     tablet; it never surfaces as a failed fetch the customer can't clear.
#   * Double-tap on Agree is idempotent — an already-signed rental returns
#     ok, so the customer sees the thank-you rather than an error.
#   * displayPoll self-heals: a cancelled rental, a deleted rental or a
#     tenant with no template drops the override and the screen goes back
#     to normal cart mirroring on the next 1.5s tick.
set -e

# ---------------------------------------------------------------- new service
mkdir -p app/Services/Tenant
cat <<'EOF' > app/Services/Tenant/RentalAgreementService.php
<?php

namespace App\Services\Tenant;

// MARKER-RENTAL-WAIVER-DISPLAY-BE — one place that turns "the customer
// agreed" into the durable record: signature image, PDF, stamped columns.

use App\Models\Tenant\TenantRental;
use App\Models\Tenant\TenantRentalAgreementTemplate;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class RentalAgreementService
{
    /** Hard ceiling on a decoded signature. A pad drawing is a few KB. */
    private const MAX_SIGNATURE_BYTES = 400000;

    /**
     * Decode a "data:image/png;base64,..." payload and store it.
     *
     * Returns the stored path, or null when the payload is absent or does
     * not survive validation. A bad signature must never abort a check-out
     * that the customer has already agreed to at the counter — the stamped
     * columns are the record, the image is corroboration.
     */
    public function storeSignature(TenantRental $rental, int $version, ?string $dataUrl): ?string
    {
        if (! $dataUrl) {
            return null;
        }

        if (! preg_match('#^data:image/png;base64,([A-Za-z0-9+/=]+)$#', trim($dataUrl), $m)) {
            Log::warning('rental_agreement.signature_rejected', [
                'rental_id' => $rental->id, 'reason' => 'shape',
            ]);
            return null;
        }

        $bin = base64_decode($m[1], true);
        if ($bin === false || strlen($bin) === 0 || strlen($bin) > self::MAX_SIGNATURE_BYTES) {
            Log::warning('rental_agreement.signature_rejected', [
                'rental_id' => $rental->id, 'reason' => 'size',
            ]);
            return null;
        }

        // PNG magic bytes — the mime in the data URL is caller-supplied.
        if (substr($bin, 0, 8) !== "\x89PNG\r\n\x1a\n") {
            Log::warning('rental_agreement.signature_rejected', [
                'rental_id' => $rental->id, 'reason' => 'magic',
            ]);
            return null;
        }

        $path = 'tenants/' . $rental->tenant_id . '/rental-signatures/'
              . $rental->rental_number . '-v' . $version . '.png';

        try {
            Storage::disk('public')->put($path, $bin);
        } catch (\Throwable $e) {
            Log::error('rental_agreement.signature_store_failed', [
                'rental_id' => $rental->id, 'error' => $e->getMessage(),
            ]);
            return null;
        }

        return $path;
    }

    /** Render the agreement PDF. Never throws — the PDF is a nicety. */
    public function renderPdf(
        $tenant,
        TenantRental $rental,
        TenantRentalAgreementTemplate $template,
        string $signerName,
        ?string $signaturePath
    ): ?string {
        try {
            $pdf = \Barryvdh\DomPDF\Facade\Pdf::loadView('tenant.rentals.agreement-pdf', [
                'tenant'        => $tenant,
                'rental'        => $rental,
                'template'      => $template,
                'signerName'    => $signerName,
                'signedAt'      => now(),
                'signaturePath' => $signaturePath,
            ])->setPaper('letter');

            $path = 'tenants/' . $tenant->id . '/rental-agreements/'
                  . $rental->rental_number . '-v' . $template->version . '.pdf';

            Storage::disk('public')->put($path, $pdf->output());
            return $path;
        } catch (\Throwable $e) {
            Log::error('rental_agreement.pdf_failed', [
                'rental_id' => $rental->id, 'error' => $e->getMessage(),
            ]);
            return null;
        }
    }

    /**
     * Stamp the signature onto the rental. Caller has already checked that
     * the rental is reserved and unsigned.
     *
     * @param string $method desk | display
     */
    public function finalize(
        $tenant,
        TenantRental $rental,
        TenantRentalAgreementTemplate $template,
        string $signerName,
        string $method,
        ?string $signatureDataUrl = null,
        ?string $ip = null
    ): TenantRental {
        $signaturePath = $this->storeSignature($rental, (int) $template->version, $signatureDataUrl);
        $pdfPath       = $this->renderPdf($tenant, $rental, $template, $signerName, $signaturePath);

        $where = $method === 'display' ? 'on the customer display' : 'at the desk';

        $rental->update([
            'agreement_template_version' => $template->version,
            'agreement_signed_at'        => now(),
            'agreement_method'           => $method,
            'agreement_signer_name'      => $signerName,
            'agreement_signature_path'   => $signaturePath,
            'agreement_signed_ip'        => $ip,
            'agreement_pdf_path'         => $pdfPath,
            'notes'                      => trim(($rental->notes ? $rental->notes . "\n" : '')
                . 'Agreement v' . $template->version . ' signed ' . $where . ' by ' . $signerName . '.'),
        ]);

        return $rental->refresh();
    }
}
EOF
echo "created app/Services/Tenant/RentalAgreementService.php"

python3 <<'PY'
import io

# ============================================================ RentalBookingController
p = 'app/Http/Controllers/Tenant/RentalBookingController.php'
s = io.open(p, encoding='utf-8').read()

old = "use App\\Models\\Tenant\\TenantRentalConditionCheck;"
assert s.count(old) == 1, 'B1 import anchor'
s = s.replace(old, old + "\nuse App\\Models\\Tenant\\TenantRegister; // MARKER-RENTAL-WAIVER-DISPLAY-BE\nuse App\\Services\\Tenant\\RentalAgreementService; // MARKER-RENTAL-WAIVER-DISPLAY-BE")

# Desk signings should populate the new name column too, so the signed-state
# card has something to show regardless of which path was used.
old = """            'agreement_method'           => 'desk',"""
assert s.count(old) == 1, 'B2 desk method anchor'
s = s.replace(old, """            'agreement_method'           => 'desk',
            'agreement_signer_name'      => $request->input('signer_name'), // MARKER-RENTAL-WAIVER-DISPLAY-BE""")

# New endpoints go in ahead of signAgreement.
old = """    public function signAgreement(Request $request, string $id)"""
assert s.count(old) == 1, 'B3 signAgreement anchor'
s = s.replace(old, """    /**
     * MARKER-RENTAL-WAIVER-DISPLAY-BE — push the waiver to the screen paired
     * with the staff member's current register.
     *
     * Every refusal returns a code the check-out page can act on, so the
     * staff member is never left with a button that silently does nothing.
     */
    public function sendAgreementToDisplay(Request $request, string $id)
    {
        $tenant = tenant();

        $rental = TenantRental::where('tenant_id', $tenant->id)->where('id', $id)
            ->with('customer')->firstOrFail();

        if ($rental->agreement_signed_at) {
            return response()->json(['ok' => false, 'code' => 'already_signed'], 200);
        }
        if ($rental->status !== 'reserved') {
            return response()->json(['ok' => false, 'code' => 'not_reserved'], 200);
        }

        $template = TenantRentalAgreementTemplate::where('tenant_id', $tenant->id)
            ->orderByDesc('version')->first();
        if (! $template) {
            return response()->json(['ok' => false, 'code' => 'no_template'], 200);
        }

        $registerId = (int) $request->session()->get('current_register_id', 0);
        $register = $registerId
            ? TenantRegister::where('tenant_id', $tenant->id)->where('is_active', true)->find($registerId)
            : null;

        if (! $register) {
            // Hand back the pickable registers so the page can offer the fix
            // inline instead of dead-ending on "no register selected".
            $request->session()->forget('current_register_id');
            return response()->json([
                'ok'        => false,
                'code'      => 'no_register',
                'registers' => TenantRegister::where('tenant_id', $tenant->id)
                    ->where('is_active', true)->orderBy('number')
                    ->get(['id', 'number', 'name'])
                    ->map(fn ($r) => ['id' => $r->id, 'label' => 'Register ' . $r->number . ' · ' . $r->name])
                    ->all(),
            ], 200);
        }

        $nonce = Str::random(40);
        $register->update([
            'display_mode'       => 'agreement',
            'display_rental_id'  => $rental->id,
            'display_mode_at'    => now(),
            'display_sign_nonce' => $nonce,
        ]);

        return response()->json([
            'ok'       => true,
            'register' => ['number' => $register->number, 'name' => $register->name],
        ]);
    }

    /**
     * MARKER-RENTAL-WAIVER-DISPLAY-BE — take the waiver back off the screen.
     *
     * Clears every register pointing at this rental, not just the session's
     * one: staff can switch registers mid-flow, and a waiver left live on an
     * abandoned screen is exactly the stranded state this must not allow.
     */
    public function recallAgreementFromDisplay(Request $request, string $id)
    {
        $tenant = tenant();

        $rental = TenantRental::where('tenant_id', $tenant->id)->where('id', $id)->firstOrFail();

        TenantRegister::where('tenant_id', $tenant->id)
            ->where('display_rental_id', $rental->id)
            ->get()
            ->each(fn (TenantRegister $r) => $r->clearDisplayMode());

        return response()->json(['ok' => true]);
    }

    /**
     * MARKER-RENTAL-WAIVER-DISPLAY-BE — status poll for the check-out page.
     *
     * Drives the live flip from "waiting" to "signed" without a reload, and
     * tells staff when a push has aged out so the screen state and the page
     * state can never disagree.
     */
    public function agreementStatus(Request $request, string $id)
    {
        $tenant = tenant();

        $rental = TenantRental::where('tenant_id', $tenant->id)->where('id', $id)->firstOrFail();

        $register = TenantRegister::where('tenant_id', $tenant->id)
            ->where('display_rental_id', $rental->id)->first();

        $displayState = 'none';
        if ($register) {
            $displayState = $register->agreementIsLive() ? 'waiting' : 'expired';
        }

        return response()->json([
            'ok'           => true,
            'signed'       => (bool) $rental->agreement_signed_at,
            'signer_name'  => $rental->agreement_signer_name,
            'method'       => $rental->agreement_method,
            'version'      => $rental->agreement_template_version,
            'signed_at'    => $rental->agreement_signed_at
                ? tlocal_datetime($rental->agreement_signed_at, 'M j, g:i A') : null,
            'pdf_url'      => $rental->agreement_pdf_path
                ? Storage::disk('public')->url($rental->agreement_pdf_path) : null,
            'signature_url' => $rental->agreement_signature_path
                ? Storage::disk('public')->url($rental->agreement_signature_path) : null,
            'display'      => $displayState,
            'register'     => $register ? ('Register ' . $register->number . ' · ' . $register->name) : null,
        ]);
    }

    public function signAgreement(Request $request, string $id)"""
)

io.open(p, 'w', encoding='utf-8').write(s)
print('patched', p)

# ============================================================ RegisterDisplayController
p = 'app/Http/Controllers/Tenant/RegisterDisplayController.php'
s = io.open(p, encoding='utf-8').read()

old = "use App\\Models\\Tenant\\TenantRegister;"
assert s.count(old) == 1, 'D1 import anchor'
s = s.replace(old, old + """
use App\\Models\\Tenant\\TenantRental; // MARKER-RENTAL-WAIVER-DISPLAY-BE
use App\\Models\\Tenant\\TenantRentalAgreementTemplate; // MARKER-RENTAL-WAIVER-DISPLAY-BE
use App\\Services\\Tenant\\RentalAgreementService; // MARKER-RENTAL-WAIVER-DISPLAY-BE""")

# --- displayPoll gains the override branch, ahead of the cart logic.
old = """        $snap = $register->display_cart;

        // A snapshot older than 90s means the POS page is gone — fall back
        // to idle instead of showing a stale cart to the next customer."""
assert s.count(old) == 1, 'D2 displayPoll anchor'
s = s.replace(old, """        // MARKER-RENTAL-WAIVER-DISPLAY-BE — a live waiver owns the screen.
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
        // to idle instead of showing a stale cart to the next customer.""")

# --- new methods before the closing brace of the class
old = """        return response()->json([
            'state' => $stale ? 'idle' : ($snap['state'] ?? 'idle'),
            'snap'  => $stale ? null : $snap,
        ]);
    }
}"""
assert s.count(old) == 1, 'D3 class tail anchor'
s = s.replace(old, """        return response()->json([
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
}""")

io.open(p, 'w', encoding='utf-8').write(s)
print('patched', p)

# ============================================================ routes
p = 'routes/web.php'
s = io.open(p, encoding='utf-8').read()

old = """    Route::get('/pay-display/{token}/state.json', [TenantControllers\\RegisterDisplayController::class, 'displayPoll'])->name('tenant.pay_display.poll');"""
assert s.count(old) == 1, 'RT1 public display anchor'
s = s.replace(old, old + """
    // MARKER-RENTAL-WAIVER-DISPLAY-BE — customer signs the rental waiver on
    // the paired screen. Token + nonce are the credential (CSRF-exempt: the
    // tablet may sit for hours and a 419 would be an unclearable dead end).
    Route::post('/pay-display/{token}/agreement/sign', [TenantControllers\\RegisterDisplayController::class, 'signAgreementFromDisplay'])->name('tenant.pay_display.agreement.sign');""")

old = """                Route::post('/rentals/bookings/{id}/agreement/sign',   [TenantControllers\\RentalBookingController::class, 'signAgreement'])->name('rentals.bookings.agreement.sign');"""
assert s.count(old) == 1, 'RT2 rental agreement anchor'
s = s.replace(old, old + """
                // MARKER-RENTAL-WAIVER-DISPLAY-BE — waiver on the paired customer screen.
                Route::post('/rentals/bookings/{id}/agreement/send-to-display', [TenantControllers\\RentalBookingController::class, 'sendAgreementToDisplay'])->name('rentals.bookings.agreement.send_display');
                Route::post('/rentals/bookings/{id}/agreement/recall-display',  [TenantControllers\\RentalBookingController::class, 'recallAgreementFromDisplay'])->name('rentals.bookings.agreement.recall_display');
                Route::get( '/rentals/bookings/{id}/agreement/status.json',     [TenantControllers\\RentalBookingController::class, 'agreementStatus'])->name('rentals.bookings.agreement.status');""")

io.open(p, 'w', encoding='utf-8').write(s)
print('patched', p)

# ============================================================ CSRF
p = 'bootstrap/app.php'
s = io.open(p, encoding='utf-8').read()
old = """            'funnel/track', // MARKER-FUNNEL-CSRF — anonymous analytics beacon"""
assert s.count(old) == 1, 'CS1 csrf anchor'
s = s.replace(old, old + """
            'pay-display/*/agreement/sign', // MARKER-RENTAL-WAIVER-DISPLAY-BE""")
io.open(p, 'w', encoding='utf-8').write(s)
print('patched', p)
PY

echo "--- balance check ---"
python3 - <<'PY'
import io
def bal(p):
    s = io.open(p, encoding='utf-8').read()
    i, n, d, par = 0, len(s), 0, 0
    while i < n:
        c = s[i]
        if c == '#' or (c == '/' and i+1 < n and s[i+1] == '/'):
            while i < n and s[i] != '\n': i += 1
        elif c == '/' and i+1 < n and s[i+1] == '*':
            i += 2
            while i+1 < n and not (s[i] == '*' and s[i+1] == '/'): i += 1
            i += 2
        elif c in '"\'':
            q = c; i += 1
            while i < n and s[i] != q:
                if s[i] == '\\': i += 1
                i += 1
            i += 1
        else:
            if c == '{': d += 1
            elif c == '}': d -= 1
            elif c == '(': par += 1
            elif c == ')': par -= 1
            i += 1
    return d, par
for f in ['app/Services/Tenant/RentalAgreementService.php',
          'app/Http/Controllers/Tenant/RentalBookingController.php',
          'app/Http/Controllers/Tenant/RegisterDisplayController.php',
          'routes/web.php', 'bootstrap/app.php']:
    d, par = bal(f)
    print(f, 'braces', d, 'parens', par)
PY

echo
echo "apply-rental-waiver-display-backend: OK"
