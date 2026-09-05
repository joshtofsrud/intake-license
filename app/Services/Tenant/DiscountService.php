<?php
// MARKER-DISCOUNTS

namespace App\Services\Tenant;

use App\Models\Tenant\TenantDiscount;
use App\Models\Tenant\TenantDiscountRedemption;
use Illuminate\Support\Facades\DB;

/**
 * The discount engine.
 *
 * validate() never throws and never writes — it answers "can this code be
 * used on this subtotal, by this customer, and for how much". redeem()
 * re-validates under a row lock, because between a cashier typing a code
 * and tendering the sale, another register may have used the last one.
 */
class DiscountService
{
    /**
     * @return array{ok:bool, reason:?string, amount_cents:int, discount:?TenantDiscount}
     */
    public function validate(
        string $tenantId,
        string $code,
        int $subtotalCents,
        ?string $customerId = null
    ): array {
        $fail = fn (string $why) => ['ok' => false, 'reason' => $why, 'amount_cents' => 0, 'discount' => null];

        $code = strtoupper(trim($code));
        if ($code === '') {
            return $fail('Enter a discount code.');
        }

        $discount = TenantDiscount::where('tenant_id', $tenantId)->where('code', $code)->first();
        if (! $discount) {
            return $fail('That code doesn\'t exist.');
        }

        if (! $discount->is_active) {
            return $fail('That code has been turned off.');
        }
        if ($discount->starts_at && $discount->starts_at->isFuture()) {
            return $fail('That code starts ' . $discount->starts_at->format('M j') . '.');
        }
        if ($discount->ends_at && $discount->ends_at->isPast()) {
            return $fail('That code expired ' . $discount->ends_at->format('M j') . '.');
        }
        if ($subtotalCents < $discount->min_subtotal_cents) {
            return $fail('This code needs a subtotal of at least $'
                . number_format($discount->min_subtotal_cents / 100, 2) . '.');
        }

        // Limits count ROWS, not the denormalised counter.
        if ($discount->max_redemptions > 0) {
            $used = TenantDiscountRedemption::where('discount_id', $discount->id)->count();
            if ($used >= $discount->max_redemptions) {
                return $fail('That code has been fully used.');
            }
        }

        if ($discount->max_per_customer > 0) {
            if (! $customerId) {
                return $fail('This code is limited per customer — add the customer to the sale first.');
            }
            $usedByThem = TenantDiscountRedemption::where('discount_id', $discount->id)
                ->where('customer_id', $customerId)->count();
            if ($usedByThem >= $discount->max_per_customer) {
                return $fail($discount->max_per_customer === 1
                    ? 'This customer has already used that code.'
                    : 'This customer has used that code the maximum number of times.');
            }
        }

        $amount = $this->amountFor($discount, $subtotalCents);
        if ($amount <= 0) {
            return $fail('That code doesn\'t take anything off this sale.');
        }

        return ['ok' => true, 'reason' => null, 'amount_cents' => $amount, 'discount' => $discount];
    }

    /**
     * The money off, clamped: never more than the cap, never more than the
     * subtotal. A discount can bring a sale to zero, never below.
     */
    public function amountFor(TenantDiscount $discount, int $subtotalCents): int
    {
        if ($subtotalCents <= 0) return 0;

        $amount = $discount->type === TenantDiscount::TYPE_PERCENT
            ? (int) floor($subtotalCents * $discount->value / 100)
            : (int) $discount->value;

        if ($discount->type === TenantDiscount::TYPE_PERCENT && $discount->max_discount_cents > 0) {
            $amount = min($amount, (int) $discount->max_discount_cents);
        }

        return max(0, min($amount, $subtotalCents));
    }

    /**
     * Record a use. Re-validates inside a transaction with the discount row
     * locked, so concurrent registers can't both take the last redemption.
     *
     * @return array{ok:bool, reason:?string, amount_cents:int, redemption:?TenantDiscountRedemption}
     */
    public function redeem(
        string $tenantId,
        string $code,
        int $subtotalCents,
        ?string $saleId,
        ?string $customerId,
        ?string $userId
    ): array {
        return DB::transaction(function () use ($tenantId, $code, $subtotalCents, $saleId, $customerId, $userId) {
            $code = strtoupper(trim($code));

            $locked = TenantDiscount::where('tenant_id', $tenantId)
                ->where('code', $code)
                ->lockForUpdate()
                ->first();

            if (! $locked) {
                return ['ok' => false, 'reason' => 'That code doesn\'t exist.', 'amount_cents' => 0, 'redemption' => null];
            }

            $check = $this->validate($tenantId, $code, $subtotalCents, $customerId);
            if (! $check['ok']) {
                return ['ok' => false, 'reason' => $check['reason'], 'amount_cents' => 0, 'redemption' => null];
            }

            $redemption = TenantDiscountRedemption::create([
                'tenant_id'           => $tenantId,
                'discount_id'         => $locked->id,
                'sale_id'             => $saleId,
                'customer_id'         => $customerId,
                'amount_cents'        => $check['amount_cents'],
                'subtotal_cents'      => $subtotalCents,
                'code'                => $code,
                'redeemed_by_user_id' => $userId,
            ]);

            $locked->update([
                'redemption_count' => TenantDiscountRedemption::where('discount_id', $locked->id)->count(),
            ]);

            // MARKER-PROMO-TAGS — the one place every surface passes through.
            app(DiscountTagService::class)->onRedeemed($locked, $customerId);

            return [
                'ok'           => true,
                'reason'       => null,
                'amount_cents' => $check['amount_cents'],
                'redemption'   => $redemption,
            ];
        });
    }

    /**
     * MARKER-REGISTER-DISCOUNT — release ONE redemption row directly. Used
     * when a sale fails after the code was redeemed but before the sale
     * exists, so there is no sale_id to look it up by.
     */
    public function releaseRedemption(TenantDiscountRedemption $redemption): void
    {
        $discountId = $redemption->discount_id;
        $customerId = $redemption->customer_id; // MARKER-PROMO-TAGS
        $tenantId   = $redemption->tenant_id;
        $redemption->delete();
        app(DiscountTagService::class)->onReleased($tenantId, $discountId, $customerId); // MARKER-PROMO-TAGS

        $d = TenantDiscount::find($discountId);
        if ($d) {
            $d->update([
                'redemption_count' => TenantDiscountRedemption::where('discount_id', $discountId)->count(),
            ]);
        }
    }

    /**
     * Undo a redemption — a voided or refunded sale must give the use back,
     * or a one-per-customer code is burned by a mistake.
     */
    public function releaseForSale(string $tenantId, string $saleId): int
    {
        $rows = TenantDiscountRedemption::where('tenant_id', $tenantId)
            ->where('sale_id', $saleId)->get();

        $released = 0;
        foreach ($rows as $row) {
            $discountId = $row->discount_id;
            $customerId = $row->customer_id; // MARKER-PROMO-TAGS
            $row->delete();
            $released++;
            app(DiscountTagService::class)->onReleased($tenantId, $discountId, $customerId); // MARKER-PROMO-TAGS

            $d = TenantDiscount::find($discountId);
            if ($d) {
                $d->update([
                    'redemption_count' => TenantDiscountRedemption::where('discount_id', $discountId)->count(),
                ]);
            }
        }

        return $released;
    }
}
