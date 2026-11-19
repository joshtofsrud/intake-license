<?php

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Models\Tenant\TenantPosConnection;
use App\Services\Pos\PosConnectionService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Throwable;

/**
 * MARKER-POS-CONNECT: the E-commerce page's Shop POS tab — connect, check, disconnect.
 */
class PosConnectionController extends Controller
{
    private function guard(): void
    {
        abort_unless(tenant()->online_store_enabled, 404);
        $me = Auth::guard('tenant')->user();
        abort_unless($me && $me->canAccessSection('storefront'), 403);
    }

    private function back(): RedirectResponse
    {
        return redirect()->route('tenant.storefront.settings', ['tab' => 'pos']);
    }

    public function connect(Request $request, string $adapter, PosConnectionService $svc)
    {
        $this->guard();
        try {
            $url = $svc->begin(
                tenant(),
                Auth::guard('tenant')->id(),
                strtolower($adapter),
                $request->getSchemeAndHttpHost() . '/admin/storefront?tab=pos'
            );
        } catch (Throwable $e) {
            return $this->back()->with('error', $e->getMessage());
        }

        return redirect()->away($url);
    }

    public function check(PosConnectionService $svc): RedirectResponse
    {
        $this->guard();
        $conn = TenantPosConnection::where('tenant_id', tenant()->id)->first();
        if (! $conn) {
            return $this->back()->with('error', 'There is no POS connected.');
        }
        $ok = $svc->discover($conn);

        return $this->back()->with($ok ? 'success' : 'error', $ok ? 'Connection checked — everything below is current.' : 'The check failed: ' . $conn->last_error);
    }

    public function disconnect(PosConnectionService $svc): RedirectResponse
    {
        $this->guard();
        $svc->disconnect(tenant());

        return $this->back()->with('success', 'POS disconnected. Intake no longer has access.');
    }
}
