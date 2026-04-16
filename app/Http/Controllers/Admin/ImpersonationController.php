<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Tenant;
use App\Models\Tenant\TenantUser;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Session;

class ImpersonationController extends Controller
{
    // ----------------------------------------------------------------
    // POST /admin/impersonate/{tenantId}
    // Called from the Filament TenantResource action.
    // Logs the master admin in as the tenant's first owner.
    // ----------------------------------------------------------------
    public function impersonate(Request $request, string $tenantId)
    {
        $tenant = Tenant::findOrFail($tenantId);

        $owner = TenantUser::where('tenant_id', $tenant->id)
            ->where('role', 'owner')
            ->where('is_active', true)
            ->first();

        if (! $owner) {
            return back()->withErrors(['error' => 'No active owner found for this tenant.']);
        }

        // Store the master admin's identity so we can restore it
        Session::put('impersonating_from', [
            'guard'      => 'web',
            'user_id'    => Auth::id(),
            'return_url' => url('/admin/tenants'),
        ]);

        // Log in as the tenant owner via the tenant guard
        Auth::guard('tenant')->login($owner);

        // Redirect to their admin dashboard
        $adminUrl = 'https://' . $tenant->subdomain . '.' . config('intake.domain') . '/admin';

        return redirect($adminUrl)->with('info',
            'You are impersonating ' . $tenant->name . ' as ' . $owner->name . '.'
        );
    }

    // ----------------------------------------------------------------
    // GET /admin/impersonate/stop
    // Clears the tenant session and redirects back to master admin.
    // ----------------------------------------------------------------
    public function stop(Request $request)
    {
        $from = Session::pull('impersonating_from');

        Auth::guard('tenant')->logout();

        $returnUrl = $from['return_url'] ?? url('/admin/tenants');

        return redirect($returnUrl)->with('success', 'Impersonation ended.');
    }
}
