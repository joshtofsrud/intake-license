<?php

namespace App\Services;

use App\Models\Tenant;
use App\Models\Tenant\TenantClassMembershipProduct;
use App\Models\Tenant\TenantClassOnlineCheckout;
use App\Models\Tenant\TenantClassPackProduct;
use App\Models\Tenant\TenantClassRegistration;
use App\Models\Tenant\TenantClassSession;
use App\Models\Tenant\TenantCustomer;
use App\Models\Tenant\TenantCustomerMembership;
use App\Models\Tenant\TenantCustomerPack;
use App\Services\Tenant\DirectPaymentsService;
use App\Services\Tenant\SaleService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * Selling classes online. MARKER-CLASSES-P3
 *
 * Passes (packs, memberships, intro offers) the studio marked "Sell online",
 * and single classes paid online, all charged on the studio's own Stripe
 * account (Direct Payments). A paid checkout becomes an ordinary card sale,
 * so it shows in sales history, day close and refunds like a register sale;
 * the sale's class_pass line issues the pass (ClassPassService).
 *
 * start()    validates, finds or creates the customer, makes the payment.
 * finalize() settles a paid checkout exactly once, from the browser return
 *            or the Stripe webhook, whichever comes first. If it can't be
 *            settled (the class filled up while they paid, say) the payment
 *            is refunded and the checkout is marked failed.
 */
class ClassOnlineSaleService
{
    /** What a class booked online without a pass can be paid with. */
    public const DROPIN_MODES = [
        'both' => 'Pay online or at the studio',
        'card' => 'Pay online only',
        'desk' => 'Pay at the studio only',
    ];

    public static function payments(Tenant $tenant): DirectPaymentsService
    {
        return app()->makeWith(DirectPaymentsService::class, ['tenant' => $tenant]);
    }

    /** The studio can take cards online: Direct Payments set up with keys. */
    public static function cardsOn(Tenant $tenant): bool
    {
        try {
            $dp = self::payments($tenant);
            return $dp->isEnabled() && (bool) $dp->publishableKey();
        } catch (\Throwable $e) {
            return false;
        }
    }

    /** The studio's setting, as saved. */
    public static function dropinSetting(Tenant $tenant): string
    {
        $v = (string) (($tenant->settings ?? [])['class_online_dropin'] ?? 'both');
        return array_key_exists($v, self::DROPIN_MODES) ? $v : 'both';
    }

    /** What actually applies: no card payments means pay at the studio. */
    public static function dropinMode(Tenant $tenant): string
    {
        return self::cardsOn($tenant) ? self::dropinSetting($tenant) : 'desk';
    }

    /** @return array{intro: Collection, packs: Collection, memberships: Collection} */
    public static function products(Tenant $tenant): array
    {
        $packs = TenantClassPackProduct::where('tenant_id', $tenant->id)
            ->where('is_active', true)->where('sell_online', true)
            ->orderBy('price_cents')->get();
        $memberships = TenantClassMembershipProduct::where('tenant_id', $tenant->id)
            ->where('is_active', true)->where('sell_online', true)
            ->orderByDesc('price_cents')->get();

        return [
            'intro'       => $packs->where('is_intro', true)->values(),
            'packs'       => $packs->where('is_intro', false)->values(),
            'memberships' => $memberships,
        ];
    }

    /** Has this customer ever had an intro pack (any status)? */
    public static function introUsed(string $tenantId, ?TenantCustomer $customer): bool
    {
        if (! $customer) {
            return false;
        }
        return TenantCustomerPack::where('tenant_id', $tenantId)
            ->where('customer_id', $customer->id)
            ->whereIn('product_id', TenantClassPackProduct::where('tenant_id', $tenantId)->where('is_intro', true)->select('id'))
            ->exists();
    }

    /** A product that may be bought online right now, or null. */
    public static function sellable(Tenant $tenant, string $kind, ?string $productId): TenantClassPackProduct|TenantClassMembershipProduct|null
    {
        $p = ClassPassService::product($tenant->id, $kind, $productId);
        return ($p && $p->is_active && $p->sell_online) ? $p : null;
    }

