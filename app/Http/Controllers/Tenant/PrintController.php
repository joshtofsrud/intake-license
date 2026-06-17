<?php

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Models\Tenant\TenantAppointment;
use App\Models\Tenant\TenantSale;
use App\Services\DocumentBuilder;
use App\Services\PrintIdentityService;
use App\Support\DocumentOptions;
use Illuminate\Http\Request;

/**
 * Single front door for printed documents. Builds the section model via
 * DocumentBuilder and renders it through the one data-driven thermal view.
 * Parallel to the legacy tag/receipt routes until the cutover.
 *
 * MARKER-PATCH-336
 */
class PrintController extends Controller
{
    public function __construct(private DocumentBuilder $builder)
    {
    }

    public function appointment(Request $request, string $id)
    {
        $tenant = tenant();
        $appt = TenantAppointment::where('tenant_id', $tenant->id)->where('id', $id)->firstOrFail();

        $opt = DocumentOptions::fromRequest($request);
        if (!in_array($opt->type, ['tag', 'receipt', 'invoice'], true)) {
            $opt->type = 'receipt';
        }

        // MARKER-PATCH-341 — graphical invoice reuses the existing DomPDF stack
        if ($opt->format === 'invoice') {
            return redirect()->route('tenant.appointments.invoice.preview', ['id' => $appt->id]);
        }
        $view = $opt->format === 'full' ? 'tenant.print.full' : 'tenant.print.thermal';

        return view($view, [
            'tenant'     => $tenant,
            'doc'        => $this->builder->forAppointment($appt, $opt),
            'identity'   => PrintIdentityService::forTenant($tenant),
            'embed'      => $request->boolean('embed'),
            'showHeader' => $opt->showHeader,
        ]);
    }

    public function sale(Request $request, string $id)
    {
        $tenant = tenant();
        $sale = TenantSale::where('tenant_id', $tenant->id)->where('id', $id)->firstOrFail();

        $opt = DocumentOptions::fromRequest($request);
        $view = $opt->format === 'full' ? 'tenant.print.full' : 'tenant.print.thermal'; // MARKER-PATCH-341

        return view($view, [
            'tenant'     => $tenant,
            'doc'        => $this->builder->forSale($sale, $opt),
            'identity'   => PrintIdentityService::forTenant($tenant),
            'embed'      => $request->boolean('embed'),
            'showHeader' => $opt->showHeader,
        ]);
    }

    public function meta(Request $request, string $source, string $id)
    {
        $tenant = tenant();

        if ($source === 'appointment') {
            $appt = TenantAppointment::where('tenant_id', $tenant->id)->where('id', $id)->firstOrFail();
            $assets = \App\Models\Tenant\TenantAppointmentAsset::where('tenant_id', $tenant->id)
                ->where('appointment_id', $appt->id)
                ->orderBy('sort_order')
                ->get(['id', 'asset_name_snapshot', 'identifier_snapshot']);

            return response()->json([
                'source'       => 'appointment',
                'number'       => $appt->ra_number,
                'has_payments' => $appt->payments()->exists(),
                'assets'       => $assets->map(fn ($a) => [
                    'id'   => (string) $a->id,
                    'name' => trim(($a->asset_name_snapshot ?? '') . ($a->identifier_snapshot ? ' · ' . $a->identifier_snapshot : '')),
                ])->all(),
            ]);
        }

        $sale = TenantSale::where('tenant_id', $tenant->id)->where('id', $id)->firstOrFail();

        return response()->json([
            'source'       => 'sale',
            'number'       => $sale->sale_number,
            'has_payments' => $sale->payments()->exists(),
            'assets'       => [],
        ]);
    }

    public function email(Request $request, string $source, string $id)
    {
        $tenant = tenant();
        $opt = DocumentOptions::fromRequest($request);
        $to = $request->input('email');

        if ($source === 'appointment') {
            $appt = TenantAppointment::where('tenant_id', $tenant->id)->where('id', $id)->firstOrFail();
            $to = $to ?: $appt->customer_email;
            $doc = $this->builder->forAppointment($appt, $opt);
            $number = $appt->ra_number;
        } else {
            $sale = TenantSale::where('tenant_id', $tenant->id)->where('id', $id)->firstOrFail();
            $to = $to ?: ($sale->customer->email ?? null);
            $doc = $this->builder->forSale($sale, $opt);
            $number = $sale->sale_number;
        }

        if (!$to) {
            return response()->json(['ok' => false, 'message' => 'No email on file for this customer.'], 422);
        }

        $viewData = [
            'tenant'     => $tenant,
            'doc'        => $doc,
            'identity'   => PrintIdentityService::forTenant($tenant),
            'embed'      => true,
            'showHeader' => $opt->showHeader,
        ];
        $pdf  = \Barryvdh\DomPDF\Facade\Pdf::loadView('tenant.print.full', $viewData)->setPaper('letter');
        $type = ucfirst($doc['doc_type'] ?? 'document');
        $html = '<p>Your ' . e(strtolower($type)) . ' ' . e($number) . ' from ' . e($tenant->name) . ' is attached.</p>';

        $ok = \App\Services\EmailService::forTenant($tenant)->sendRenderedWithPdf(
            'document',
            $to,
            $type . ' ' . $number . ' from ' . $tenant->name,
            $html,
            $pdf->output(),
            strtolower($type) . '-' . $number . '.pdf'
        );

        return response()->json([
            'ok'      => $ok,
            'message' => $ok ? 'Emailed to ' . $to . '.' : 'Could not send — the address may be suppressed.',
        ], $ok ? 200 : 502);
    }
}
