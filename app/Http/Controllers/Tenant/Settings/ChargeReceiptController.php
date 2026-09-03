<?php

namespace App\Http\Controllers\Tenant\Settings;

use App\Http\Controllers\Controller;
use App\Models\TenantChargeRun;
use App\Services\Billing\ReceiptBuilder;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Facades\Auth;

// MARKER-BILLING-RECEIPT
class ChargeReceiptController extends Controller
{
    public function show(string $runId, ReceiptBuilder $builder)
    {
        $me = Auth::guard('tenant')->user();
        if (! $me || ! $me->isManager()) {
            abort(redirect()->route('tenant.settings.index'));
        }

        // Scoped to this tenant: a run id from another shop must not resolve.
        $run = TenantChargeRun::where('tenant_id', tenant()->id)->findOrFail($runId);

        $data = $builder->for($run);

        return Pdf::loadView('tenant.settings.receipt-pdf', $data)
            ->setPaper('letter')
            ->stream('intake-receipt-' . $data['number'] . '.pdf');
    }
}
