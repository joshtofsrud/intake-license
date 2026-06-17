<?php

namespace App\Services;

use App\Models\Tenant\TenantAppointment;
use App\Models\Tenant\TenantAppointmentAsset;
use App\Models\Tenant\TenantSale;
use App\Support\DocumentOptions;

/**
 * One data shape for every printed/emailed document. Produces an ordered
 * slips -> sections structure so a single template renders tag / receipt /
 * invoice by walking section types — it never branches on document type.
 *
 * A receipt/invoice is one slip; a tag is one slip per asset. Each slip is an
 * ordered list of typed sections. Header/footer/feed come from the print
 * identity (rendered by the shell), not from these sections.
 *
 * MARKER-PATCH-333 / MARKER-PATCH-335
 */
class DocumentBuilder
{
    public function __construct(private InvoiceBuilderService $invoice)
    {
    }

    public function forAppointment(TenantAppointment $appt, DocumentOptions $opt): array
    {
        $appt->loadMissing(['items', 'notes', 'payments', 'customer']);

        return $opt->type === 'tag'
            ? $this->tag($appt, $opt)
            : $this->appointmentReceipt($appt, $opt);
    }

    // ── work-order service tag: one slip per asset ───────────────────────────
    private function tag(TenantAppointment $appt, DocumentOptions $opt): array
    {
        $assets = TenantAppointmentAsset::where('tenant_id', $appt->tenant_id)
            ->where('appointment_id', $appt->id)
            ->orderBy('sort_order')
            ->get();

        if (!empty($opt->assetIds)) {
            $want = array_map('strval', $opt->assetIds);
            $assets = $assets->filter(fn ($a) => in_array((string) $a->id, $want, true))->values();
        }

        $job      = $appt->ra_number ?: ('#' . $appt->id);
        $name     = trim($appt->customerName());
        $apptDate = $appt->appointment_date ? $appt->appointment_date->format('D M j') : '';
        $apptTime = $appt->appointment_time ? \Carbon\Carbon::parse($appt->appointment_time)->format('g:ia') : '';
        $promised = $appt->promised_at ? tlocal_date($appt->promised_at, 'D M j') : null;
        $qrUrl    = $opt->includeQr ? route('tenant.appointments.show', $appt->id) : null;
        $notes    = $this->notes($appt, $opt);

        // one slip per asset; a job with no assets still prints a single slip
        $rows = $assets->isNotEmpty()
            ? $assets->map(fn ($a) => [
                'bike'     => trim(($a->asset_name_snapshot ?? '') . ($a->identifier_snapshot ? ' · ' . $a->identifier_snapshot : '')),
                'services' => $appt->items->where('appointment_asset_id', $a->id)->pluck('item_name_snapshot')->filter()->values()->all(),
            ])->all()
            : [['bike' => '', 'services' => $appt->items->pluck('item_name_snapshot')->filter()->values()->all()]];

        $slips = [];
        foreach ($rows as $r) {
            $meta = [['CUSTOMER', $name ?: '—']];
            if ($opt->showPhone && $appt->customer_phone) {
                $meta[] = ['PHONE', $appt->customer_phone];
            }
            $meta[] = ['APPT DATE', $apptDate . ($apptTime ? ', ' . $apptTime : '')];
            if ($promised) {
                $meta[] = ['PROMISED', $promised];
            }
            if ($opt->showBike && $r['bike']) {
                $meta[] = ['BIKE', $r['bike']];
            }

            $sections = [
                ['type' => 'doc_label', 'text' => 'SERVICE TAG'],
                ['type' => 'job', 'label' => 'JOB', 'value' => $job, 'qr' => $qrUrl],
                ['type' => 'meta', 'rows' => $meta],
            ];
            if ($opt->showServices && !empty($r['services'])) {
                $sections[] = ['type' => 'services', 'label' => 'SERVICE', 'items' => $r['services']];
            }
            if (!empty($notes)) {
                $sections[] = ['type' => 'notes', 'items' => $notes];
            }
            if ($opt->showStub) {
                $sections[] = ['type' => 'stub', 'job' => $job, 'name' => $name, 'promised' => $promised];
            }
            $slips[] = ['sections' => $sections];
        }

        return ['doc_type' => 'tag', 'number' => $job, 'slips' => $slips];
    }

