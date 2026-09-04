<?php

namespace App\Services\Billing;

use App\Models\Addon;
use App\Models\Tenant;
use App\Models\Tenant\TenantEmailLedgerEntry;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Schema;

/**
 * MARKER-BILLING-STATEMENT — one month's charges for one shop, assembled once
 * so every surface shows the same number.
 *
 * Deliberately does NOT decide what to charge a card. It states what a period
 * has come to; phase 5 decides what happens about it.
 */
class StatementService
{
    public function __construct(private DiscountService $discounts) {}

    public function for(Tenant $tenant, ?CarbonImmutable $monthStart = null): array
    {
        $start = ($monthStart ?? CarbonImmutable::now())->startOfMonth();
        $end   = $start->endOfMonth();

        // MARKER-STATEMENT-HISTORY — a month that finished before the shop
        // existed has no statement. Anything else would be fiction.
        $createdAt = $tenant->created_at ? CarbonImmutable::parse($tenant->created_at) : null;
        if ($createdAt && $end->lt($createdAt)) {
            return [
                'exists'      => false,
                'period'      => ['start' => $start, 'end' => $end, 'label' => $start->format('F Y')],
                'created_at'  => $createdAt,
                'total_cents' => 0,
            ];
        }

        // MARKER-STATEMENT-HISTORY — plan and add-ons describe TODAY'S
        // arrangement; there is no record of what a shop had last April, so a
        // past month shows usage only, which is genuinely historical because
        // every ledger row keeps its own rate and date.
        $isCurrentMonth = $start->isSameMonth(CarbonImmutable::now());

        $plan   = $isCurrentMonth ? $this->plan($tenant)   : ['tier' => $tenant->plan_tier, 'label' => ucfirst((string) $tenant->plan_tier), 'locations' => 0, 'unit' => 0, 'cents' => 0];
        $addons = $isCurrentMonth ? $this->addons($tenant) : [];
        $usage  = $this->usage($tenant, $start, $end);

        $addonsTotal = array_sum(array_column($addons, 'cents'));

        $applied = $this->discounts->apply($tenant, $start, $plan['cents'], $addonsTotal);

        $beforeDiscount = $plan['cents'] + $addonsTotal + $usage['cents'];
        $afterDiscount  = $applied['platform_cents'] + $applied['addons_cents'] + $usage['cents'];

        return [
            'exists'          => true,
            'usage_only'      => ! $isCurrentMonth,   // MARKER-STATEMENT-HISTORY
            'period'          => ['start' => $start, 'end' => $end, 'label' => $start->format('F Y')],
            'plan'            => $plan,
            'addons'          => $addons,
            'addons_cents'    => $addonsTotal,
            'usage'           => $usage,
            'discounts'       => $applied['applied'],
            'discount_cents'  => $applied['discount_cents'],
            'before_cents'    => $beforeDiscount,
            'total_cents'     => $afterDiscount,
            // What the shop is NOT paying, which is worth saying out loud.
            'saving_cents'    => $applied['discount_cents'],
        ];
    }

    // ---- pieces -------------------------------------------------------

    private function plan(Tenant $tenant): array
    {
        $tier   = $tenant->plan_tier ?: 'starter';
        $prices = (array) \App\Support\PlanPricing::all();
        $cents  = (int) ($prices[$tier] ?? 0);

        // Per-location licensing: the plan covers one, extra locations multiply.
        $locations = max(1, (int) ($tenant->licensed_locations ?: 1));

        return [
            'tier'      => $tier,
            'label'     => ucfirst($tier),
            'locations' => $locations,
            'unit'      => $cents,
            'cents'     => $cents * $locations,
        ];
    }

    /**
     * Active add-ons with their price. An add-on the plan already includes is
     * listed at $0 rather than omitted — a shop should see what they have, not
     * only what they pay for.
     */
    private function addons(Tenant $tenant): array
    {
        if (! Schema::hasTable('tenant_feature_addons')) return [];

        $rows = $tenant->activeAddons()->get();
        if ($rows->isEmpty()) return [];

        $catalog = Addon::whereIn('code', $rows->pluck('addon_code'))->get()->keyBy('code');
        $out     = [];

        foreach ($rows as $row) {
            $addon = $catalog->get($row->addon_code);
            if (! $addon) continue;

            $included = in_array($tenant->plan_tier, (array) ($addon->included_in_plans ?? []), true);
            // A one-time fee is not part of a monthly total; it is shown, at zero,
            // so the shop can see they have it.
            $recurring = ($addon->billing_cadence !== 'one_time');

            $out[] = [
                'code'      => $row->addon_code,
                'name'      => $addon->name,
                // MARKER-ADDON-CATALOG — the dated price, not the column, so a price
                //  change does not rewrite what an older statement said.
                'cents'     => ($included || ! $recurring) ? 0 : \App\Support\AddonPricing::for($row->addon_code),
                'note'      => $included ? 'included in ' . ucfirst((string) $tenant->plan_tier)
                              : (! $recurring ? 'one-time' : null),
                'status'    => $row->status,
                'since'     => $row->activated_at,
                'canceling' => $row->status === 'canceling' ? $row->canceling_at : null,
            ];
        }

        usort($out, fn ($a, $b) => $b['cents'] <=> $a['cents']);
        return $out;
    }

