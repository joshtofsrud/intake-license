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
}
