<?php

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Models\Tenant\TenantAppointment;
use Illuminate\Http\Request;

class DashboardController extends Controller
{
    public function index(Request $request)
    {
        $tenant = tenant();

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

        return view('tenant.dashboard', compact('stats', 'recentAppointments'));
    }
}
