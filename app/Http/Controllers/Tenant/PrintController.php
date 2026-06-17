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

        return view('tenant.print.thermal', [
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

        return view('tenant.print.thermal', [
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
}
