<?php

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Services\Tenant\DashboardDataService;
use App\Support\Dashboard\BlockRegistry;
use App\Support\Dashboard\DashboardTemplates;
use Illuminate\Http\Request;

class DashboardController extends Controller
{
    public function index(Request $request)
    {
        $tenant = tenant();

        // Onboarding wizard takes priority over the dashboard for any tenant
        // who hasn't completed setup. The wizard tracks its own step in
        // tenant.onboarding_step (1..8); a NULL step on an incomplete tenant
        // means they haven't started — send to step 1.
        if ($tenant->onboarding_status !== 'complete') {
            $stepSlugs = [
                1 => 'industry', 2 => 'identity', 3 => 'booking', 4 => 'hours',
                5 => 'services', 6 => 'team',     7 => 'payment', 8 => 'done',
            ];
            $stepNum  = $tenant->onboarding_step ?? 1;
            $stepSlug = $stepSlugs[$stepNum] ?? 'industry';

            return redirect()->route('tenant.onboarding.wizard.' . $stepSlug, [
                ]);
        }

        $service = new DashboardDataService($tenant);

        // When impersonating, the master admin is on the 'web' guard AND
        // the tenant owner is on the 'tenant' guard. We want the tenant
        // owner for the dashboard greeting; fall back to default if no
        // tenant-guarded user exists.
        $user = $request->user('tenant') ?? $request->user();

        $dismissedThisSession = (bool) $request->cookie('onboarding_dismissed_at');
        $progress = $service->onboardingProgress($dismissedThisSession);

        $workOrderBannerDismissed = (bool) $request->cookie('wof_banner_dismissed');
        $workOrderBanner = $service->workOrderBanner($workOrderBannerDismissed);

        // compute zones in order, then
        // pass today + attention into zoneLauncher so it can reuse already-
        // computed counts (low stock, SO counts) without re-querying.
        $today     = $service->zoneToday();
        $attention = $service->zoneAttention();

        $data = [
            'greeting'  => $service->greeting($user),
            'today'     => $today,
            'attention' => $attention,
            'growth'    => $service->zoneGrowth(),
            'launcher'  => $service->zoneLauncher($today, $attention),
            'progress'  => $progress,
            'workOrderBanner' => $workOrderBanner,
        ];

        // MARKER-DASH-TEMPLATES: the dashboard is a template — Overview, Tiles,
        // Brief, or one built in master admin. The person's pick lives in
        // dashboard_view (the template key); the default covers no pick, or
        // a pick that's no longer offered.
        $user = \Illuminate\Support\Facades\Auth::guard('tenant')->user();
        $template = DashboardTemplates::activeFor($user->dashboard_view ?? null);
        $rows = $template['rows'];

        // Only load what this template's blocks draw.
        $needs = BlockRegistry::needs($rows);
        if (in_array('week', $needs, true)) {
            $data['week'] = $service->weekAhead();
        }
        if (in_array('jobs', $needs, true)) {
            $data['jobs'] = $service->jobsCompleted30();
        }
        if (in_array('tiles', $needs, true)) {
            $data['tiles'] = \App\Support\DashboardTiles::layout($tenant, $user);
        }

        $data['activeTemplate']  = $template;
        $data['switchTemplates'] = DashboardTemplates::switchable();
        $data['dashRows']        = $rows;

        return view(DashboardTemplates::frameView($template), $data);
    }

    /** switch to another dashboard template. */
    public function setView(\Illuminate\Http\Request $request)
    {
        $data = $request->validate(['view' => ['required', 'string', \Illuminate\Validation\Rule::in(DashboardTemplates::keys())]]);
        \Illuminate\Support\Facades\Auth::guard('tenant')->user()
            ->update(['dashboard_view' => $data['view']]);

        return redirect()->route('tenant.dashboard');
    }

    /** save one user's tile arrangement. */
    public function saveTiles(\Illuminate\Http\Request $request)
    {
        $data = $request->validate([
            'order'    => 'nullable|array|max:60',
            'order.*'  => 'string|max:40',
            'hidden'   => 'nullable|array|max:60',
            'hidden.*' => 'string|max:40',
        ]);

        // Only keys the registry actually knows — a stale tab or a hand-made
        // request can't stuff arbitrary strings into the column.
        $known = array_keys(\App\Support\DashboardTiles::definitions());
        $clean = [
            'order'  => array_values(array_intersect($data['order']  ?? [], $known)),
            'hidden' => array_values(array_intersect($data['hidden'] ?? [], $known)),
        ];

        \Illuminate\Support\Facades\Auth::guard('tenant')->user()
            ->update(['dashboard_tiles' => $clean]);

        return response()->json(['ok' => true]);
    }

    /** back to the registry's own order, everything shown. */
    public function resetTiles()
    {
        \Illuminate\Support\Facades\Auth::guard('tenant')->user()
            ->update(['dashboard_tiles' => null]);

        return redirect()->route('tenant.dashboard')->with('success', 'Tiles reset.');
    }

    public function dismissWorkOrderBanner(\Illuminate\Http\Request $request)
    {
        return response()
            ->json(['ok' => true])
            ->withCookie(cookie('wof_banner_dismissed', '1', 60 * 24 * 365));
    }

}
