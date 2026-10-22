<?php

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Models\Tenant\TenantClassCreditEntry;
use App\Models\Tenant\TenantClassOnlineCheckout;
use App\Models\Tenant\TenantClassSession;
use App\Models\Tenant\TenantCustomer;
use App\Models\Tenant\TenantCustomerMembership;
use App\Models\Tenant\TenantCustomerPack;
use App\Services\ClassOnlineSaleService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * Buying classes online: the passes page, checkout, and the member's own
 * passes page with "Update card". MARKER-CLASSES-P3
 */
class ClassOnlineController extends Controller
{
    public function __construct(private ClassOnlineSaleService $online) {}

    private function customer(): ?TenantCustomer
    {
        return Auth::guard('customer')->user();
    }

    // ---------------------------------------------------------- /classes/passes

    public function passes(Request $request)
    {
        $tenant = tenant();

        // A shared link (ads, social, the QR at the desk) opens that pass's checkout.
        if ($request->filled('offer')) {
            $offer = (string) $request->query('offer');
            foreach (['pack', 'membership'] as $kind) {
                if (ClassOnlineSaleService::sellable($tenant, $kind, $offer)) {
                    return redirect()->route('tenant.customer.classes.buy', ['kind' => $kind, 'productId' => $offer]);
                }
            }
        }

        $customer = $this->customer();
        $products = ClassOnlineSaleService::products($tenant);
        $intro    = ClassOnlineSaleService::introUsed($tenant->id, $customer) ? collect() : $products['intro'];

        $owned = $customer
            ? TenantCustomerMembership::where('tenant_id', $tenant->id)->where('customer_id', $customer->id)
                ->whereIn('status', ['active', 'past_due', 'paused'])->pluck('product_id')->all()
            : [];

        $cheapestDropin = \App\Models\Tenant\TenantClassTemplate::where('tenant_id', $tenant->id)
            ->active()->where('price_cents', '>', 0)->min('price_cents');

        return view('public.classes.passes', [
            'customer'       => $customer,
            'intro'          => $intro,
            'packs'          => $products['packs'],
            'memberships'    => $products['memberships'],
            'owned'          => $owned,
            'cardsOn'        => ClassOnlineSaleService::cardsOn($tenant),
            'cheapestDropin' => $cheapestDropin,
            'templateNames'  => \App\Models\Tenant\TenantClassTemplate::where('tenant_id', $tenant->id)->pluck('name', 'id')->all(),
        ]);
    }

    // ---------------------------------------------------- /classes/buy/{kind}/{id}

    public function buy(Request $request, string $kind, string $productId)
    {
        $tenant   = tenant();
        $customer = $this->customer();
        $product  = in_array($kind, ['pack', 'membership'], true)
            ? ClassOnlineSaleService::sellable($tenant, $kind, $productId) : null;
        if (! $product) {
            return redirect()->route('tenant.customer.classes.passes')
                ->withErrors(['buy' => 'That pass is not for sale online right now.']);
        }

        $session = null;
        if ($request->filled('session')) {
            $session = TenantClassSession::where('tenant_id', $tenant->id)->with('template')->find($request->query('session'));
            if (! ClassOnlineSaleService::bookable($session)) {
                $session = null;
            }
        }

        $introUsed = $kind === 'pack' && $product->is_intro && ClassOnlineSaleService::introUsed($tenant->id, $customer);
        $quote     = ClassOnlineSaleService::quote($tenant, $kind, $product, null, $customer);
        $alt       = $introUsed ? ClassOnlineSaleService::products($tenant)['packs']->sortBy('price_cents')->first() : null;

        return view('public.classes.buy', [
            'kind'      => $kind,
            'product'   => $product,
            'session'   => $session,
            'customer'  => $customer,
            'quote'     => $quote,
            'cardsOn'   => ClassOnlineSaleService::cardsOn($tenant),
            'stripePk'  => ClassOnlineSaleService::cardsOn($tenant) ? ClassOnlineSaleService::payments($tenant)->publishableKey() : null,
            'introUsed' => $introUsed,
            'alt'       => $alt,
            'renewsOn'  => now($tenant->timezone())->startOfDay()->addMonth(),
        ]);
    }

    // ------------------------------------------------------ POST /classes/checkout

