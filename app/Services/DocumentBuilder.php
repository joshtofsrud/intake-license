<?php

namespace App\Services;

use App\Models\Tenant\TenantAppointment;
use App\Models\Tenant\TenantSale;
use App\Support\DocumentOptions;

/**
 * One data shape for every printed/emailed document, for any format. Reuses
 * InvoiceBuilderService for appointment asset-grouping, then layers on the
 * composer's print-time choices: asset filter, customer/staff notes
 * (is_customer_visible), and the payment ledger with a running balance.
 *
 * MARKER-PATCH-333
 */
class DocumentBuilder
{
    public function __construct(private InvoiceBuilderService $invoice)
    {
    }

    /** Rich document data for a work order. */
    public function forAppointment(TenantAppointment $appt, DocumentOptions $opt): array
    {
        $appt->loadMissing(['notes', 'payments']);
        $base = $this->invoice->forAppointment($appt, ['style' => 'print']);

        // asset filter (empty = all)
        if (!empty($opt->assetIds)) {
            $want = array_map('strval', $opt->assetIds);
            $base['assets'] = array_values(array_filter(
                $base['assets'],
                fn ($g) => in_array((string) ($g['id'] ?? ''), $want, true)
            ));
        }

        // notes filtered by visibility + the composer toggles
        $notes = [];
        foreach ($appt->notes as $n) {
            $isCustomer = (bool) $n->is_customer_visible;
            if ($isCustomer && !$opt->includeCustomerNotes) {
                continue;
            }
            if (!$isCustomer && !$opt->includeStaffNotes) {
                continue;
            }
            $notes[] = [
                'content'  => $n->note_content,
                'customer' => $isCustomer,
                'type'     => $n->note_type,
            ];
        }

        return array_merge($base, [
            'source'  => 'appointment',
            'options' => $opt,
            'notes'   => $notes,
            'ledger'  => $opt->includeLedger ? $this->ledger($appt->payments, (int) $base['total']) : [],
            'prices'  => $opt->includePrices,
        ]);
    }

    /** Flat document data for a POS sale (no asset grouping). */
    public function forSale(TenantSale $sale, DocumentOptions $opt): array
    {
        $sale->loadMissing(['items', 'payments', 'customer']);

        $lines = [];
        foreach ($sale->items as $it) {
            $lines[] = [
                'name'  => $it->name_snapshot,
                'qty'   => (float) $it->quantity,
                'cents' => (int) $it->line_total_cents,
            ];
        }

        $total = (int) $sale->total_cents;
        $paid  = (int) $sale->payments->sum('amount_cents');
        $cust  = $sale->customer;

        return [
            'source'    => 'sale',
            'options'   => $opt,
            'tenant'    => tenant(),
            'sale'      => $sale,
            'number'    => $sale->sale_number,
            'customer'  => [
                'name'  => $cust ? trim(($cust->first_name ?? '') . ' ' . ($cust->last_name ?? '')) : null,
                'email' => $cust->email ?? null,
                'phone' => $cust->phone ?? null,
            ],
            'lines'     => $lines,
            'subtotal'  => (int) $sale->subtotal_cents,
            'discount'  => (int) $sale->discount_cents,
            'tax'       => (int) $sale->tax_cents,
            'surcharge' => (int) $sale->surcharge_cents,
            'tip'       => (int) $sale->tip_cents,
            'total'     => $total,
            'paid'      => $paid,
            'balance'   => max(0, $total - $paid),
            'notes'     => [],
            'ledger'    => $opt->includeLedger ? $this->ledger($sale->payments, $total) : [],
            'prices'    => $opt->includePrices,
        ];
    }

    /** Every payment line with a running balance. Refunds carry negative cents. */
    private function ledger($payments, int $total): array
    {
        $rows = [];
        $running = 0;
        foreach ($payments as $p) {
            $running += (int) $p->amount_cents;
            $rows[] = [
                'label'   => method_exists($p, 'methodLabel') ? $p->methodLabel() : ($p->method ?? 'Payment'),
                'kind'    => $p->kind ?? null,
                'refund'  => method_exists($p, 'isRefund') ? $p->isRefund() : ((int) $p->amount_cents < 0),
                'cents'   => (int) $p->amount_cents,
                'balance' => $total - $running,
                'at'      => isset($p->recorded_at) && $p->recorded_at ? tlocal($p->recorded_at, 'M j, g:i A') : null,
            ];
        }
        return $rows;
    }
}