    /** The class can still be booked online by a customer. */
    public static function bookable(?TenantClassSession $s): bool
    {
        return $s
            && in_array($s->status, ['scheduled', 'confirmed'], true)
            && $s->starts_at->isFuture()
            && $s->spotsRemaining() > 0
            && ClassRules::for()->bookingBlock($s) === null; // MARKER-CLASSES-P4
    }

    /** @return array{name:string, price:int, tax:int, total:int} */
    public static function quote(Tenant $tenant, string $kind, $product, ?TenantClassSession $session, ?TenantCustomer $customer): array
    {
        if ($kind === 'dropin') {
            $name    = 'Drop-in: ' . $session->template->name . ' · ' . tlocal($session->starts_at, 'M j, g:i A');
            $price   = (int) $session->template->price_cents;
            $taxable = true; // as at the register
        } else {
            $name    = $product->name;
            $price   = (int) $product->price_cents;
            $taxable = (bool) $product->is_taxable;
        }
        $rate = (float) ($tenant->default_tax_rate ?? 0);
        $tax  = ($taxable && $rate > 0 && ! ($customer?->tax_exempt)) ? (int) round($price * ($rate / 100)) : 0;

        return ['name' => $name, 'price' => $price, 'tax' => $tax, 'total' => $price + $tax];
    }

    /**
     * Start an online payment.
     *
     * $in: kind (pack|membership|dropin), product_id, session_id,
     *      first_name, last_name, email (guests only)
     *
     * @return array{checkout: TenantClassOnlineCheckout, client_secret: string}
     */
    public function start(Tenant $tenant, array $in, ?TenantCustomer $signedIn): array
    {
        if (! self::cardsOn($tenant)) {
            throw new RuntimeException($tenant->name . ' takes payment at the studio. Book your class and pay when you arrive.');
        }

        $kind    = (string) ($in['kind'] ?? '');
        $product = null;
        $session = null;

        if (! empty($in['session_id'])) {
            $session = TenantClassSession::where('tenant_id', $tenant->id)->with('template')->find($in['session_id']);
            if (! self::bookable($session)) {
                throw new RuntimeException('That class can no longer be booked online. Pick another time.');
            }
        }

        if ($kind === 'dropin') {
            if (! $session) {
                throw new RuntimeException('Pick a class first.');
            }
            if (! in_array(self::dropinMode($tenant), ['both', 'card'], true) || (int) $session->template->price_cents <= 0) {
                throw new RuntimeException('This class can\'t be paid for online.');
            }
        } elseif (in_array($kind, ['pack', 'membership'], true)) {
            $product = self::sellable($tenant, $kind, $in['product_id'] ?? null);
            if (! $product) {
                throw new RuntimeException('That pass is not for sale online right now.');
            }
            if ((int) $product->price_cents <= 0) {
                throw new RuntimeException('That pass is given at the studio. Ask at the desk.');
            }
        } else {
            throw new RuntimeException('Pick what you\'d like to buy.');
        }

        $customer = $signedIn ?: $this->guest($tenant, $in);

        if ($kind === 'pack' && $product->is_intro && self::introUsed($tenant->id, $customer)) {
            throw new RuntimeException('The ' . $product->name . ' offer is for first visits, and this email has had it before.');
        }
        if ($kind === 'membership' && TenantCustomerMembership::where('tenant_id', $tenant->id)
                ->where('customer_id', $customer->id)->where('product_id', $product->id)
                ->whereIn('status', ['active', 'past_due', 'paused'])->exists()) {
            throw new RuntimeException('You already have ' . $product->name . '. Contact the studio to change it.');
        }
        if ($session && TenantClassRegistration::where('class_session_id', $session->id)
                ->where('customer_id', $customer->id)
                ->whereIn('status', ['registered', 'checked_in', 'waitlisted'])->exists()) {
            throw new RuntimeException('You\'re already booked into this class.');
        }

        $q = self::quote($tenant, $kind, $product, $session, $customer);

        $checkout = TenantClassOnlineCheckout::create([
            'tenant_id'    => $tenant->id,
            'customer_id'  => $customer->id,
            'kind'         => $kind,
            'product_id'   => $product?->id,
            'session_id'   => $session?->id,
            'amount_cents' => $q['price'],
            'tax_cents'    => $q['tax'],
            'status'       => 'pending',
        ]);

        $dp   = self::payments($tenant);
        $meta = [
            'intake_class_checkout_id' => $checkout->id,
            'intake_customer_id'       => $customer->id,
            'intake_kind'              => 'class_' . $kind,
        ];
        $pi = $kind === 'membership'
            ? $dp->createPaymentIntentSavingCard($q['total'], $dp->ensureCustomer($customer), $meta)
            : $dp->createPaymentIntent($q['total'], 'usd', $meta);

        $checkout->update(['stripe_payment_intent_id' => $pi->id]);

        return ['checkout' => $checkout, 'client_secret' => $pi->client_secret];
    }

