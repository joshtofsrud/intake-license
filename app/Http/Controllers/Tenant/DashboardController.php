<?php

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Services\Tenant\DashboardDataService;
use App\Support\Dashboard\BlockRegistry;
use App\Support\Dashboard\CustomizePanel;
use App\Support\Dashboard\DashboardTemplates;
use App\Support\Dashboard\Layout;
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
        $template = DashboardTemplates::activeFor($user->dashboard_view ?? null, $tenant);

        // MARKER-DASH-AUDIENCE: blocks this person's role doesn't get are left
        // out, then their own hides and order are applied, as far as the
        // template (and the platform-wide switch) allow.
        $rearrange = DashboardTemplates::rearrangeFor($template);
        $prefs = (array) (($user->dashboard_prefs ?? [])[$template['key']] ?? []);
        $roleRows = Layout::personal($template['rows'], $user->role, [], 'none');
        $rows = Layout::personal($template['rows'], $user->role, $prefs, $rearrange);

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
        $data['switchTemplates'] = DashboardTemplates::switchable($tenant);
        $data['dashRows']        = $rows;
        $data['customize']       = CustomizePanel::for($template, $roleRows, $prefs, $rearrange);

        return view(DashboardTemplates::frameView($template), $data);
    }

    /** switch to another dashboard template. */
    public function setView(\Illuminate\Http\Request $request)
    {
        $data = $request->validate(['view' => ['required', 'string', \Illuminate\Validation\Rule::in(DashboardTemplates::keys(tenant()))]]);
        \Illuminate\Support\Facades\Auth::guard('tenant')->user()
            ->update(['dashboard_view' => $data['view']]);

        return redirect()->route('tenant.dashboard');
    }

    /**
     * MARKER-DASH-AUDIENCE: save what one person hid or reordered on one
     * template. Only blocks the template has, and only what the template
     * lets people change; a reset clears their choices for that template.
     */
    public function saveCustomize(Request $request)
    {
        $user = \Illuminate\Support\Facades\Auth::guard('tenant')->user();
        $data = $request->validate([
            'template'  => ['required', 'string', \Illuminate\Validation\Rule::in(DashboardTemplates::keys(tenant()))],
            'reset'     => 'nullable|boolean',
            'hidden'    => 'nullable|array|max:60',
            'hidden.*'  => 'string|max:40',
            'order'     => 'nullable|array|max:60',
            'order.*'   => 'array|max:60',
            'order.*.*' => 'string|max:40',
        ]);

        $template = DashboardTemplates::activeFor($data['template'], tenant());
        $all = (array) ($user->dashboard_prefs ?? []);
        if (! empty($data['reset'])) {
            unset($all[$template['key']]);
        } else {
            $clean = CustomizePanel::clean($template, $user->role, $data, DashboardTemplates::rearrangeFor($template));
            if ($clean === null) {
                return response()->json(['ok' => false, 'error' => 'Changing this dashboard is turned off.'], 403);
            }
            $all[$template['key']] = $clean;
        }
        $user->update(['dashboard_prefs' => $all ?: null]);

        return response()->json(['ok' => true]);
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
