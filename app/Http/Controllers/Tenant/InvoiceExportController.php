<?php

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Models\Tenant\TenantAppointment;
use App\Services\EmailService;
use App\Services\InvoiceBuilderService;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;

/**
 * MARKER-PATCH-204 — work-order invoice export (Print style, dompdf).
 *
 * Endpoints accept the composer's selections (style / terms / note) and
 * persist the customer-facing ones (note + terms) before rendering, so the
 * stored invoice and the sent PDF always match.
 */
class InvoiceExportController extends Controller
{
    public function __construct(private InvoiceBuilderService $builder) {}

    private function find(string $id): TenantAppointment
    {
        return TenantAppointment::where('tenant_id', tenant()->id)
            ->where('id', $id)
            ->firstOrFail();
    }

    /** Persist only the customer-facing fields the owner set in the composer. */
    private function persist(TenantAppointment $appt, Request $r): void
    {
        $data = [];
        if ($r->has('note')) {
            $data['invoice_note'] = $r->input('note') ?: null;
        }
        if ($r->filled('terms') && in_array($r->input('terms'), ['due_now', 'on_completion'], true)) {
            $data['invoice_terms'] = $r->input('terms');
        }
        if ($data) $appt->update($data);
    }

    private function pdf(TenantAppointment $appt, Request $r)
    {
        $this->persist($appt, $r);
        $data = $this->builder->forAppointment($appt, [
            'style' => 'print',
            'terms' => $r->input('terms'),
            'note'  => $r->input('note', $appt->invoice_note),
        ]);
        return Pdf::loadView('tenant.invoices.pdf-print', $data)->setPaper('letter');
    }

    /** Inline preview (opens in a new tab). */
    public function preview(Request $r, string $id)
    {
        $appt = $this->find($id);
        return $this->pdf($appt, $r)->stream('invoice-' . $appt->ra_number . '.pdf');
    }

    /** Force download. */
    public function download(Request $r, string $id)
    {
        $appt = $this->find($id);
        return $this->pdf($appt, $r)->download('invoice-' . $appt->ra_number . '.pdf');
    }

    /** Email the PDF to the customer through the tenant's Postmark stream. */
    public function email(Request $r, string $id)
    {
        $appt = $this->find($id);
        $to   = $r->input('email', $appt->customer_email);
        if (!$to) {
            return back()->with('error', 'No email on file for this customer.');
        }

        $pdf  = $this->pdf($appt, $r);
        $data = $this->builder->forAppointment($appt, [
            'terms' => $r->input('terms'),
            'note'  => $r->input('note', $appt->invoice_note),
        ]);
        $html = view('tenant.invoices.email-body', $data)->render();

        $ok = EmailService::forTenant($appt->tenant)->sendRenderedWithPdf(
            'appointment_invoice',
            $to,
            'Your invoice from ' . $appt->tenant->name . ' — ' . $appt->ra_number,
            $html,
            $pdf->output(),
            'invoice-' . $appt->ra_number . '.pdf'
        );

        return back()->with(
            $ok ? 'success' : 'error',
            $ok ? 'Invoice emailed to ' . $to . '.'
                : 'Could not send — the address may be suppressed.'
        );
    }
}