    /** A guest's customer record: found by email in this shop, or made. */
    private function guest(Tenant $tenant, array $in): TenantCustomer
    {
        $email = mb_strtolower(trim((string) ($in['email'] ?? '')));
        if ($email === '' || ! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new RuntimeException('Enter your email so we can send your receipt.');
        }
        $first = trim((string) ($in['first_name'] ?? ''));
        $last  = trim((string) ($in['last_name'] ?? ''));
        if ($first === '') {
            throw new RuntimeException('Enter your name.');
        }

        return TenantCustomer::where('tenant_id', $tenant->id)->whereRaw('LOWER(email) = ?', [$email])->first()
            ?? TenantCustomer::create([
                'tenant_id'  => $tenant->id,
                'first_name' => mb_substr($first, 0, 80),
                'last_name'  => mb_substr($last, 0, 80),
                'email'      => $email,
            ]);
    }

    /**
     * Settle a paid checkout. Safe to call twice and from two places at
     * once: the row is claimed with a single conditional update, so only one
     * caller does the work.
     */
    public function finalize(TenantClassOnlineCheckout $checkout, \Stripe\PaymentIntent $pi): TenantClassOnlineCheckout
    {
        if ($checkout->status !== 'pending' && $checkout->status !== 'settling') {
            return $checkout;
        }

        $want = (int) $checkout->amount_cents + (int) $checkout->tax_cents;
        $piId = (string) ($pi->metadata->intake_class_checkout_id ?? '');
        if (! hash_equals((string) $checkout->id, $piId)
            || ($pi->status ?? null) !== 'succeeded'
            || (int) ($pi->amount_received ?? $pi->amount) !== $want
            || strtolower((string) ($pi->currency ?? 'usd')) !== 'usd') {
            Log::warning('classes.online: payment does not settle this checkout', [
                'checkout' => $checkout->id, 'pi' => $pi->id ?? null, 'pi_checkout' => $piId,
                'status' => $pi->status ?? null, 'amount' => $pi->amount ?? null, 'want' => $want,
            ]);
            return $checkout;
        }

        // Claim it. A 'settling' row older than ten minutes was left by a
        // request that died part way, and may be picked up again.
        $claimed = TenantClassOnlineCheckout::whereKey($checkout->id)
            ->where(fn ($q) => $q->where('status', 'pending')
                ->orWhere(fn ($q) => $q->where('status', 'settling')->where('updated_at', '<', now()->subMinutes(10))))
            ->update(['status' => 'settling', 'updated_at' => now()]);
        if (! $claimed) {
            return $checkout->fresh();
        }

        $tenant   = Tenant::find($checkout->tenant_id);
        $customer = TenantCustomer::where('tenant_id', $tenant->id)->find($checkout->customer_id);
        $session  = $checkout->session_id
            ? TenantClassSession::where('tenant_id', $tenant->id)->with('template')->find($checkout->session_id)
            : null;
        $card     = self::payments($tenant)->extractCardDetails($pi);
        $regs     = app(ClassRegistrationService::class);
        $reg      = null;

        try {
            if ($checkout->kind === 'dropin') {
                // Book first: if the class filled while they paid, nothing is sold.
                $reg = $regs->register($session->id, $customer->id, $tenant->id, 'online', false, false);
                if ($reg->status !== 'registered') {
                    throw new RuntimeException('That class filled up while you were paying.');
                }
                $reg->update(['paid_cents' => (int) $checkout->amount_cents]);
                $line = [
                    'type'             => 'open_item',
                    'name_snapshot'    => 'Drop-in: ' . $session->template->name . ' · ' . tlocal($session->starts_at, 'M j, g:i A'),
                    'quantity'         => 1,
                    'unit_price_cents' => (int) $checkout->amount_cents,
                    'is_taxable'       => true,
                ];
            } else {
                $line = [
                    'type'       => 'class_pass',
                    'quantity'   => 1,
                    'class_pass' => ['kind' => $checkout->kind, 'product_id' => $checkout->product_id],
                ];
            }

            $sale = app(SaleService::class)->createSale([
                'tenant_id'                => $tenant->id,
                'rang_up_by_user_id'       => null, // online
                'location_id'              => $tenant->defaultLocation?->id
                    ?? DB::table('tenant_locations')->where('tenant_id', $tenant->id)->orderBy('created_at')->value('id'),
                'customer_id'              => $customer->id,
                'status'                   => 'completed',
                'payment_status'           => 'paid',
                'payment_method'           => 'card',
                'stripe_payment_intent_id' => $pi->id,
                'card_brand'               => $card['brand'] ?? null,
                'card_last4'               => $card['last4'] ?? null,
                'paid_at'                  => now(),
                'notes'                    => 'Bought online',
                'items'                    => [$line],
            ]);

            if ((int) $sale->total_cents !== (int) ($pi->amount_received ?? $pi->amount)) {
                Log::warning('classes.online: sale total differs from the payment', [
                    'checkout' => $checkout->id, 'sale' => $sale->id, 'sale_total' => $sale->total_cents, 'paid' => $pi->amount,
                ]);
            }

            $passId = null;
            if ($checkout->kind !== 'dropin') {
                $itemId = $sale->items()->where('type', 'class_pass')->value('id');
                $passId = $checkout->kind === 'pack'
                    ? TenantCustomerPack::where('sale_item_id', $itemId)->value('id')
                    : TenantCustomerMembership::where('sale_item_id', $itemId)->value('id');

                // Bought from a class page: book them in with it. The pass is
                // theirs either way, so a full class only means the waitlist.
                if ($session) {
                    try {
                        $reg = $regs->register($session->id, $customer->id, $tenant->id, $checkout->kind, false, true);
                    } catch (\Throwable $e) {
                        $checkout->note = mb_substr('Pass bought; not booked: ' . $e->getMessage(), 0, 255);
                    }
                }
            }

            $checkout->forceFill([
                'status'          => 'paid',
                'sale_id'         => $sale->id,
                'registration_id' => $reg?->id,
                'pass_id'         => $passId,
            ])->save();
        } catch (\Throwable $e) {
            Log::error('classes.online: could not settle a paid checkout; refunding', [
                'checkout' => $checkout->id, 'pi' => $pi->id, 'error' => $e->getMessage(),
            ]);
            if ($reg && $checkout->kind === 'dropin') {
                try {
                    $regs->cancel($reg->id, $tenant->id, true);
                } catch (\Throwable $c) {
                    Log::error('classes.online: could not undo the booking', ['registration' => $reg->id, 'error' => $c->getMessage()]);
                }
            }
            $refunded = false;
            try {
                self::payments($tenant)->refundPaymentIntent($pi->id, 'requested_by_customer');
                $refunded = true;
            } catch (\Throwable $r) {
                Log::critical('classes.online: refund after a failed checkout ALSO failed', ['pi' => $pi->id, 'error' => $r->getMessage()]);
                report($r);
            }
            $why = $e instanceof RuntimeException ? $e->getMessage() : 'Something went wrong recording it.';
            $checkout->forceFill([
                'status' => 'failed',
                'note'   => mb_substr($why . ($refunded ? ' Your payment was refunded.' : ' The studio will refund your payment.'), 0, 255),
            ])->save();

            return $checkout;
        }

        $this->sendReceipt($tenant, $checkout->fresh(), $customer, $session, $card);

        return $checkout->fresh();
    }