    public function start(Request $request)
    {
        $tenant = tenant();
        $data   = $request->validate([
            'kind'       => ['required', 'in:pack,membership,dropin'],
            'product_id' => ['nullable', 'uuid'],
            'session_id' => ['nullable', 'uuid'],
            'first_name' => ['nullable', 'string', 'max:80'],
            'last_name'  => ['nullable', 'string', 'max:80'],
            'email'      => ['nullable', 'email', 'max:180'],
            'saves_card' => ['nullable', 'boolean'],
        ]);
        if ($data['kind'] === 'membership' && ! $request->boolean('saves_card')) {
            return response()->json(['ok' => false, 'message' => 'A membership renews on this card. Tick the box to save it.'], 422);
        }

        try {
            $r = $this->online->start($tenant, $data, $this->customer());
        } catch (RuntimeException $e) {
            return response()->json(['ok' => false, 'message' => $e->getMessage()], 422);
        } catch (\Throwable $e) {
            Log::error('classes.online: start failed', ['tenant' => $tenant->id, 'error' => $e->getMessage()]);
            return response()->json(['ok' => false, 'message' => 'Could not start the payment. Try again, or contact the studio.'], 500);
        }

        $mine   = (array) $request->session()->get('class_checkouts', []);
        $mine[] = $r['checkout']->id;
        $request->session()->put('class_checkouts', array_slice($mine, -10));

        return response()->json([
            'ok'            => true,
            'client_secret' => $r['client_secret'],
            'return_url'    => route('tenant.customer.classes.checkout.return'),
        ]);
    }

    // --------------------------------------------- GET /classes/checkout/return

    public function returnLeg(Request $request)
    {
        $tenant   = tenant();
        $checkout = TenantClassOnlineCheckout::where('tenant_id', $tenant->id)
            ->where('stripe_payment_intent_id', (string) $request->query('payment_intent'))
            ->first();
        if (! $checkout) {
            return redirect()->route('tenant.customer.classes.passes');
        }

        if (in_array($checkout->status, ['pending', 'settling'], true)) {
            try {
                $pi = ClassOnlineSaleService::payments($tenant)->retrievePaymentIntent($checkout->stripe_payment_intent_id);
                $checkout = $this->online->finalize($checkout, $pi);
            } catch (\Throwable $e) {
                Log::error('classes.online: return leg failed', ['checkout' => $checkout->id, 'error' => $e->getMessage()]);
            }
        }

        return redirect()->route('tenant.customer.classes.checkout.done', ['c' => $checkout->id]);
    }

    // ----------------------------------------------- GET /classes/checkout/done

    public function done(Request $request)
    {
        $tenant   = tenant();
        $customer = $this->customer();
        $id       = (string) $request->query('c');
        $mine     = in_array($id, (array) $request->session()->get('class_checkouts', []), true);

        $checkout = TenantClassOnlineCheckout::where('tenant_id', $tenant->id)->find($id);
        if (! $checkout || (! $mine && $checkout->customer_id !== $customer?->id)) {
            return redirect()->route('tenant.customer.classes.passes');
        }

        $buyer   = TenantCustomer::where('tenant_id', $tenant->id)->find($checkout->customer_id);
        $product = $checkout->product_id ? \App\Services\ClassPassService::product($tenant->id, $checkout->kind, $checkout->product_id) : null;
        $session = $checkout->session_id ? TenantClassSession::where('tenant_id', $tenant->id)->with('template')->find($checkout->session_id) : null;
        $reg     = $checkout->registration_id ? \App\Models\Tenant\TenantClassRegistration::find($checkout->registration_id) : null;
        $pack    = $checkout->kind === 'pack' && $checkout->pass_id ? TenantCustomerPack::find($checkout->pass_id) : null;
        $member  = $checkout->kind === 'membership' && $checkout->pass_id ? TenantCustomerMembership::find($checkout->pass_id) : null;
        $sale    = $checkout->sale_id ? \App\Models\Tenant\TenantSale::find($checkout->sale_id) : null;

        return view('public.classes.bought', compact('checkout', 'buyer', 'product', 'session', 'reg', 'pack', 'member', 'sale', 'customer'));
    }

    // ------------------------------------------------------- GET /account/passes

