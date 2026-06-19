<?php

namespace App\Services;

use App\Models\Tenant\TenantAppointment;
use App\Models\Tenant\TenantAppointmentAsset;

/**
 * MARKER-PATCH-204 — single source of truth for invoice data.
 *
 * Assembles everything the PDF view AND the email body need from an
 * appointment, multi-asset aware: line items roll up under each asset
 * (services + addons), loose items / addons / charges form their own group,
 * and totals come from the appointment's stored money columns.
 */
class InvoiceBuilderService
{
    /**
     * @param array $opts ['style' => 'print', 'terms' => 'due_now'|'on_completion', 'note' => string|null]
     */
    public function forAppointment(TenantAppointment $appt, array $opts = []): array
    {
        $tenant = $appt->tenant;
        $appt->loadMissing(['items', 'addons', 'parts', 'charges', 'tenant']);

        // terms: 'paid' always wins if the work order is paid, so the doc
        // can never claim a balance on a paid job.
        $terms = $appt->isPaid()
            ? 'paid'
            : ($opts['terms'] ?? $appt->invoice_terms ?? 'on_completion');
        if (!in_array($terms, ['due_now', 'on_completion', 'paid'], true)) {
            $terms = 'on_completion';
        }

        $note = $opts['note'] ?? $appt->invoice_note;

        // ── assets: each carries its own items + addons + stored subtotal ──
        $assets = TenantAppointmentAsset::where('tenant_id', $tenant->id)
            ->where('appointment_id', $appt->id)
            ->with(['items', 'addons', 'parts'])
            ->orderBy('sort_order')
            ->get();

        $assetGroups = [];
        foreach ($assets as $a) {
            $lines = [];
            foreach ($a->items as $it) {
                $lines[] = [
                    'name'  => $it->item_name_snapshot,
                    'kind'  => 'service',
                    'add'   => false,
                    'qty'   => null,
                    'cents' => (int) $it->effectivePriceCents(),
                ];
            }
            foreach ($a->addons as $ad) {
                $lines[] = [
                    'name'  => $ad->addon_name_snapshot,
                    'kind'  => 'addon',
                    'add'   => true,
                    'qty'   => null,
                    'cents' => (int) $ad->effectivePriceCents(),
                ];
            }
            // MARKER-PATCH-347 — parts (inventory parts AND custom one-offs, which
            // are stored as part rows with inventory_item_id = null) were never
            // projected, so they never showed on any document.
            foreach ($a->parts as $pt) {
                $lines[] = [
                    'name'   => $pt->item_name_snapshot,
                    'kind'   => 'part',
                    'add'    => false,
                    'qty'    => (int) $pt->quantity,
                    'unit'   => (int) $pt->effectiveUnitPriceCents(),
                    'custom' => ! $pt->inventory_item_id,
                    'sku'    => $pt->item_sku_snapshot,
                    'cents'  => (int) $pt->lineTotalCents(),
                ];
            }
            $assetGroups[] = [
                'id'       => $a->id, // MARKER-PATCH-333
                'name'     => $a->asset_name_snapshot ?: 'Asset',
                'lines'    => $lines,
                // MARKER-PATCH-347 — derive from the rendered lines so the asset
                // subtotal always equals the sum of what's actually printed.
                'subtotal' => array_sum(array_column($lines, 'cents')),
            ];
        }

        // ── loose (unpinned) items / add-ons / parts ──
        // MARKER-PATCH-347 — loose parts now project. Legacy ad-hoc `charges`
        // are intentionally excluded: they are not part of the billed total
        // (recalcAppointmentTotals sums items + add-ons + parts only), so
        // listing them would make the printed lines disagree with the totals.
        // The custom one-off path now writes part rows, not charge rows.
        $loose = [];
        foreach ($appt->items->whereNull('appointment_asset_id') as $it) {
            $loose[] = [
                'name'  => $it->item_name_snapshot,
                'kind'  => 'service',
                'add'   => false,
                'qty'   => null,
                'cents' => (int) $it->effectivePriceCents(),
            ];
        }
        foreach ($appt->addons->whereNull('appointment_asset_id') as $ad) {
            $loose[] = [
                'name'  => $ad->addon_name_snapshot,
                'kind'  => 'addon',
                'add'   => true,
                'qty'   => null,
                'cents' => (int) $ad->effectivePriceCents(),
            ];
        }
        foreach ($appt->parts->whereNull('appointment_asset_id') as $pt) {
            $loose[] = [
                'name'   => $pt->item_name_snapshot,
                'kind'   => 'part',
                'add'    => false,
                'qty'    => (int) $pt->quantity,
                'unit'   => (int) $pt->effectiveUnitPriceCents(),
                'custom' => ! $pt->inventory_item_id,
                'sku'    => $pt->item_sku_snapshot,
                'cents'  => (int) $pt->lineTotalCents(),
            ];
        }

        $subtotal = (int) $appt->subtotal_cents;
        $tax      = (int) $appt->tax_cents;
        $total    = (int) $appt->total_cents;
        $paid     = (int) $appt->paid_cents;
        $balance  = max(0, $total - $paid);

        return [
            'style'    => $opts['style'] ?? 'print',
            'terms'    => $terms,
            'note'     => $note,
            'tenant'   => $tenant,
            'appt'     => $appt,
            'number'   => $appt->ra_number,
            'customer' => [
                'name'  => $appt->customerName(),
                'email' => $appt->customer_email,
                'phone' => $appt->customer_phone,
            ],
            'assets'   => $assetGroups,
            'loose'    => $loose,
            'subtotal' => $subtotal,
            'tax'      => $tax,
            'total'    => $total,
            'paid'     => $paid,
            'balance'  => $balance,
        ];
    }
}
