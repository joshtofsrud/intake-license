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
        $appt->loadMissing(['items', 'addons', 'charges', 'tenant']);

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
            ->with(['items', 'addons'])
            ->orderBy('sort_order')
            ->get();

        $assetGroups = [];
        foreach ($assets as $a) {
            $lines = [];
            foreach ($a->items as $it) {
                $lines[] = ['name' => $it->item_name_snapshot, 'add' => false, 'cents' => (int) $it->price_cents];
            }
            foreach ($a->addons as $ad) {
                $lines[] = ['name' => $ad->addon_name_snapshot, 'add' => true, 'cents' => (int) $ad->price_cents];
            }
            $assetGroups[] = [
                'name'     => $a->asset_name_snapshot ?: 'Asset',
                'lines'    => $lines,
                'subtotal' => (int) $a->subtotal_cents,
            ];
        }

        // ── loose (unpinned) items/addons + ad-hoc charges ──
        $loose = [];
        foreach ($appt->items->whereNull('appointment_asset_id') as $it) {
            $loose[] = ['name' => $it->item_name_snapshot, 'add' => false, 'cents' => (int) $it->price_cents];
        }
        foreach ($appt->addons->whereNull('appointment_asset_id') as $ad) {
            $loose[] = ['name' => $ad->addon_name_snapshot, 'add' => true, 'cents' => (int) $ad->price_cents];
        }
        foreach ($appt->charges as $ch) {
            $loose[] = ['name' => $ch->description, 'add' => false, 'cents' => (int) $ch->amount_cents];
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