    // ── appointment receipt / invoice: combined (or per-asset) ───────────────
    private function appointmentReceipt(TenantAppointment $appt, DocumentOptions $opt): array
    {
        $base = $this->invoice->forAppointment($appt, ['style' => 'print']);

        $groups = $base['assets'];
        if (!empty($opt->assetIds)) {
            $want   = array_map('strval', $opt->assetIds);
            $groups = array_values(array_filter($groups, fn ($g) => in_array((string) ($g['id'] ?? ''), $want, true)));
        }
        // loose items become their own group
        if (!empty($base['loose'])) {
            $groups[] = ['id' => null, 'name' => null, 'lines' => $base['loose'], 'subtotal' => null];
        }

        $label  = strtoupper($opt->type === 'invoice' ? 'invoice' : 'receipt');
        $notes  = $this->notes($appt, $opt);
        $ledger = $opt->includeLedger ? $this->ledger($appt->payments, (int) $base['total']) : [];

        $build = function (array $grps) use ($appt, $opt, $label, $notes, $ledger, $base) {
            $meta = [
                ['Job', $appt->ra_number ?: ('#' . $appt->id)],
                ['Date', $appt->appointment_date ? $appt->appointment_date->format('M j, Y') : ''],
            ];
            if (trim($appt->customerName())) {
                $meta[] = ['Customer', trim($appt->customerName())];
            }
            $sections = [
                ['type' => 'doc_label', 'text' => $label],
                ['type' => 'meta', 'rows' => $meta],
                ['type' => 'line_items', 'groups' => $grps, 'show_prices' => $opt->includePrices],
            ];
            if ($opt->includePrices) {
                $sections[] = ['type' => 'totals', 'rows' => $this->totalRows($base), 'grand' => ['TOTAL', (int) $base['total']]];
            }
            if (!empty($ledger)) {
                $sections[] = ['type' => 'ledger', 'rows' => $ledger];
            }
            if (!empty($notes)) {
                $sections[] = ['type' => 'notes', 'items' => $notes];
            }
            return ['sections' => $sections];
        };

        if ($opt->splitPerAsset && count($groups) > 1) {
            $slips = array_map(fn ($g) => $build([$g]), $groups);
        } else {
            $slips = [$build($groups)];
        }

        return ['doc_type' => $opt->type, 'number' => $appt->ra_number, 'slips' => $slips];
    }

    // ── POS sale: flat, single slip ──────────────────────────────────────────
    public function forSale(TenantSale $sale, DocumentOptions $opt): array
    {
        $sale->loadMissing(['items', 'payments', 'customer']);

        $lines = [];
        foreach ($sale->items as $it) {
            $lines[] = ['name' => $it->name_snapshot, 'qty' => (float) $it->quantity, 'cents' => (int) $it->line_total_cents];
        }

        $total = (int) $sale->total_cents;
        $cust  = $sale->customer;
        $meta  = [
            ['Sale', $sale->sale_number],
            ['Date', tlocal($sale->paid_at ?? $sale->created_at, 'M j, Y g:ia')],
        ];
        if ($cust) {
            $meta[] = ['Customer', trim(($cust->first_name ?? '') . ' ' . ($cust->last_name ?? ''))];
        }

        $sections = [
            ['type' => 'doc_label', 'text' => $sale->isRefunded() ? 'REFUND' : 'RECEIPT'],
            ['type' => 'meta', 'rows' => $meta],
            ['type' => 'line_items', 'groups' => [['id' => null, 'name' => null, 'lines' => $lines]], 'show_prices' => $opt->includePrices],
        ];
        if ($opt->includePrices) {
            $sections[] = ['type' => 'totals', 'rows' => $this->saleTotalRows($sale), 'grand' => ['TOTAL', $total]];
        }
        if ($opt->includeLedger || $sale->payments->count()) {
            $sections[] = ['type' => 'ledger', 'rows' => $this->ledger($sale->payments, $total)];
        }

        return ['doc_type' => 'receipt', 'number' => $sale->sale_number, 'slips' => [['sections' => $sections]]];
    }

    // ── helpers ──────────────────────────────────────────────────────────────
    private function notes(TenantAppointment $appt, DocumentOptions $opt): array
    {
        $out = [];
        foreach ($appt->notes as $n) {
            $isCustomer = (bool) $n->is_customer_visible;
            if ($isCustomer && !$opt->includeCustomerNotes) {
                continue;
            }
            if (!$isCustomer && !$opt->includeStaffNotes) {
                continue;
            }
            $out[] = ['content' => $n->note_content, 'customer' => $isCustomer];
        }
        return $out;
    }

    private function totalRows(array $base): array
    {
        $rows = [['Subtotal', (int) $base['subtotal'], false]];
        if ((int) $base['tax'] > 0) {
            $rows[] = ['Tax', (int) $base['tax'], false];
        }
        return $rows;
    }

    private function saleTotalRows(TenantSale $sale): array
    {
        $rows = [['Subtotal', (int) $sale->subtotal_cents, false]];
        if ((int) $sale->discount_cents > 0) {
            $rows[] = ['Discount', (int) $sale->discount_cents, true];
        }
        if ((int) $sale->tax_cents > 0) {
            $rows[] = ['Tax', (int) $sale->tax_cents, false];
        }
        if ((int) $sale->surcharge_cents > 0) {
            $rows[] = ['Surcharge', (int) $sale->surcharge_cents, false];
        }
        if ((int) $sale->tip_cents > 0) {
            $rows[] = ['Tip', (int) $sale->tip_cents, false];
        }
        return $rows;
    }

    private function ledger($payments, int $total): array
    {
        $rows = [];
        $running = 0;
        foreach ($payments as $p) {
            $running += (int) $p->amount_cents;
            $rows[] = [
                'label'   => method_exists($p, 'methodLabel') ? $p->methodLabel() : ($p->method ?? 'Payment'),
                'refund'  => method_exists($p, 'isRefund') ? $p->isRefund() : ((int) $p->amount_cents < 0),
                'cents'   => (int) $p->amount_cents,
                'balance' => $total - $running,
                'at'      => isset($p->recorded_at) && $p->recorded_at ? tlocal($p->recorded_at, 'M j, g:ia') : null,
            ];
        }
        return $rows;
    }
}
