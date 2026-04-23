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

        // When impersonating, the master admin is on the 'web' guard AND
        // the tenant owner is on the 'tenant' guard. We want the tenant
        // owner for the dashboard greeting; fall back to default if no
        // tenant-guarded user exists.
        $user = $request->user('tenant') ?? $request->user();

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
