<?php

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Models\Tenant\TenantAppointment;
use App\Models\Tenant\TenantCapacityRule;
use App\Models\Tenant\TenantCustomer;
use App\Models\Tenant\TenantServiceItem;
use Illuminate\Http\Request;

class DashboardController extends Controller
{
    public function index(Request $request)
    {
        $tenant = tenant();

        // ------------------------------------------------------------------
        // Stats
        // ------------------------------------------------------------------
        $today          = now()->toDateString();
        $weekStart      = now()->startOfWeek()->toDateString();
        $weekEnd        = now()->endOfWeek()->toDateString();
        $lastWeekStart  = now()->subWeek()->startOfWeek()->toDateString();
        $lastWeekEnd    = now()->subWeek()->endOfWeek()->toDateString();
        $monthStart     = now()->startOfMonth()->toDateString();
        $lastMonthStart = now()->subMonth()->startOfMonth()->toDateString();
        $lastMonthEnd   = now()->subMonth()->endOfMonth()->toDateString();

        $base = TenantAppointment::where('tenant_id', $tenant->id);

        $todayCount    = (clone $base)->whereDate('appointment_date', $today)->count();
        $todayOpen     = (clone $base)->whereDate('appointment_date', $today)
                           ->whereNotIn('status', ['completed','closed','shipped','cancelled','refunded'])->count();
        $weekCount     = (clone $base)->whereBetween('appointment_date', [$weekStart, $weekEnd])->count();
        $lastWeekCount = (clone $base)->whereBetween('appointment_date', [$lastWeekStart, $lastWeekEnd])->count();
        $openCount     = (clone $base)->whereNotIn('status', ['completed','closed','shipped','cancelled','refunded'])->count();
        $readyPickup   = (clone $base)->whereIn('status', ['completed','shipped'])->count();

        $revenueMtd       = (int)(clone $base)->whereBetween('appointment_date', [$monthStart, $today])->where('payment_status','paid')->sum('total_cents');
        $revenueLastMonth = (int)(clone $base)->whereBetween('appointment_date', [$lastMonthStart, $lastMonthEnd])->where('payment_status','paid')->sum('total_cents');
        $revenueDelta     = $revenueLastMonth > 0 ? round((($revenueMtd - $revenueLastMonth) / $revenueLastMonth) * 100) : null;

        $stats = [
            'today'         => $todayCount,
            'today_open'    => $todayOpen,
            'week'          => $weekCount,
            'week_delta'    => $weekCount - $lastWeekCount,
            'open'          => $openCount,
            'ready_pickup'  => $readyPickup,
            'revenue_mtd'   => $revenueMtd,
            'revenue_delta' => $revenueDelta,
        ];

        $recentAppointments = TenantAppointment::where('tenant_id', $tenant->id)
            ->orderByDesc('appointment_date')->orderByDesc('created_at')
            ->limit(10)->get();

        // ------------------------------------------------------------------
        // Onboarding progress — each step is "done" when the tenant has
        // moved its relevant data away from defaults
        // ------------------------------------------------------------------
        $progress = $this->onboardingProgress($tenant);

        return view('tenant.dashboard', compact(
            'stats', 'recentAppointments', 'progress'
        ));
    }

    /**
     * Compute onboarding checklist progress for the given tenant.
     *
     * Each step returns ['done' => bool, ...] so the view can render
     * check marks + link to the right place to finish it.
     */
    private function onboardingProgress($tenant): array
    {
        // Branding: any non-default value on logo, accent_color, or tagline
        $brandingDone = !empty($tenant->logo_url)
            || (!empty($tenant->accent_color) && $tenant->accent_color !== '#BEF264')
            || !empty($tenant->tagline);

        // Services: at least one service item exists
        $servicesDone = TenantServiceItem::where('tenant_id', $tenant->id)->exists();

        // Hours: at least one capacity rule exists
        $hoursDone = TenantCapacityRule::where('tenant_id', $tenant->id)->exists();

        // Complete when all three required are done
        $allDone = $brandingDone && $servicesDone && $hoursDone;

        return [
            'branding'      => $brandingDone,
            'services'      => $servicesDone,
            'hours'         => $hoursDone,
            'all_done'      => $allDone,
            'show_modal'    => !$allDone && !request()->cookie('onboarding_dismissed_at'),
            'completed'     => array_sum([$brandingDone, $servicesDone, $hoursDone]),
            'total'         => 3,
        ];
    }
}