    public function myPasses()
    {
        if (! $customer = $this->customer()) {
            return redirect()->route('tenant.customer.login');
        }
        $tenant = tenant();

        $memberships = TenantCustomerMembership::where('tenant_id', $tenant->id)->where('customer_id', $customer->id)
            ->whereIn('status', ['active', 'past_due', 'paused'])->with('product')->orderBy('created_at')->get();
        $packs = TenantCustomerPack::where('tenant_id', $tenant->id)->where('customer_id', $customer->id)
            ->where('status', 'active')->with('product')->orderBy('expires_at')->get();
        $recent = TenantClassCreditEntry::where('tenant_id', $tenant->id)->where('customer_id', $customer->id)
            ->orderByDesc('created_at')->limit(12)->get();

        return view('public.account.portal.passes', [
            'customer'    => $customer,
            'memberships' => $memberships,
            'packs'       => $packs,
            'recent'      => $recent,
            'cardsOn'     => ClassOnlineSaleService::cardsOn($tenant),
            'stripePk'    => ClassOnlineSaleService::cardsOn($tenant) ? ClassOnlineSaleService::payments($tenant)->publishableKey() : null,
        ]);
    }

    // --------------------------------------- POST /account/passes/{id}/card (JSON)

    public function startCard(Request $request, string $id)
    {
        $tenant = tenant();
        if (! ($customer = $this->customer()) || ! ClassOnlineSaleService::cardsOn($tenant)) {
            return response()->json(['ok' => false, 'message' => 'Card updates aren\'t available. Contact the studio.'], 422);
        }
        $m = TenantCustomerMembership::where('tenant_id', $tenant->id)->where('customer_id', $customer->id)
            ->whereIn('status', ['active', 'past_due', 'paused'])->find($id);
        if (! $m) {
            return response()->json(['ok' => false, 'message' => 'That membership isn\'t active.'], 404);
        }

        try {
            $dp = ClassOnlineSaleService::payments($tenant);
            $si = $dp->createSetupIntent($dp->ensureCustomer($customer), [
                'intake_membership_id' => $m->id,
                'intake_customer_id'   => $customer->id,
                'intake_kind'          => 'membership_card',
            ]);
        } catch (\Throwable $e) {
            Log::error('classes.online: setup intent failed', ['membership' => $m->id, 'error' => $e->getMessage()]);
            return response()->json(['ok' => false, 'message' => 'Could not start the card update. Try again.'], 500);
        }

        return response()->json([
            'ok'            => true,
            'client_secret' => $si->client_secret,
            'return_url'    => route('tenant.customer.portal.passes.card'),
        ]);
    }

    // --------------------------------------------- GET /account/passes/card

    public function cardReturn(Request $request)
    {
        $tenant = tenant();
        if (! $customer = $this->customer()) {
            return redirect()->route('tenant.customer.login');
        }

        try {
            $si = ClassOnlineSaleService::payments($tenant)->retrieveSetupIntent((string) $request->query('setup_intent'));
        } catch (\Throwable $e) {
            return redirect()->route('tenant.customer.portal.passes')->withErrors(['card' => 'We couldn\'t confirm that card. Try again.']);
        }

        $m = TenantCustomerMembership::where('tenant_id', $tenant->id)->where('customer_id', $customer->id)
            ->with('product')->find($si['metadata']['intake_membership_id'] ?? null);
        if (! $m || $si['status'] !== 'succeeded' || ! $si['id']
            || ($si['metadata']['intake_customer_id'] ?? null) !== $customer->id
            || ($customer->stripe_customer_id && $si['customer'] !== $customer->stripe_customer_id)) {
            return redirect()->route('tenant.customer.portal.passes')->withErrors(['card' => 'We couldn\'t confirm that card. Try again.']);
        }

        // Already applied (a refresh of this page): nothing more to do.
        if ($m->stripe_payment_method_id === $si['id']) {
            return redirect()->route('tenant.customer.portal.passes')->with('success', 'Card saved.');
        }

        $result = $this->online->useNewCard($m, $si);
        $m->refresh();

        return redirect()->route('tenant.customer.portal.passes')->with(
            $result === 'failed' ? 'error' : 'success',
            match ($result) {
                'charged' => 'Card saved and your renewal is paid. Thanks!',
                'failed'  => 'Card saved, but the renewal charge didn\'t go through: ' . ($m->last_charge_error ?: 'declined') . '. Try another card.',
                default   => 'Card saved. Renewals will use it from now on.',
            }
        );
    }
}
