<?php

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Models\Tenant\TenantRental;
use App\Models\Tenant\TenantRentalExtensionOffer;
use App\Models\Tenant\TenantSale;
use App\Models\Tenant\TenantSaleItem;
use App\Models\Tenant\TenantSalePayment;
use App\Services\DirectPaymentsService;
use App\Services\Sms\SmsService;
use App\Services\Tenant\SalePaymentService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * MARKER-RENTAL-EXT — the magic-link surface. The token IS the auth:
 * no login, one screen, one tap. Payment rides the exact reserve-flow
 * rails (PI -> draft sale -> confirm records ledger payment), and the
 * rental's due_at moves only after Stripe says succeeded.
 */
class RentalExtensionOfferController extends Controller
{
    private function offerOr404(string $token): TenantRentalExtensionOffer
    {
        $tenant = tenant();
        abort_unless($tenant, 404);
        $offer = TenantRentalExtensionOffer::where('tenant_id', $tenant->id)
            ->where('token', $token)->first();
        abort_unless($offer, 404);
        return $offer;
    }

    public function show(string $token)
    {
        $tenant = tenant();
        $offer  = $this->offerOr404($token);
        $rental = TenantRental::with(['customer', 'lines.unit'])->find($offer->rental_id);
        abort_unless($rental, 404);

        // A paid offer shows its confirmation; anything else dead shows why.
        if ($offer->status === 'sent' && $offer->expires_at && $offer->expires_at->isPast()) {
            $offer->update(['status' => 'expired']);
        }

        return view('public.rental-extension', [
            'tenant' => $tenant,
            'offer'  => $offer,
            'rental' => $rental,
            'unit'   => $rental->lines->firstWhere('kind', 'unit')?->unit,
        ]);
    }

    public function decline(string $token)
    {
        $offer = $this->offerOr404($token);
        if ($offer->status === 'sent') {
            $offer->update(['status' => 'declined', 'responded_at' => now()]);
        }
        return redirect()->route('tenant.rentals.extension.show', $token);
    }

    /** Create the PI + draft sale; returns Stripe Elements bootstrap. */
    public function pay(string $token)
    {
        $tenant = tenant();
        $offer  = $this->offerOr404($token);
        if (!$offer->isOpen()) {
            return response()->json(['ok' => false, 'error' => 'This offer is no longer available.'], 410);
        }
        $rental = TenantRental::with('customer')->find($offer->rental_id);
        if (!$rental || $rental->status !== 'out' || $rental->returned_at) {
            return response()->json(['ok' => false, 'error' => 'This rental has already been returned.'], 410);
        }

        $payments = new DirectPaymentsService($tenant);
        if (!$payments->isEnabled()) {
            return response()->json(['ok' => false, 'error' => 'Online payment is not available — call the shop to extend.'], 422);
        }

        try {
            [$sale, $pi] = DB::transaction(function () use ($tenant, $offer, $rental, $payments) {
                // Reuse an existing draft if the customer double-taps.
                if ($offer->sale_id && $offer->stripe_payment_intent_id) {
                    $sale = TenantSale::find($offer->sale_id);
                    $pi   = $payments->retrievePaymentIntent($offer->stripe_payment_intent_id);
                    if ($sale && $pi && in_array($pi->status, ['requires_payment_method', 'requires_confirmation', 'requires_action'], true)) {
                        return [$sale, $pi];
                    }
                }

                $pi = $payments->createPaymentIntent($offer->total_cents, 'usd', [
                    'tenant_id' => $tenant->id,
                    'rental_id' => $rental->id,
                    'offer_id'  => $offer->id,
                    'purpose'   => 'rental_extension',
                ]);

                $sale = TenantSale::create([
                    'id'                       => (string) Str::uuid(),
                    'tenant_id'                => $tenant->id,
                    'sale_number'              => 'EXT-' . strtoupper(Str::random(6)),
                    'sale_date'                => now()->toDateString(),
                    'status'                   => 'pending',
                    'payment_status'           => 'draft',
                    'customer_id'              => $rental->customer_id,
                    'rental_id'                => $rental->id,
                    'stripe_payment_intent_id' => $pi->id,
                    'subtotal_cents'           => $offer->subtotal_cents,
                    'tax_cents'                => $offer->tax_cents,
                    'total_cents'              => $offer->total_cents,
                    'notes'                    => 'Last-minute extension for rental ' . $rental->rental_number,
                ]);
                TenantSaleItem::create([
                    'id'               => (string) Str::uuid(),
                    'tenant_id'        => $tenant->id,
                    'sale_id'          => $sale->id,
                    'type'             => 'open_item',
                    'name_snapshot'    => 'Rental extension — ' . $rental->rental_number . ' to ' . tlocal_datetime($offer->extend_to, 'g:i A'),
                    'quantity'         => 1,
                    'unit_price_cents' => $offer->subtotal_cents,
                    'line_total_cents' => $offer->subtotal_cents,
                    'is_taxable'       => $offer->tax_cents > 0,
                    'position'         => 0,
                ]);

                $offer->update(['sale_id' => $sale->id, 'stripe_payment_intent_id' => $pi->id]);
                return [$sale, $pi];
            });
        } catch (\Throwable $e) {
            Log::error('rental_ext.pay_bootstrap_failed', ['offer' => $offer->id, 'error' => $e->getMessage()]);
            return response()->json(['ok' => false, 'error' => 'Could not start the payment — try again.'], 500);
        }

        return response()->json([
            'ok'              => true,
            'client_secret'   => $pi->client_secret,
            'payment_intent'  => $pi->id,
            'publishable_key' => $payments->publishableKey(),
        ]);
    }

