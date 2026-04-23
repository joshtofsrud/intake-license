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

        $workOrderBannerDismissed = (bool) $request->cookie('wof_banner_dismissed');
        $workOrderBanner = $service->workOrderBanner($workOrderBannerDismissed);

        $data = [
            'greeting'  => $service->greeting($user),
            'today'     => $service->zoneToday(),
            'attention' => $service->zoneAttention(),
            'growth'    => $service->zoneGrowth(),
            'progress'  => $progress,
            'workOrderBanner' => $workOrderBanner,
        ];

        return view('tenant.dashboard', $data);
    }

    public function dayJson(\Illuminate\Http\Request $request)
    {
        $tenant = tenant();
        $service = new DashboardDataService($tenant);

        $date = $request->query('date', now()->toDateString());
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            $date = now()->toDateString();
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
