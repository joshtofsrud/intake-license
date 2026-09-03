<?php

namespace App\Services\Billing;

use App\Models\BillingSettings;
use App\Models\PlatformSettings;
use App\Models\Tenant;
use App\Models\Tenant\TenantEmailLedgerEntry;
use App\Models\TenantChargeRun;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Stripe\StripeClient;

/**
 * MARKER-BILLING-CHARGE — settle a shop's balance against its saved card.
 *
 * The whole design exists to make double-charging impossible:
 *
 *   claim()   stamps charge_run_id onto unclaimed ledger rows inside a
 *             transaction. A row can carry only one run, so two overlapping
 *             attempts cannot cover the same emails.
 *   charge()  sends Stripe an idempotency key derived from the run. A retry
 *             after a timeout returns the ORIGINAL PaymentIntent rather than
 *             creating a second one.
 *   No path creates a second run for rows that already have one.
 *
 * Nothing here charges unless BOTH the platform master switch and the tenant's
 * own flag are on. Off is the default and the safe state.
 */
class ChargeService
{
    /** Both switches, plus a card, or nothing happens. */
    public function canCharge(Tenant $tenant): bool
    {
        if (! (bool) (PlatformSettings::current()->charging_enabled ?? false)) return false;
        if (! (bool) $tenant->charging_enabled)                                return false;
        if (! $tenant->stripe_payment_method_id)                               return false;
        return (bool) BillingSettings::current()->activeSecretKey();
    }

    public function threshold(Tenant $tenant): int
    {
        return (int) ($tenant->charge_threshold_cents
            ?? PlatformSettings::current()->charge_threshold_default_cents
            ?? 2500);
    }

    /** Metered, settled, and not yet claimed by a run. */
    public function unbilledCents(Tenant $tenant): int
    {
        $spend = TenantEmailLedgerEntry::where('tenant_id', $tenant->id)
            ->where('status', TenantEmailLedgerEntry::STATUS_SENT)
            ->whereNull('charge_run_id')
            ->selectRaw('SUM(rate * segments) spend')
            ->value('spend');

        return (int) round(((float) $spend) * 100);
    }

    /**
     * Called after metering. Returns a run only when the balance has crossed
     * the threshold AND charging is on for this shop.
     */
    public function maybeCharge(Tenant $tenant): ?TenantChargeRun
    {
        if (! $this->canCharge($tenant)) return null;
        if ($this->unbilledCents($tenant) < $this->threshold($tenant)) return null;

        $run = $this->claim($tenant);
        if (! $run) return null;

        return $this->charge($run);
    }

    /**
     * Claim every unbilled row for a new run, in one transaction. The UPDATE
     * only touches rows with charge_run_id IS NULL, so a concurrent claim
     * cannot take the same rows.
     */
    public function claim(Tenant $tenant): ?TenantChargeRun
    {
        return DB::transaction(function () use ($tenant) {
            $runId = (string) Str::uuid();

            $claimed = TenantEmailLedgerEntry::where('tenant_id', $tenant->id)
                ->where('status', TenantEmailLedgerEntry::STATUS_SENT)
                ->whereNull('charge_run_id')
                ->update(['charge_run_id' => $runId]);

            if ($claimed === 0) {
                return null;
            }

            $spend = TenantEmailLedgerEntry::where('charge_run_id', $runId)
                ->selectRaw('SUM(rate * segments) spend')->value('spend');

            $cents = (int) round(((float) $spend) * 100);

            if ($cents <= 0) {
                // Free rows only: settle them without inventing a $0 charge.
                TenantEmailLedgerEntry::where('charge_run_id', $runId)
                    ->update(['charge_run_id' => null]);
                return null;
            }

            return TenantChargeRun::create([
                'id'              => $runId,
                'tenant_id'       => $tenant->id,
                'status'          => TenantChargeRun::PENDING,
                'amount_cents'    => $cents,
                'message_count'   => $claimed,
                'idempotency_key' => 'run_' . $runId,
            ]);
        });
    }

