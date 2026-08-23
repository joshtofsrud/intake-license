#!/usr/bin/env python3
"""Rentals stretch: customer self-serve extension from the portal. An
active rental in /account/rentals shows a "Keep it longer" button when
the unit is genuinely free after the return — same eligibility brain as
the auto-offers, but customer-initiated pays FULL price (discounts stay
the shop's move). The button mints a portal-channel offer at 0% and
drops the customer straight onto the existing /x/{token} one-tap
checkout — one payment surface, one set of rails.
Run from repo root: python3 apply-rental-portal-self-extend.py
"""
import sys

def sub(p, old, new, label):
    s = open(p).read()
    if new in s:
        print(f"SKIP (already applied): {label}"); return
    if old not in s:
        print(f"FAIL: anchor not found for {label} in {p}"); sys.exit(1)
    open(p, 'w').write(s.replace(old, new, 1))
    print(f"OK: {label}")

# ============================================================
# 1) Service — allow a discount override for customer-initiated offers
# ============================================================
sub('app/Services/RentalExtensionOfferService.php',
    """    public function eligibility(Tenant $tenant, TenantRental $rental, ?string &$reason = null): ?array
    {
        $cfg = $this->settings($tenant);""",
    """    public function eligibility(Tenant $tenant, TenantRental $rental, ?string &$reason = null, ?int $discountOverride = null): ?array
    {
        $cfg = $this->settings($tenant);
        // MARKER-RENTAL-EXT-PORTAL — customer-initiated extensions pay full
        // price; the discount is the shop's move, not a self-serve coupon.
        if ($discountOverride !== null) {
            $cfg['discount_pct'] = max(0, min(90, $discountOverride));
        }""",
    "service: discount override")

sub('app/Services/RentalExtensionOfferService.php',
    """    /** Create the offer row and send the SMS. Returns the offer. */
    public function createAndSend(Tenant $tenant, TenantRental $rental, array $e, string $channel = 'auto'): TenantRentalExtensionOffer
    {""",
    """    /** Create the offer row and send the SMS. Returns the offer. */
    public function createAndSend(Tenant $tenant, TenantRental $rental, array $e, string $channel = 'auto'): TenantRentalExtensionOffer
    {
        $sendSms = $channel !== 'portal'; // MARKER-RENTAL-EXT-PORTAL — customer is already on the page""",
    "service: portal skips sms flag")

sub('app/Services/RentalExtensionOfferService.php',
    """        if ($customer?->phone) {
            try {
                SmsService::send($tenant, $customer->phone, $body);""",
    """        if ($sendSms && $customer?->phone) {
            try {
                SmsService::send($tenant, $customer->phone, $body);""",
    "service: gate sms")

# ============================================================
# 2) Portal controller op + route
# ============================================================
sub('routes/web.php',
    """    Route::get('/account/rentals',        [TenantControllers\\CustomerPortalController::class, 'rentals'])->name('tenant.customer.portal.rentals');""",
    """    Route::get('/account/rentals',        [TenantControllers\\CustomerPortalController::class, 'rentals'])->name('tenant.customer.portal.rentals');
    Route::post('/account/rentals/{id}/extend', [TenantControllers\\CustomerPortalController::class, 'extendRental'])->name('tenant.customer.portal.rentals.extend'); // MARKER-RENTAL-EXT-PORTAL""",
    "route")

sub('app/Http/Controllers/Tenant/CustomerPortalController.php',
    """        return view('public.account.portal.rentals', compact('customer', 'active', 'reserved', 'past'));
    }""",
    """        // MARKER-RENTAL-EXT-PORTAL — per active rental: can the customer
        // extend right now? Full price (discount 0). Reuse an open offer.
        $extendable = [];
        if ($tenant->rental_extensions_enabled) {
            $svc = app(\\App\\Services\\RentalExtensionOfferService::class);
            foreach ($active as $r) {
                $open = \\App\\Models\\Tenant\\TenantRentalExtensionOffer::where('rental_id', $r->id)
                    ->where('status', 'sent')
                    ->where(fn ($q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', now()))
                    ->orderByDesc('sent_at')->first();
                if ($open) { $extendable[$r->id] = ['open' => $open]; continue; }
                $reason = null;
                $e = $svc->eligibility($tenant, $r, $reason, 0);
                if ($e) $extendable[$r->id] = ['elig' => $e];
            }
        }

        return view('public.account.portal.rentals', compact('customer', 'active', 'reserved', 'past', 'extendable'));
    }

    // MARKER-RENTAL-EXT-PORTAL — mint (or reuse) an offer and land the
    // customer on the /x/{token} one-tap checkout. Full price, no SMS.
    public function extendRental(string $id)
    {
        if (! $customer = $this->customer()) {
            return redirect()->route('tenant.customer.login');
        }
        $tenant = tenant();
        abort_unless($tenant->rental_extensions_enabled, 404);

        $rental = TenantRental::where('tenant_id', $tenant->id)
            ->where('customer_id', $customer->id)
            ->findOrFail($id);

        $open = \\App\\Models\\Tenant\\TenantRentalExtensionOffer::where('rental_id', $rental->id)
            ->where('status', 'sent')
            ->where(fn ($q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', now()))
            ->orderByDesc('sent_at')->first();
        if ($open) {
            return redirect()->route('tenant.rentals.extension.show', $open->token);
        }

        $svc = app(\\App\\Services\\RentalExtensionOfferService::class);
        $reason = null;
        $e = $svc->eligibility($tenant, $rental, $reason, 0);
        if (!$e) {
            return redirect()->route('tenant.customer.portal.rentals')
                ->with('error', 'This rental can\\'t be extended right now: ' . $reason);
        }

        $offer = $svc->createAndSend($tenant, $rental, $e, 'portal');
        return redirect()->route('tenant.rentals.extension.show', $offer->token);
    }""",
    "controller op")

# ============================================================
# 3) Portal blade — button on active rental cards
# ============================================================
sub('resources/views/public/account/portal/rentals.blade.php',
    """        @if($r->deposit_hold_cents)""",
    """        {{-- MARKER-RENTAL-EXT-PORTAL — self-serve keep-it-longer --}}
        @php $ext = ($extendable ?? [])[$r->id] ?? null; @endphp
        @if($ext)
          <div style="margin-top:10px">
            @if(isset($ext['open']))
              <a href="{{ route('tenant.rentals.extension.show', $ext['open']->token) }}" class="ac-btn ac-btn--primary" style="text-decoration:none;display:block;text-align:center">
                Extend to {{ tlocal_datetime($ext['open']->extend_to, 'g:i A') }} — {{ format_money($ext['open']->total_cents) }}{{ $ext['open']->discount_pct > 0 ? ' (' . $ext['open']->discount_pct . '% off)' : '' }}
              </a>
            @else
              <form method="POST" action="{{ route('tenant.customer.portal.rentals.extend', $r->id) }}" style="display:inline">
                @csrf
                <button class="ac-btn ac-btn--primary">Keep it longer — extend to {{ tlocal_datetime($ext['elig']['extend_to'], 'g:i A') }} for {{ format_money($ext['elig']['total_cents']) }}</button>
              </form>
            @endif
          </div>
        @endif
        @if($r->deposit_hold_cents)""",
    "portal blade button")

print("Done. No migration needed. view:clear after deploy.")