    private function sendReceipt(Tenant $tenant, TenantClassOnlineCheckout $co, TenantCustomer $customer, ?TenantClassSession $session, array $card): void
    {
        if (! filled($customer->email)) {
            return;
        }
        try {
            $rows = [];
            if ($co->kind === 'dropin') {
                $rows[] = ['Class', $session->template->name . ' · ' . tlocal($session->starts_at, 'D M j, g:i A')];
            } else {
                $product = ClassPassService::product($tenant->id, $co->kind, $co->product_id);
                $rows[]  = ['Pass', $product?->name ?? 'Class pass'];
                if ($co->kind === 'membership' && ($m = TenantCustomerMembership::find($co->pass_id))) {
                    $rows[] = ['Renews', $m->current_period_end?->copy()->addDay()->format('M j, Y') . ' · $' . number_format(($m->renewal_price_cents ?? 0) / 100, 2) . ' a month'];
                } elseif ($co->kind === 'pack' && ($p = TenantCustomerPack::find($co->pass_id))) {
                    $rows[] = ['Classes', $p->credits_remaining . ' · use by ' . $p->expires_at?->format('M j, Y')];
                }
                if ($session) {
                    $rows[] = ['Booked', $session->template->name . ' · ' . tlocal($session->starts_at, 'D M j, g:i A')];
                }
            }
            $rows[] = ['Paid', '$' . number_format(($co->amount_cents + $co->tax_cents) / 100, 2)
                . (! empty($card['last4']) ? ' · ' . ucfirst((string) ($card['brand'] ?? 'card')) . ' ' . $card['last4'] : '')];

            $table = '<table style="font-size:14px;line-height:1.9;margin:10px 0">' . collect($rows)->map(fn ($r) =>
                '<tr><td style="color:#666;padding-right:16px">' . e($r[0]) . '</td><td><strong>' . e($r[1]) . '</strong></td></tr>'
            )->implode('') . '</table>';

            $account = $customer->password
                ? '<p><a href="' . e($tenant->urlTo('/account/passes')) . '" style="color:#111">See your passes</a></p>'
                : '<p style="font-size:13px;color:#666">To see your passes online, <a href="' . e($tenant->urlTo('/account/register')) . '" style="color:#111">create an account</a> with this email.</p>';

            (new EmailService($tenant))->send('class_purchase_receipt', $customer->email, [
                'first_name'   => e($customer->first_name ?: 'there'),
                'details'      => $table,
                'account_line' => $account,
                'schedule_url' => $tenant->urlTo('/classes'),
            ]);
        } catch (\Throwable $e) {
            Log::warning('classes.online: receipt not sent', ['checkout' => $co->id, 'error' => $e->getMessage()]);
        }
    }

    /**
     * A customer saved a new card for a membership (account page). It becomes
     * the card renewals use; a renewal that failed is retried straight away.
     * Returns what happened, for the message on the page.
     */
    public function useNewCard(TenantCustomerMembership $m, array $card): string
    {
        $m->forceFill([
            'billing_mode'             => 'card',
            'stripe_payment_method_id' => $card['id'],
            'card_brand'               => $card['brand'] ?? null,
            'card_last4'               => $card['last4'] ?? null,
            'renewal_price_cents'      => $m->renewal_price_cents ?? $m->product?->price_cents,
        ])->save();
        ClassCreditLedger::record($m->tenant_id, $m->customer_id, 0, 'card_updated', ['membership_id' => $m->id],
            'Card updated online' . (! empty($card['last4']) ? ' · ending ' . $card['last4'] : ''));

        if ($m->status !== 'past_due') {
            return 'saved';
        }

        $tenant = Tenant::find($m->tenant_id);
        $m->forceFill(['next_retry_at' => now()])->save();
        $did = app(MembershipBillingService::class)->one(
            $m->fresh('product'),
            now($tenant?->timezone() ?? config('app.timezone'))->toDateString(),
            now()
        );

        return $did === 'charged' ? 'charged' : 'failed';
    }
}
