<?php

namespace App\Console\Commands;

use App\Models\Tenant;
use App\Models\Tenant\TenantSale;
use App\Services\Tenant\DirectPaymentsService;
use Illuminate\Console\Command;

/**
 * MARKER-PATCH-172E — One-shot reconcile for sales paid via Stripe Checkout
 * Sessions where the session_id linkage to the Intake sale row didn\'t persist
 * (root cause: SaleService didn\'t accept checkout_session_id before this patch).
 *
 * Usage:
 *   php artisan reconcile:checkout-session <tenant-subdomain> <stripe-session-id> <intake-sale-id>
 *
 * Example:
 *   php artisan reconcile:checkout-session grndctrl cs_live_a1aT27Chz... a1xxxxxx-...
 */
class ReconcileStripeCheckoutSession extends Command
{
    protected $signature = 'reconcile:checkout-session {tenant} {session_id} {sale_id} {--dry-run}';
    protected $description = 'Reconcile a paid Stripe Checkout Session with an Intake draft sale row.';

    public function handle(): int
    {
        $tenant = Tenant::where('subdomain', $this->argument('tenant'))->first();
        if (! $tenant) {
            $this->error("Tenant not found: " . $this->argument('tenant'));
            return 1;
        }

        $sale = TenantSale::where('tenant_id', $tenant->id)
            ->where('id', $this->argument('sale_id'))
            ->first();
        if (! $sale) {
            $this->error("Sale not found in tenant: " . $this->argument('sale_id'));
            return 1;
        }

        $this->info("Sale: {$sale->sale_number} | current payment_status: {$sale->payment_status} | total_cents: {$sale->total_cents}");

        $sessionId = $this->argument('session_id');
        $direct = new DirectPaymentsService($tenant);
        try {
            $session = $direct->retrieveCheckoutSession($sessionId);
        } catch (\Throwable $e) {
            $this->error("Could not retrieve Stripe session: " . $e->getMessage());
            return 1;
        }

        if ($session->payment_status !== 'paid') {
            $this->error("Session is not paid yet (payment_status={$session->payment_status}). Cannot reconcile.");
            return 1;
        }

        if ((int) $session->amount_total !== (int) $sale->total_cents) {
            $this->warn("AMOUNT MISMATCH: Stripe session is \${$session->amount_total} cents, Intake sale is \${$sale->total_cents} cents.");
            if (! $this->confirm('Proceed anyway?', false)) {
                return 1;
            }
        }

        // Extract card metadata
        $piId = is_string($session->payment_intent) ? $session->payment_intent : ($session->payment_intent?->id ?? null);
        $brand = $last4 = $funding = $chargeId = null;
        if ($piId) {
            try {
                $pi = $direct->retrievePaymentIntent($piId);
                $details = $direct->extractCardDetails($pi);
                $brand    = $details['brand'];
                $last4    = $details['last4'];
                $funding  = $details['funding'];
                $chargeId = $details['charge_id'];
            } catch (\Throwable $e) {
                $this->warn("Card extraction failed: " . $e->getMessage() . " (continuing without card details)");
            }
        }

        $this->info("Will update sale:");
        $this->line("  status                    -> completed");
        $this->line("  payment_status            -> paid");
        $this->line("  paid_at                   -> now");
        $this->line("  checkout_session_id       -> {$session->id}");
        $this->line("  stripe_payment_intent_id  -> " . ($piId ?? '(null)'));
        $this->line("  stripe_charge_id          -> " . ($chargeId ?? '(null)'));
        $this->line("  card_brand                -> " . ($brand ?? '(null)'));
        $this->line("  card_last4                -> " . ($last4 ?? '(null)'));
        $this->line("  card_funding              -> " . ($funding ?? '(null)'));
        $this->line("  payment_reference         -> " . ($brand && $last4 ? "{$brand} ····{$last4}" : "Paid via link"));

        if ($this->option('dry-run')) {
            $this->info("--dry-run: no changes written.");
            return 0;
        }

        if (! $this->confirm('Apply these changes?', true)) {
            $this->info("Aborted.");
            return 0;
        }

        $sale->status                    = 'completed';
        $sale->payment_status            = 'paid';
        $sale->paid_at                   = now();
        $sale->checkout_session_id       = $session->id;
        $sale->stripe_payment_intent_id  = $piId;
        $sale->stripe_charge_id          = $chargeId;
        $sale->card_brand                = $brand;
        $sale->card_last4                = $last4;
        $sale->card_funding              = $funding;
        $sale->payment_reference         = ($brand && $last4) ? "{$brand} ····{$last4}" : 'Paid via link';
        $sale->save();

        $this->info("Sale {$sale->sale_number} reconciled.");
        return 0;
    }
}
