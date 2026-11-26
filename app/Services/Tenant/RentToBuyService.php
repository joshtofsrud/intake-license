<?php

namespace App\Services\Tenant;

use App\Models\Tenant\TenantDiscount;
use App\Models\Tenant\TenantRental;
use App\Models\Tenant;
use App\Support\Rentals\RentalPricing;
use Illuminate\Support\Str;

/**
 * MARKER-RENT-TO-BUY
 *
 * A renter who buys from the shop gets what they spent on the rental back
 * toward the purchase, up to the shop's day limit, plus an optional percent
 * off parts and accessories bought the same day.
 *
 * Mechanics: the credit is a single-use, fixed-amount code bound to the
 * renter (RTB-XXXXXX). Using it at the register issues a second code, bound
 * to the same customer, good until the shop closes that day (RTBP-XXXXXX).
 * Voiding the sale gives the credit back and withdraws an unused parts code.
 */
class RentToBuyService
{
    public const SOURCE_CREDIT = 'rent_to_buy';
    public const SOURCE_PARTS  = 'rent_to_buy_parts';

    public static function settings(Tenant $tenant): array
    {
        $s = $tenant->settings ?? [];
        return [
            'enabled'      => (bool) ($s['rtb_enabled'] ?? false),
            'max_days'     => max(1, (int) ($s['rtb_max_days'] ?? 4)),
            'window_days'  => max(1, (int) ($s['rtb_window_days'] ?? 30)),
            'min_purchase' => max(0, (int) ($s['rtb_min_purchase_cents'] ?? 0)),
            'parts_pct'    => max(0, min(90, (int) ($s['rtb_parts_pct'] ?? 0))),
        ];
    }

    /** The credit already issued for this rental, if any. */
    public function creditFor(TenantRental $rental): ?TenantDiscount
    {
        return TenantDiscount::where('tenant_id', $rental->tenant_id)
            ->where('rental_id', $rental->id)
            ->where('source', self::SOURCE_CREDIT)
            ->first();
    }

    /**
     * What this rental is worth toward a purchase: the unit lines' first
     * N days, never more than the rental cost after its discount, never
     * more than was actually paid.
     */
    public function amountFor(TenantRental $rental, int $maxDays): int
    {
        $rental->loadMissing('lines');
        $credit = 0;
        foreach ($rental->lines->where('kind', 'unit') as $l) {
            $units = (int) $l->duration_units;
            $rate  = (int) $l->rate_cents_snapshot;
            $total = (int) ($l->line_total_cents ?? $rate * $units);
            if (($l->rate_mode_snapshot ?? 'daily') === 'daily' && $units > $maxDays) {
                $extra = $units > 1 ? intdiv($total - $rate, $units - 1) : $rate;
                $credit += RentalPricing::dailyTotal($rate, $extra, $maxDays);
            } else {
                $credit += $total;
            }
        }
        $cost = max(0, (int) $rental->subtotal_cents - (int) ($rental->discount_cents ?? 0));
        return max(0, min($credit, $cost, (int) $rental->paid_cents));
    }

    /**
     * Issue the credit. Returns [discount|null, message]. Never issues twice.
     */
    public function issue(TenantRental $rental, ?string $userId = null): array
    {
        $tenant = Tenant::find($rental->tenant_id);
        $cfg = self::settings($tenant);
        if (! $cfg['enabled']) return [null, 'Rent to Buy is turned off in rental settings.'];
        if (! $rental->customer_id) return [null, 'This rental has no customer to hold the credit.'];
        if ($rental->status === 'cancelled') return [null, 'Cancelled rentals don\'t earn a credit.'];
        if ($existing = $this->creditFor($rental)) return [$existing, 'Credit already issued: ' . $existing->code . '.'];

        $amount = $this->amountFor($rental, $cfg['max_days']);
        if ($amount <= 0) return [null, 'Nothing paid on this rental yet, so there is no credit to issue.'];

        $discount = TenantDiscount::create([
            'tenant_id'          => $rental->tenant_id,
            'code'               => $this->freshCode('RTB-'),
            'label'              => 'Rent to Buy credit — rental ' . $rental->rental_number,
            'type'               => TenantDiscount::TYPE_FIXED,
            'value'              => $amount,
            'min_subtotal_cents' => max($amount, $cfg['min_purchase']),
            'max_discount_cents' => 0,
            'starts_at'          => now(),
            'ends_at'            => now($tenant->timezone())->addDays($cfg['window_days'])->endOfDay()->utc(),
            'max_redemptions'    => 1,
            'max_per_customer'   => 1,
            'redemption_count'   => 0,
            'is_active'          => true,
            'customer_id'        => $rental->customer_id,
            'rental_id'          => $rental->id,
            'source'             => self::SOURCE_CREDIT,
            'created_by'         => $userId,
        ]);

        return [$discount, 'Rent to Buy credit ' . $discount->code . ' issued — ' . format_money($amount) . ' toward a purchase.'];
    }

