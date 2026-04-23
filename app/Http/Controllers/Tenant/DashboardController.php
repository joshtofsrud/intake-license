<?php

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Services\Tenant\DashboardDataService;
use Illuminate\Http\Request;

class DashboardController extends Controller
{
    public function index(Request $request)
    {
        $tenant = tenant();
        $service = new DashboardDataService($tenant);

        // Use default auth context — do NOT pass a guard name, which
        // can break impersonation flows that set their own session state.
        $user = $request->user();

        $dismissedThisSession = (bool) $request->cookie('onboarding_dismissed_at');
        $progress = $service->onboardingProgress($dismissedThisSession);

        $data = [
            'greeting'  => $service->greeting($user),
            'today'     => $service->zoneToday(),
            'attention' => $service->zoneAttention(),
            'growth'    => $service->zoneGrowth(),
            'progress'  => $progress,
        ];

        return view('tenant.dashboard', $data);
    }
}
