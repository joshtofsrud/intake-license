<?php
// MARKER-EMAIL-CONSENT

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Models\Tenant\TenantCustomer;
use App\Services\Tenant\ConsentService;
use Illuminate\Http\Request;

/**
 * Public one-click unsubscribe. GET shows a confirm page and POST acts —
 * mail scanners prefetch GETs, and a scanner must never unsubscribe anyone.
 * The HMAC token is the auth; no login, no expiry.
 */
class EmailPreferencesController extends Controller
{
    public function show(Request $request, string $customerId, string $sig)
    {
        [$tenant, $customer] = $this->resolve($customerId, $sig);
        if (! $customer) {
            return response()->view('public.email.unsubscribe', ['state' => 'invalid'], 404);
        }

        return view('public.email.unsubscribe', [
            'state'    => $customer->email_marketing_opt_out_at ? 'already' : 'confirm',
            'tenant'   => $tenant,
            'customer' => $customer,
            'sig'      => $sig,
        ]);
    }

    public function unsubscribe(Request $request, string $customerId, string $sig)
    {
        [$tenant, $customer] = $this->resolve($customerId, $sig);
        if (! $customer) {
            return response()->view('public.email.unsubscribe', ['state' => 'invalid'], 404);
        }

        app(ConsentService::class)->optOut($customer);

        return view('public.email.unsubscribe', [
            'state'    => 'done',
            'tenant'   => $tenant,
            'customer' => $customer,
            'sig'      => $sig,
        ]);
    }

    private function resolve(string $customerId, string $sig): array
    {
        if (! ConsentService::signatureValid($customerId, $sig)) {
            return [null, null];
        }

        // Scope to the tenant the public site resolved — a valid signature
        // for a customer of another shop must not work on this domain.
        $tenant   = tenant();
        $customer = $tenant
            ? TenantCustomer::where('tenant_id', $tenant->id)->where('id', $customerId)->first()
            : null;

        return [$tenant, $customer];
    }
}