    /** Auto-issue on return. Quiet: a return never fails over a credit. */
    public function issueOnReturn(TenantRental $rental): ?string
    {
        try {
            $tenant = Tenant::find($rental->tenant_id);
            if (! self::settings($tenant)['enabled']) return null;
            [$d, $msg] = $this->issue($rental);
            return $d ? $msg : null;
        } catch (\Throwable $e) {
            \Log::error('rent_to_buy.issue_on_return_failed', ['rental_id' => $rental->id, 'error' => $e->getMessage()]);
            return null;
        }
    }

    /** Called by DiscountService::redeem() for every redemption. */
    public function onRedeemed(TenantDiscount $discount, ?string $customerId): void
    {
        if ($discount->source !== self::SOURCE_CREDIT || ! $customerId) return;
        $tenant = Tenant::find($discount->tenant_id);
        $pct = self::settings($tenant)['parts_pct'];
        if ($pct <= 0) return;
        if (TenantDiscount::where('parent_id', $discount->id)->exists()) return;

        TenantDiscount::create([
            'tenant_id'          => $discount->tenant_id,
            'code'               => $this->freshCode('RTBP-'),
            'label'              => 'Rent to Buy — ' . $pct . '% off parts & accessories today',
            'type'               => TenantDiscount::TYPE_PERCENT,
            'value'              => $pct,
            'min_subtotal_cents' => 0,
            'max_discount_cents' => 0,
            'starts_at'          => now(),
            'ends_at'            => now($tenant->timezone())->endOfDay()->utc(),
            'max_redemptions'    => 1,
            'max_per_customer'   => 1,
            'redemption_count'   => 0,
            'is_active'          => true,
            'customer_id'        => $customerId,
            'rental_id'          => $discount->rental_id,
            'source'             => self::SOURCE_PARTS,
            'parent_id'          => $discount->id,
        ]);
    }

    /** A voided purchase gives the credit back — withdraw its unused parts code. */
    public function onReleased(string $discountId): void
    {
        TenantDiscount::where('parent_id', $discountId)
            ->where('source', self::SOURCE_PARTS)
            ->where('redemption_count', 0)
            ->delete();
    }

    /** Open, usable credits for a customer (register + customer card). */
    public function openFor(string $tenantId, string $customerId)
    {
        return TenantDiscount::where('tenant_id', $tenantId)
            ->where('customer_id', $customerId)
            ->whereIn('source', [self::SOURCE_CREDIT, self::SOURCE_PARTS])
            ->where('is_active', true)
            ->where('redemption_count', 0)
            ->where(fn ($q) => $q->whereNull('ends_at')->orWhere('ends_at', '>', now()))
            ->orderBy('ends_at')
            ->get();
    }

    private function freshCode(string $prefix): string
    {
        do {
            $code = $prefix . strtoupper(Str::random(6));
            $code = strtr($code, ['0' => 'X', 'O' => 'Y', '1' => 'Z', 'I' => 'K', 'L' => 'M']);
        } while (TenantDiscount::where('code', $code)->exists());
        return $code;
    }
}
