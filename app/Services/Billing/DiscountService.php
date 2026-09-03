<?php

namespace App\Services\Billing;

use App\Models\Tenant;
use App\Models\TenantDiscount;
use Carbon\CarbonInterface;

/**
 * MARKER-BILLING-DISCOUNTS — apply a shop's discounts to a period's charges.
 *
 * Order matters and is fixed: percentages first, then fixed amounts, and a
 * discount can never take a line below zero. Two 60% discounts do not make a
 * refund; they make 84% off. This is stated here rather than left to whoever
 * writes the statement, so every surface computes the same number.
 *
 * Usage — email and texts — is never discounted. It is a pass-through cost.
 */
class DiscountService
{
    /** @return \Illuminate\Support\Collection<TenantDiscount> */
    public function activeFor(Tenant $tenant, CarbonInterface $on)
    {
        return TenantDiscount::where('tenant_id', $tenant->id)
            ->orderBy('starts_on')
            ->get()
            ->filter(fn (TenantDiscount $d) => $d->activeOn($on))
            ->values();
    }

    /**
     * @param  int  $platformCents  the plan
     * @param  int  $addonsCents    all add-ons
     * @return array{applied: array, discount_cents: int, platform_cents: int, addons_cents: int}
     */
    public function apply(Tenant $tenant, CarbonInterface $on, int $platformCents, int $addonsCents): array
    {
        $platform = $platformCents;
        $addons   = $addonsCents;
        $applied  = [];

        $discounts = $this->activeFor($tenant, $on);

        // percentages first, so a fixed amount never gets multiplied away
        foreach ($discounts->where('percent', '!=', null) as $d) {
            $base = $this->baseFor($d->scope, $platform, $addons);
            $cut  = (int) round($base * ($d->percent / 100));
            [$platform, $addons] = $this->deduct($d->scope, $platform, $addons, $cut);
            $applied[] = ['discount' => $d, 'cents' => $cut];
        }

        foreach ($discounts->where('amount_cents', '!=', null) as $d) {
            $base = $this->baseFor($d->scope, $platform, $addons);
            $cut  = min($base, (int) $d->amount_cents);   // never below zero
            [$platform, $addons] = $this->deduct($d->scope, $platform, $addons, $cut);
            $applied[] = ['discount' => $d, 'cents' => $cut];
        }

        return [
            'applied'        => $applied,
            'discount_cents' => array_sum(array_column($applied, 'cents')),
            'platform_cents' => $platform,
            'addons_cents'   => $addons,
        ];
    }

    private function baseFor(string $scope, int $platform, int $addons): int
    {
        return match ($scope) {
            'platform' => $platform,
            'addons'   => $addons,
            default    => $platform + $addons,
        };
    }

    /** Split a cut across the lines it applies to, largest first, no negatives. */
    private function deduct(string $scope, int $platform, int $addons, int $cut): array
    {
        if ($scope === 'platform') return [max(0, $platform - $cut), $addons];
        if ($scope === 'addons')   return [$platform, max(0, $addons - $cut)];

        $fromPlatform = min($platform, $cut);
        $cut         -= $fromPlatform;
        return [$platform - $fromPlatform, max(0, $addons - $cut)];
    }
}
