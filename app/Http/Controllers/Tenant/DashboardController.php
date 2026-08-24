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

        // MARKER-PATCH-110-STEP-4 — compute zones in order, then
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

        // MARKER-TILES — a second view for people who want a way in rather
        // than a report. Overview is unchanged and remains the default.
        $user = \Illuminate\Support\Facades\Auth::guard('tenant')->user();
        if (($user->dashboard_view ?? 'overview') === 'tiles') {
            $data['tiles'] = \App\Support\DashboardTiles::layout($tenant, $user);
            return view('tenant.dashboard-tiles', $data);
        }

        return view('tenant.dashboard', $data);
    }

    /** MARKER-TILES — flip between Overview and Tiles. */
    public function setView(\Illuminate\Http\Request $request)
    {
        $data = $request->validate(['view' => 'required|in:overview,tiles']);
        \Illuminate\Support\Facades\Auth::guard('tenant')->user()
            ->update(['dashboard_view' => $data['view']]);

        return redirect()->route('tenant.dashboard');
    }

    /** MARKER-TILES — save one user's tile arrangement. */
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

    /** MARKER-TILES — back to the registry's own order, everything shown. */
    public function resetTiles()
    {
        \Illuminate\Support\Facades\Auth::guard('tenant')->user()
            ->update(['dashboard_tiles' => null]);

        return redirect()->route('tenant.dashboard')->with('success', 'Tiles reset.');
    }

    public function dayJson(\Illuminate\Http\Request $request)
    {
        $tenant = tenant();
        $service = new DashboardDataService($tenant);

        $date = $request->query('date', $tenant->localToday()->toDateString());
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            $date = $tenant->localToday()->toDateString();
        }

        $data = $service->dayData($date);

        return response()->json([
            'ok' => true,
            'target_date'       => $data['target_date'],
            'target_date_long'  => $data['target_date_long'],
            'appointment_count' => $data['appointment_count'],
            'strip'             => $data['strip'],
            'appointments'      => $data['appointments']->map(function ($a) {
                return [
                    'id'                  => $a->id,
                    'url'                 => route('tenant.appointments.show', $a->id),
                    'appointment_time'    => $a->appointment_time,
                    'time_hm'             => $a->appointment_time ? \Carbon\Carbon::parse($a->appointment_time)->format('g:i') : null,
                    'time_ap'             => $a->appointment_time ? \Carbon\Carbon::parse($a->appointment_time)->format('A') : null,
                    'duration'            => (int) ($a->total_duration_minutes ?? 0),
                    'first_item'          => $a->items->first()?->item_name_snapshot ?? 'Service',
                    'customer_name'       => trim(($a->customer_first_name ?? '') . ' ' . ($a->customer_last_name ?? '')),
                    'total_formatted'     => format_money($a->total_cents),
                    'status'              => $a->status,
                    'status_label'        => ucwords(str_replace('_', ' ', $a->status)),
                    'status_class'        => str_replace('_', '-', $a->status),
                    'payment_status'      => $a->payment_status,
                    'payment_status_label'=> ucfirst($a->payment_status),
                    'receiving'           => $a->receiving_method_snapshot ?: 'Any time',
                ];
            })->values()->toArray(),
        ]);
    }

        public function dismissWorkOrderBanner(\Illuminate\Http\Request $request)
    {
        return response()
            ->json(['ok' => true])
            ->withCookie(cookie('wof_banner_dismissed', '1', 60 * 24 * 365));
    }

}