    /** Stripe said succeeded (client-side) — verify, extend, receipt. */
    public function confirm(string $token, Request $request)
    {
        $tenant = tenant();
        $offer  = $this->offerOr404($token);
        $request->validate(['payment_intent' => ['required', 'string', 'max:120']]);
        $piId = $request->input('payment_intent');

        if ($offer->stripe_payment_intent_id !== $piId || !$offer->sale_id) {
            return response()->json(['ok' => false, 'error' => 'Payment reference mismatch.'], 422);
        }
        $sale   = TenantSale::find($offer->sale_id);
        $rental = TenantRental::find($offer->rental_id);
        if (!$sale || !$rental) {
            return response()->json(['ok' => false, 'error' => 'Offer records are missing — contact the shop.'], 404);
        }

        // Idempotent: double-click or webhook may have beaten us.
        $already = TenantSalePayment::where('sale_id', $sale->id)
            ->where('external_reference', $piId)->exists();
        if (!$already) {
            try {
                $payments = new DirectPaymentsService($tenant);
                $pi = $payments->retrievePaymentIntent($piId);
            } catch (\Throwable $e) {
                Log::error('rental_ext.confirm_retrieve_failed', ['pi' => $piId, 'error' => $e->getMessage()]);
                return response()->json(['ok' => false, 'error' => 'Could not verify the payment.'], 502);
            }
            if ($pi->status !== 'succeeded') {
                return response()->json(['ok' => false, 'error' => 'Payment has not completed.'], 422);
            }
            if ((int) $pi->amount !== (int) $sale->total_cents) {
                Log::error('rental_ext.amount_mismatch', ['pi' => $piId, 'sale' => $sale->id]);
                return response()->json(['ok' => false, 'error' => 'Payment amount mismatch — contact the shop.'], 422);
            }

            app(SalePaymentService::class)->record(
                $sale,
                (int) $pi->amount,
                TenantSalePayment::KIND_PAYMENT,
                TenantSalePayment::SOURCE_BOOKING_FLOW,
                'card',
                null,
                $piId,
                'Last-minute rental extension payment.',
            );
        }

        if ($offer->status !== 'paid') {
            DB::transaction(function () use ($offer, $rental) {
                $rental->update([
                    'original_due_at' => $rental->original_due_at ?? $rental->due_at,
                    'due_at'          => $offer->extend_to,
                ]);
                $offer->update(['status' => 'paid', 'responded_at' => now()]);
            });

            $customer = $rental->customer;
            if ($customer?->phone) {
                try {
                    SmsService::send(tenant(), $customer->phone,
                        tenant()->name . ': you\'re extended! ' . 'New return time: '
                        . tlocal_datetime($offer->extend_to, 'g:i A') . '. Receipt: '
                        . rtrim(tenant()->publicUrl(), '/') . '/x/' . $offer->token);
                } catch (\Throwable $e) {
                    Log::warning('rental_ext.confirm_sms_failed', ['offer' => $offer->id]);
                }
            }
        }

        return response()->json(['ok' => true, 'next' => route('tenant.rentals.extension.show', $offer->token)]);
    }
}