    /** Charge the card. Safe to call again on the same run. */
    public function charge(TenantChargeRun $run): TenantChargeRun
    {
        $tenant = $run->tenant;

        if (! $this->canCharge($tenant)) {
            return $run;
        }
        if (in_array($run->status, [TenantChargeRun::CHARGED, TenantChargeRun::WRITTEN_OFF, TenantChargeRun::REFUNDED], true)) {
            return $run;
        }

        $run->forceFill([
            'status'   => TenantChargeRun::CHARGING,
            'attempts' => $run->attempts + 1,
        ])->save();

        try {
            $intent = $this->client()->paymentIntents->create([
                'amount'         => $run->amount_cents,
                'currency'       => 'usd',
                'customer'       => $tenant->stripe_customer_id,
                'payment_method' => $tenant->stripe_payment_method_id,
                'off_session'    => true,   // nobody is at the screen
                'confirm'        => true,
                'description'    => 'Intake usage — ' . $tenant->name,
                'metadata'       => ['tenant_id' => $tenant->id, 'charge_run_id' => $run->id],
            ], ['idempotency_key' => $run->idempotency_key]);

            if ($intent->status === 'succeeded') {
                $run->forceFill([
                    'status'                   => TenantChargeRun::CHARGED,
                    'stripe_payment_intent_id' => $intent->id,
                    'charged_at'               => now(),
                    'failure_code'             => null,
                    'failure_message'          => null,
                    'next_attempt_at'          => null,
                ])->save();

                $this->resumeCampaigns($tenant);

                // MARKER-BILLING-NOTICES — receipt, and the open notice is answered.
                $notices = app(\App\Services\Billing\BillingNoticeService::class);
                $notices->notify($tenant, 'charged', [
                    '{amount}'   => '$' . number_format($run->amount_cents / 100, 2),
                    '{messages}' => number_format($run->message_count),
                ], $run->id);
                $notices->resolve($tenant, 'charged');

                logger()->info('MARKER-BILLING-CHARGE charged', [
                    'tenant' => $tenant->id, 'run' => $run->id, 'cents' => $run->amount_cents,
                ]);
            } else {
                $this->markFailed($run, $intent->status, 'Stripe returned status ' . $intent->status);
            }
        } catch (\Stripe\Exception\CardException $e) {
            $this->markFailed($run, $e->getDeclineCode() ?: $e->getStripeCode(), $e->getMessage());
        } catch (\Throwable $e) {
            // A timeout may mean the charge SUCCEEDED. Leave the run charging
            // and let reconcile() ask Stripe rather than retrying blind.
            $run->forceFill([
                'failure_message' => $e->getMessage(),
                'next_attempt_at' => now()->addMinutes(15),
            ])->save();

            logger()->error('MARKER-BILLING-CHARGE unresolved', [
                'run' => $run->id, 'error' => $e->getMessage(),
            ]);
        }

        return $run->refresh();
    }

    /**
     * Ask Stripe what became of a run left 'charging'. The idempotency key
     * means the original intent is returned rather than a new charge made.
     */
    public function reconcile(TenantChargeRun $run): TenantChargeRun
    {
        if ($run->status !== TenantChargeRun::CHARGING) return $run;

        try {
            $found = $this->client()->paymentIntents->search([
                'query' => "metadata['charge_run_id']:'" . $run->id . "'",
                'limit' => 1,
            ]);

            $intent = $found->data[0] ?? null;
            if (! $intent) {
                $run->forceFill(['status' => TenantChargeRun::PENDING])->save();
                return $run->refresh();
            }

            if ($intent->status === 'succeeded') {
                $run->forceFill([
                    'status' => TenantChargeRun::CHARGED,
                    'stripe_payment_intent_id' => $intent->id,
                    'charged_at' => now(),
                ])->save();
                $this->resumeCampaigns($run->tenant);
            } else {
                $this->markFailed($run, $intent->status, 'Reconciled: ' . $intent->status);
            }
        } catch (\Throwable $e) {
            logger()->error('MARKER-BILLING-CHARGE reconcile failed', ['run' => $run->id, 'error' => $e->getMessage()]);
        }

        return $run->refresh();
    }

