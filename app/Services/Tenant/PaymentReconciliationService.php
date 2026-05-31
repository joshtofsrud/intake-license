<?php

namespace App\Services\Tenant;

use App\Models\Tenant;
use App\Models\Tenant\TenantSale;
use App\Models\Tenant\TenantSalePayment;

/**
 * MARKER-PATCH-196 — Stripe-vs-ledger reconciliation.
 *
 * Finds money that Stripe took but Intake never recorded: succeeded
 * PaymentIntents over a window that have NO matching ledger row
 * (tenant_sale_payments.external_reference). These are the "paid in Stripe,
 * unpaid in Intake" strandings that the webhook hardening (patch-193) prevents
 * going forward — this report catches anything that slipped through, e.g. a
 * payment with a null checkout_session_id and a PI we couldn't fall back to,
 * or events from before the fix.
 *
 * Read-only. The reconcile action lives in the controller and goes through
 * SalePaymentService::record() so a fix is a proper ledger write.
 */
class PaymentReconciliationService
{
    public function __construct(protected Tenant $tenant) {}

    /**
     * Return succeeded Stripe PaymentIntents in the window that have no matching
     * ledger row. Each row: [pi_id, amount_cents, created (unix), description,
     * candidate_sale] where candidate_sale (if any) is an unpaid/pending sale
     * that references this PI on the sale record but never got a ledger row.
     *
     * @param  int  $days  how far back to scan (default 30)
     * @return array{scanned:int, unmatched:array, error:?string}
     */
    public function unmatchedPayments(int $days = 30): array
    {
        $direct = new DirectPaymentsService($this->tenant);
        if (! $direct->isEnabled()) {
            return ['scanned' => 0, 'unmatched' => [], 'error' => 'Card payments are not enabled for this tenant.'];
        }

        $sinceTs = now()->subDays($days)->timestamp;

        try {
            $stripePis = $direct->listSucceededPaymentIntents($sinceTs);
        } catch (\Throwable $e) {
            return ['scanned' => 0, 'unmatched' => [], 'error' => 'Could not read payments from Stripe: ' . $e->getMessage()];
        }

        if (empty($stripePis)) {
            return ['scanned' => 0, 'unmatched' => [], 'error' => null];
        }

        // All PI references already recorded in the ledger for this tenant.
        $recordedRefs = TenantSalePayment::where('tenant_id', $this->tenant->id)
            ->whereNotNull('external_reference')
            ->pluck('external_reference')
            ->flip(); // value => key for O(1) isset()

        $unmatched = [];
        foreach ($stripePis as $pi) {
            if (isset($recordedRefs[$pi['id']])) {
                continue; // already in the ledger — reconciled
            }

            // Is there a sale that references this PI but has no ledger row?
            // (e.g. webhook set stripe_payment_intent_id but ledger write failed)
            $candidate = TenantSale::where('tenant_id', $this->tenant->id)
                ->where('stripe_payment_intent_id', $pi['id'])
                ->first();

            $unmatched[] = [
                'pi_id'         => $pi['id'],
                'amount_cents'  => $pi['amount_cents'],
                'created'       => $pi['created'],
                'description'   => $pi['description'],
                'candidate_sale' => $candidate ? [
                    'id'             => $candidate->id,
                    'sale_number'    => $candidate->sale_number,
                    'payment_status' => $candidate->payment_status,
                    'status'         => $candidate->status,
                    'total_cents'    => (int) $candidate->total_cents,
                    'customer'       => $candidate->customer
                        ? trim(($candidate->customer->first_name ?? '') . ' ' . ($candidate->customer->last_name ?? ''))
                        : null,
                ] : null,
            ];
        }

        return ['scanned' => count($stripePis), 'unmatched' => $unmatched, 'error' => null];
    }
}
