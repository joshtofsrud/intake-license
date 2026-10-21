<?php

namespace App\Services;

use App\Models\Tenant;
use App\Models\Tenant\TenantCustomer;
use App\Models\Tenant\TenantCustomerMembership;
use App\Models\Tenant\TenantSale;
use App\Services\Tenant\DirectPaymentsService;
use App\Services\Tenant\SaleService;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Membership renewals, run daily by memberships:tick. MARKER-CLASSES-P2
 *
 * Each membership has a billing_mode:
 *   card  the shop charges the card saved when it was sold. A paid charge
 *         is an ordinary register sale (card), so it shows in history,
 *         day close and refunds. A decline sets past_due and is retried
 *         1, 3 and 7 days after the renewal date; still failing after
 *         that, the membership ends (expired).
 *   desk  no card on file. On the renewal date it becomes past_due and
 *         shows as due; staff take payment with "Renew at the register".
 *         Not paid within 7 days, it ends.
 *   comp  given by staff. Rolls over with no charge, as all memberships
 *         did before this patch.
 *
 * A past_due membership still books classes during those 7 days.
 * cancel_at_period_end ends it on the renewal date; pause_until skips
 * renewals until that date, then the next period starts.
 */
class MembershipBillingService
{
    public const RETRY_DAYS = [1, 3, 7];

    /** @return array{renewed:int, charged:int, failed:int, due:int, ended:int, paused:int} */
    public function run(?Carbon $now = null): array
    {
        $now    = $now ?? now();
        $counts = ['renewed' => 0, 'charged' => 0, 'failed' => 0, 'due' => 0, 'ended' => 0, 'paused' => 0];
        $today  = $this->tenantLocalDates($now);

        TenantCustomerMembership::whereIn('status', ['active', 'past_due', 'paused'])
            ->with('product')
            ->get()
            ->each(function (TenantCustomerMembership $m) use (&$counts, $today, $now) {
                $local = $today[$m->tenant_id] ?? $now->toDateString();
                try {
                    $did = $this->one($m, $local, $now);
                    if ($did) {
                        $counts[$did]++;
                    }
                } catch (\Throwable $e) {
                    Log::error('memberships: renewal step failed', ['membership_id' => $m->id, 'error' => $e->getMessage()]);
                    report($e);
                }
            });

        return $counts;
    }

    /** One membership, one decision. Returns the counter to bump, or null. */
    public function one(TenantCustomerMembership $m, string $localToday, Carbon $now): ?string
    {
        // A pause that has run out: the next period starts today.
        if ($m->status === 'paused') {
            if (! $m->pause_until || $m->pause_until->toDateString() > $localToday) {
                return null;
            }
            $m->forceFill([
                'status'             => 'active',
                'pause_until'        => null,
                'current_period_end' => Carbon::parse($localToday)->subDay(),
            ])->save();
            ClassCreditLedger::record($m->tenant_id, $m->customer_id, 0, 'resumed', ['membership_id' => $m->id], 'Pause ended');
        }

        $due = $m->current_period_end && $m->current_period_end->toDateString() < $localToday;

        if ($m->status === 'active') {
            if (! $due) {
                return null;
            }
            if ($m->cancel_at_period_end) {
                $m->update(['status' => 'cancelled']);
                ClassCreditLedger::record($m->tenant_id, $m->customer_id, 0, 'ended', ['membership_id' => $m->id], 'Cancelled at the end of the period');
                return 'ended';
            }
            if ($m->pause_until && $m->pause_until->toDateString() > $localToday) {
                $m->update(['status' => 'paused']);
                ClassCreditLedger::record($m->tenant_id, $m->customer_id, 0, 'paused', ['membership_id' => $m->id], 'Paused until ' . $m->pause_until->format('M j'));
                return 'paused';
            }
            return match ($m->billing_mode) {
                'card'  => $this->charge($m, $now),
                'desk'  => $this->markDue($m),
                default => $this->rollFree($m, $localToday),
            };
        }

        // past_due: retry a card on schedule; a desk membership just waits.
        if ($m->status === 'past_due') {
            if (! $m->next_retry_at || $m->next_retry_at->gt($now)) {
                return null;
            }
            if ($m->billing_mode === 'card' && $m->failed_attempts <= count(self::RETRY_DAYS)) {
                return $this->charge($m, $now);
            }
            $m->update(['status' => 'expired', 'next_retry_at' => null]);
            ClassCreditLedger::record($m->tenant_id, $m->customer_id, 0, 'ended', ['membership_id' => $m->id],
                $m->billing_mode === 'card' ? 'Renewal payment failed ' . $m->failed_attempts . ' times' : 'Renewal not paid within 7 days');
            return 'ended';
        }

        return null;
    }

    private function rollFree(TenantCustomerMembership $m, string $localToday): string
    {
        $start = $m->current_period_end->copy()->addDay();
        if ($start->toDateString() < $localToday) {
            $start = Carbon::parse($localToday);
        }
        $m->update([
            'current_period_start'     => $start,
            'current_period_end'       => $start->copy()->addMonth()->subDay(),
            'classes_used_this_period' => 0,
        ]);
        ClassCreditLedger::record($m->tenant_id, $m->customer_id, 0, 'renewed', ['membership_id' => $m->id], 'Given by staff, no charge');
        return 'renewed';
    }

    private function markDue(TenantCustomerMembership $m): string
    {
        $dueDate = $m->current_period_end->copy()->addDay();
        $m->update([
            'status'           => 'past_due',
            'next_retry_at'    => $dueDate->copy()->addDays(self::RETRY_DAYS[count(self::RETRY_DAYS) - 1])->startOfDay(),
            'last_charge_error'=> 'Pays at the desk',
        ]);
        ClassCreditLedger::record($m->tenant_id, $m->customer_id, 0, 'due', ['membership_id' => $m->id], 'Renewal due at the desk');
        return 'due';
    }

