<?php
// MARKER-LEDGER-SERVICE — turns a collected platform invoice into a ledger
// entry when the tenant traces to a deal-registered agency.

namespace App\Services;

use App\Models\SalesCommissionEntry;
use App\Models\SalesProspect;
use App\Models\Tenant;
use Illuminate\Support\Facades\Log;

class CommissionAccrualService
{
    public function recordInvoicePaid(Tenant $tenant, object $invoice): ?SalesCommissionEntry
    {
        $invoiceId = $invoice->id ?? null;
        $amount    = (int) ($invoice->amount_paid ?? 0);
        if (! $invoiceId || $amount <= 0) {
            return null;
        }

        // Attribution: prospect -> agency. No attributed prospect, no accrual.
        $prospect = SalesProspect::query()
            ->where('tenant_id', $tenant->id)
            ->whereNotNull('agency_id')
            ->with('agency')
            ->first();

        $agency = $prospect?->agency;
        if (! $agency) {
            return null;
        }

        // deal_registration OFF = commissions decided case-by-case, not auto.
        if (! $agency->deal_registration) {
            return null;
        }

        // Idempotency: one entry per Stripe invoice, ever.
        if (SalesCommissionEntry::where('stripe_invoice_id', $invoiceId)->exists()) {
            return null;
        }

        // Account age from tenants.created_at: < 12 months = year-1 rate.
        $ageMonths = (int) $tenant->created_at->diffInMonths(now());
        $basis     = $ageMonths < 12 ? 'year1' : 'residual';
        $rate      = $basis === 'year1'
            ? (float) $agency->commission_year1
            : (float) $agency->commission_residual;

        $entry = SalesCommissionEntry::create([
            'agency_id'              => $agency->id,
            'sales_rep_id'           => $prospect->sales_rep_id,
            'sales_prospect_id'      => $prospect->id,
            'tenant_id'              => $tenant->id,
            'stripe_invoice_id'      => $invoiceId,
            'amount_collected_cents' => $amount,
            'rate'                   => $rate,
            'commission_cents'       => (int) round($amount * $rate),
            'basis'                  => $basis,
            'collected_at'           => now(),
        ]);

        Log::info('[Commission] accrued', [
            'tenant'  => $tenant->subdomain,
            'agency'  => $agency->slug,
            'invoice' => $invoiceId,
            'basis'   => $basis,
            'cents'   => $entry->commission_cents,
        ]);

        return $entry;
    }
}
