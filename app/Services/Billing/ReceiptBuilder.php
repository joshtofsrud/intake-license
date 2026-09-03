<?php

namespace App\Services\Billing;

use App\Models\Tenant\TenantEmailLedgerEntry;
use App\Models\TenantChargeRun;

/**
 * MARKER-BILLING-RECEIPT — the contents of one charge run's receipt.
 *
 * Everything comes from the ledger rows the run claimed, grouped by what they
 * were and the rate they were charged at. Rows at different rates become
 * different lines rather than one blended average, because a receipt that does
 * not add up is worse than no receipt.
 */
class ReceiptBuilder
{
    public function for(TenantChargeRun $run): array
    {
        $rows = TenantEmailLedgerEntry::where('charge_run_id', $run->id)
            ->selectRaw("channel, kind, is_free, rate, COUNT(*) n, SUM(segments) segs, SUM(rate * segments) spend,
                         MIN(created_at) first_at, MAX(created_at) last_at")
            ->groupBy('channel', 'kind', 'is_free', 'rate')
            ->orderBy('channel')->orderByDesc('spend')
            ->get();

        $lines = [];
        foreach ($rows as $r) {
            $isCampaign = $r->kind === 'campaign';

            $lines[] = [
                'description' => match (true) {
                    (bool) $r->is_free      => 'Included with plan',
                    $r->channel === 'sms'   => 'Text messages',
                    $isCampaign             => 'Campaign email',
                    default                 => 'Receipts, reminders and confirmations',
                },
                'qty'   => $r->channel === 'sms' ? (int) $r->segs : (int) $r->n,
                'unit'  => $r->channel === 'sms' ? 'segments' : 'emails',
                'rate'  => (float) $r->rate,
                'cents' => (int) round(((float) $r->spend) * 100),
            ];
        }

        $period = [
            'from' => $rows->min('first_at'),
            'to'   => $rows->max('last_at'),
        ];

        return [
            'run'      => $run,
            'tenant'   => $run->tenant,
            'lines'    => $lines,
            'period'   => $period,
            'subtotal' => $run->subtotalCents(),
            // MARKER-BILLING-TAX-ROOM — a tax row appears only when tax was
            // actually charged. A $0.00 tax line on a filed document is a
            // claim, and while none is collected it would be a false one.
            'tax'          => (int) $run->tax_cents,
            'tax_rate'     => $run->tax_rate,
            'tax_where'    => $run->tax_jurisdiction,
            'total'        => $run->amount_cents,
            'number'   => 'INT-' . strtoupper(substr(str_replace('-', '', $run->id), 0, 10)),
        ];
    }
}