    /**
     * Charge the saved card. Success: a paid register sale whose renewal
     * line rolls the membership forward (ClassPassService). Failure: past_due
     * and the next retry date. The charge is made before the sale; if the
     * sale then can't be written, the charge is refunded so nobody pays for
     * a renewal that didn't happen.
     */
    private function charge(TenantCustomerMembership $m, Carbon $now): string
    {
        $tenant   = Tenant::find($m->tenant_id);
        $customer = TenantCustomer::where('tenant_id', $m->tenant_id)->find($m->customer_id);
        $amount   = (int) ($m->renewal_price_cents ?? $m->product?->price_cents ?? 0);

        if ($amount <= 0) {
            return $this->rollFree($m, $m->current_period_end->copy()->addDay()->toDateString());
        }

        $direct = app()->makeWith(DirectPaymentsService::class, ['tenant' => $tenant]);
        if (! $customer || ! $customer->stripe_customer_id || ! $m->stripe_payment_method_id || ! $direct->isEnabled()) {
            return $this->failed($m, $now, 'No usable saved card');
        }

        $attempt = (int) $m->failed_attempts + 1;
        $key     = 'intake-renew-' . $m->id . '-' . $m->current_period_end->format('Ymd') . '-' . $attempt;

        try {
            $pi = $direct->chargeSavedCard($customer->stripe_customer_id, $m->stripe_payment_method_id, $amount, $key, [
                'intake_membership_id' => $m->id,
                'intake_customer_id'   => $customer->id,
            ]);
        } catch (\Throwable $e) {
            return $this->failed($m, $now, $e->getMessage());
        }

        if (($pi->status ?? null) !== 'succeeded') {
            return $this->failed($m, $now, 'Card needs the customer to confirm (' . ($pi->status ?? 'unknown') . ')');
        }

        try {
            [$locationId, $userId] = $this->saleContext($m);
            app(SaleService::class)->createSale([
                'tenant_id'                => $m->tenant_id,
                'location_id'              => $locationId,
                'rang_up_by_user_id'       => $userId,
                'customer_id'              => $m->customer_id,
                'payment_status'           => 'paid',
                'payment_method'           => 'card',
                'payment_reference'        => 'Membership renewal',
                'stripe_payment_intent_id' => $pi->id,
                'card_brand'               => $m->card_brand,
                'card_last4'               => $m->card_last4,
                'paid_at'                  => now(),
                'notes'                    => 'Automatic membership renewal',
                'items'                    => [[
                    'type'             => 'class_pass',
                    'quantity'         => 1,
                    'unit_price_cents' => $amount,
                    'name_snapshot'    => ($m->product?->name ?? 'Membership') . ' · renewal',
                    'class_pass'       => ['kind' => 'membership', 'product_id' => $m->product_id, 'renew_membership_id' => $m->id],
                ]],
            ]);
        } catch (\Throwable $e) {
            Log::critical('memberships: renewal charged but the sale failed; refunding', ['membership_id' => $m->id, 'pi' => $pi->id, 'error' => $e->getMessage()]);
            report($e);
            try {
                $direct->refundPaymentIntent($pi->id, 'requested_by_customer');
            } catch (\Throwable $r) {
                Log::critical('memberships: refund after failed renewal sale ALSO failed', ['pi' => $pi->id, 'error' => $r->getMessage()]);
                report($r);
            }
            return $this->failed($m, $now, 'Charged, but the sale could not be recorded; the charge was refunded');
        }

        return 'charged';
    }

    private function failed(TenantCustomerMembership $m, Carbon $now, string $why): string
    {
        $n       = (int) $m->failed_attempts + 1;
        $dueDate = $m->current_period_end->copy()->addDay()->startOfDay();
        $next    = $n <= count(self::RETRY_DAYS) ? $dueDate->copy()->addDays(self::RETRY_DAYS[$n - 1]) : $now->copy();
        if ($next->lt($now) && $n <= count(self::RETRY_DAYS)) {
            $next = $now->copy()->addDay()->startOfDay();
        }

        $m->update([
            'status'            => 'past_due',
            'failed_attempts'   => $n,
            'next_retry_at'     => $next,
            'last_charge_error' => mb_substr($why, 0, 255),
        ]);
        ClassCreditLedger::record($m->tenant_id, $m->customer_id, 0, 'payment_failed', ['membership_id' => $m->id],
            "Renewal attempt {$n} failed: " . mb_substr($why, 0, 180));

        return 'failed';
    }

    /** Where and by whom a renewal sale is recorded: the original sale's, else the shop's first. */
    private function saleContext(TenantCustomerMembership $m): array
    {
        $orig = null;
        if ($m->sale_item_id) {
            $saleId = DB::table('tenant_sale_items')->where('id', $m->sale_item_id)->value('sale_id');
            $orig   = $saleId ? TenantSale::find($saleId) : null;
        }
        $locationId = $orig?->location_id
            ?? DB::table('tenant_locations')->where('tenant_id', $m->tenant_id)->where('is_active', true)->orderBy('created_at')->value('id');
        $userId = $orig?->rang_up_by_user_id
            ?? DB::table('tenant_users')->where('tenant_id', $m->tenant_id)->orderBy('created_at')->value('id');

        return [$locationId, $userId];
    }

    private function tenantLocalDates(Carbon $now): array
    {
        return Tenant::query()->pluck('timezone', 'id')
            ->map(fn ($tz) => $now->copy()->setTimezone($tz ?: config('app.timezone', 'UTC'))->toDateString())
            ->all();
    }
}