    /**
     * Email and texts for the period. Each ledger row keeps the rate it was
     * written at, so the rate is reported as a range when they differ —
     * a single figure would not reconcile against the total.
     */
    private function usage(Tenant $tenant, CarbonImmutable $start, CarbonImmutable $end): array
    {
        $base = fn (string $channel) => TenantEmailLedgerEntry::where('tenant_id', $tenant->id)
            ->where('channel', $channel)
            ->where('status', TenantEmailLedgerEntry::STATUS_SENT)
            ->whereBetween('created_at', [$start, $end]);

        // MARKER-EMAIL-RATES — campaigns, transactional and free are three
        // different answers; one blended range read as a bug.
        $marketing = (clone $base('email'))->where('kind', 'campaign')->where('is_free', false)
            ->selectRaw('COUNT(*) n, SUM(rate) spend, MIN(rate) lo, MAX(rate) hi')->first();

        $transactional = (clone $base('email'))->where('kind', '!=', 'campaign')->where('is_free', false)
            ->selectRaw('COUNT(*) n, SUM(rate) spend, MIN(rate) lo, MAX(rate) hi')->first();

        $free = (clone $base('email'))->where('is_free', true)->count();

        $email = (clone $base('email'))
            ->selectRaw('COUNT(*) n, SUM(rate * segments) spend, MIN(rate) lo, MAX(rate) hi')->first();

        $sms = (clone $base('sms'))
            ->selectRaw('COUNT(*) n, SUM(segments) segs, SUM(rate * segments) spend, MIN(rate) lo, MAX(rate) hi')->first();

        $emailCents = (int) round(((float) ($email->spend ?? 0)) * 100);
        $smsCents   = (int) round(((float) ($sms->spend ?? 0)) * 100);

        return [
            'email' => [
                'count' => (int) ($email->n ?? 0),
                'cents' => $emailCents,
                'rate'  => $this->rateLabel($email->lo ?? null, $email->hi ?? null),
                // MARKER-EMAIL-RATES
                'marketing' => [
                    'count' => (int) ($marketing->n ?? 0),
                    'cents' => (int) round(((float) ($marketing->spend ?? 0)) * 100),
                    'rate'  => $this->rateLabel($marketing->lo ?? null, $marketing->hi ?? null),
                ],
                'transactional' => [
                    'count' => (int) ($transactional->n ?? 0),
                    'cents' => (int) round(((float) ($transactional->spend ?? 0)) * 100),
                    'rate'  => $this->rateLabel($transactional->lo ?? null, $transactional->hi ?? null),
                ],
                'free' => [
                    'count'     => (int) $free,
                    'allowance' => \App\Services\EmailLedger::freeAllowance($tenant->id),
                ],
            ],
            'sms' => [
                'count'    => (int) ($sms->n ?? 0),
                'segments' => (int) ($sms->segs ?? 0),
                'cents'    => $smsCents,
                'rate'     => $this->rateLabel($sms->lo ?? null, $sms->hi ?? null),
                // A shop on its own Twilio meters at zero; say so rather than
                // showing a $0 line that looks like a bug.
                'byo'      => (bool) ($tenant->twilio_account_sid && $tenant->twilio_auth_token),
            ],
            'cents' => $emailCents + $smsCents,
        ];
    }

    private function rateLabel($lo, $hi): ?string
    {
        if ($lo === null) return null;
        $lo = (float) $lo; $hi = (float) $hi;
        $fmt = fn ($v) => '$' . rtrim(rtrim(number_format($v, 5), '0'), '.');
        return $lo === $hi ? $fmt($lo) : $fmt($lo) . '–' . $fmt($hi);
    }

    public static function money(int $cents): string
    {
        return '$' . number_format($cents / 100, 2);
    }
}