    /** Money back, and the rows stay settled — they were paid for. */
    public function refund(TenantChargeRun $run, string $reason, ?string $by = null): bool
    {
        if ($run->status !== TenantChargeRun::CHARGED || ! $run->stripe_payment_intent_id) {
            return false;
        }

        try {
            $this->client()->refunds->create([
                'payment_intent' => $run->stripe_payment_intent_id,
                'metadata'       => ['charge_run_id' => $run->id, 'reason' => $reason],
            ]);
        } catch (\Throwable $e) {
            logger()->error('MARKER-BILLING-CHARGE refund failed', ['run' => $run->id, 'error' => $e->getMessage()]);
            return false;
        }

        $run->forceFill([
            'status'            => TenantChargeRun::REFUNDED,
            'refunded_cents'    => $run->amount_cents,
            'resolution_reason' => $reason,
            'resolved_by'       => $by,
            'resolved_at'       => now(),
        ])->save();

        return true;
    }

    /**
     * No money moves. The rows keep their run, so they are settled and can
     * never be charged again, and the reason is recorded for the statement.
     */
    public function writeOff(TenantChargeRun $run, string $reason, ?string $by = null): bool
    {
        if (in_array($run->status, [TenantChargeRun::CHARGED, TenantChargeRun::REFUNDED], true)) {
            return false;   // already settled with money; refund instead
        }

        $run->forceFill([
            'status'            => TenantChargeRun::WRITTEN_OFF,
            'resolution_reason' => $reason,
            'resolved_by'       => $by,
            'resolved_at'       => now(),
            'next_attempt_at'   => null,
        ])->save();

        $this->resumeCampaigns($run->tenant);

        app(\App\Services\Billing\BillingNoticeService::class)  // MARKER-BILLING-NOTICES
            ->resolve($run->tenant, 'written_off');

        logger()->info('MARKER-BILLING-CHARGE written off', [
            'run' => $run->id, 'cents' => $run->amount_cents, 'by' => $by, 'reason' => $reason,
        ]);

        return true;
    }

    // ---- failure handling -------------------------------------------

    private function markFailed(TenantChargeRun $run, ?string $code, string $message): void
    {
        $attempts = $run->attempts;
        // 3 tries over about a week, then stop and tell a human.
        $next = match (true) {
            $attempts <= 1 => now()->addDays(3),
            $attempts == 2 => now()->addDays(4),
            default        => null,
        };

        $run->forceFill([
            'status'          => TenantChargeRun::FAILED,
            'failure_code'    => $code,
            'failure_message' => $message,
            'next_attempt_at' => $next,
        ])->save();

        $this->pauseCampaigns($run->tenant);

        // MARKER-BILLING-NOTICES
        app(\App\Services\Billing\BillingNoticeService::class)->notify($run->tenant, 'charge_failed', [
            '{amount}' => '$' . number_format($run->amount_cents / 100, 2),
        ], $run->id);

        logger()->warning('MARKER-BILLING-CHARGE failed', [
            'tenant' => $run->tenant_id, 'run' => $run->id, 'code' => $code, 'attempts' => $attempts,
        ]);
    }

    /** Campaigns wait; receipts, reminders and confirmations never do. */
    private function pauseCampaigns(Tenant $tenant): void
    {
        if (! $tenant->campaigns_paused_at) {
            $tenant->forceFill(['campaigns_paused_at' => now()])->save();
        }
    }

    private function resumeCampaigns(Tenant $tenant): void
    {
        $stillFailing = TenantChargeRun::where('tenant_id', $tenant->id)
            ->where('status', TenantChargeRun::FAILED)->exists();

        if (! $stillFailing && $tenant->campaigns_paused_at) {
            $tenant->forceFill(['campaigns_paused_at' => null])->save();
        }
    }

    private function client(): StripeClient
    {
        $key = BillingSettings::current()->activeSecretKey();
        if (! $key) {
            throw new \RuntimeException('Stripe secret key not configured.');
        }
        return new StripeClient(['api_key' => $key, 'stripe_version' => '2024-06-20']);
    }
}
