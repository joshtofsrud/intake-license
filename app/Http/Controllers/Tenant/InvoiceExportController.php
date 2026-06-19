<?php

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Models\Tenant\TenantAppointment;
use App\Services\EmailService;
use App\Services\InvoiceBuilderService;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;

/**
 * MARKER-PATCH-204 / 206 — work-order invoice export.
 *
 * preview / download / email  -> real dompdf PDF (Print style).
 * previewHtml                 -> lightweight HTML for the live composer pane
 *                                (no PDF, no DB write).
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
        $data = $this->decorateIdentity($data, $appt, $r); // MARKER-PATCH-348
        return Pdf::loadView('tenant.invoices.pdf-print', $data)->setPaper('letter');
    }

    /**
     * MARKER-PATCH-348 — attach the print logo (base64-embedded, because dompdf
     * has remote images disabled) and the chosen logo size to the view data.
     * logo_size comes from the Print & Send window per-print; it falls back to
     * the tenant's saved print-identity size.
     */
    private function decorateIdentity(array $data, TenantAppointment $appt, Request $r): array
    {
        $identity = \App\Services\PrintIdentityService::forTenant($appt->tenant);
        $size = $r->input('logo_size');
        $data['logo_size'] = in_array($size, ['small', 'medium', 'large', 'xl'], true)
            ? $size
            : $identity['logo_size'];
        $data['logo_data'] = $this->embedLogo($identity['logo_path'] ?? null);
        return $data;
    }

    /** Read a stored logo file and return a data: URI dompdf can embed, or null. */
    private function embedLogo(?string $path): ?string
    {
        if (!$path) return null;
        $rel  = ltrim($path, '/');
        $mime = [
            'png' => 'image/png', 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'gif' => 'image/gif',
        ];
        foreach ([storage_path('app/public/' . $rel), public_path('storage/' . $rel)] as $abs) {
            if (!is_file($abs)) continue;
            $ext = strtolower(pathinfo($abs, PATHINFO_EXTENSION));
            if (!isset($mime[$ext])) return null; // skip svg/webp — dompdf can't embed reliably
            $bytes = @file_get_contents($abs);
            if ($bytes !== false) return 'data:' . $mime[$ext] . ';base64,' . base64_encode($bytes);
        }
        return null;
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

    /**
     * MARKER-PATCH-206 — live preview pane. Returns rendered HTML (NOT a PDF)
     * for the composer iframe. Never writes to the DB — keystroke-safe.
     */
    public function previewHtml(Request $r, string $id)
    {
        $appt  = $this->find($id);
        $style = $r->input('style') === 'branded' ? 'branded' : 'print';
        $data  = $this->builder->forAppointment($appt, [
            'style' => $style,
            'terms' => $r->input('terms'),
            'note'  => $r->input('note', $appt->invoice_note),
        ]);
        $view = $style === 'branded' ? 'tenant.invoices.web-branded' : 'tenant.invoices.pdf-print';
        if ($view === 'tenant.invoices.pdf-print') {
            $data = $this->decorateIdentity($data, $appt, $r); // MARKER-PATCH-348
        }

        return response(view($view, $data)->render())
            ->header('Content-Type', 'text/html; charset=utf-8')
            ->header('X-Frame-Options', 'SAMEORIGIN');
    }
}
