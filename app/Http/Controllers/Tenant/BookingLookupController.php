<?php

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Models\Tenant\TenantCustomer;
use App\Models\Tenant\TenantCustomerAsset;
use Illuminate\Http\Request;

/**
 * MARKER-PATCH-213 — returning-customer lookup for the public booking flow.
 *
 * The "You" step (returning path) calls this with an email; if we recognize it
 * in this tenant, we hand back the customer's saved assets so the "Bikes" step
 * can pre-fill. Deliberately minimal + always 200, so the endpoint can't be
 * used to enumerate which emails are customers.
 */
class BookingLookupController extends Controller
{
    public function lookup(Request $request)
    {
        $email = strtolower(trim((string) $request->input('email', '')));

        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return response()->json(['found' => false]);
        }

        $tenant   = tenant();
        $customer = TenantCustomer::where('tenant_id', $tenant->id)
            ->where('email', $email)
            ->first();

        if (!$customer) {
            return response()->json(['found' => false]);
        }

        $assets = TenantCustomerAsset::where('tenant_id', $tenant->id)
            ->where('customer_id', $customer->id)
            ->active()                       // non-archived only
            ->orderBy('name')
            ->get()
            ->map(fn($a) => [
                'id'         => $a->id,
                'name'       => $a->name,
                'identifier' => $a->identifier,
                'metadata'   => $a->metadata,
            ])->values()->toArray();

        return response()->json([
            'found'       => true,
            'customer_id' => $customer->id,
            'first_name'  => $customer->first_name,
            'assets'      => $assets,
        ]);
    }
}
