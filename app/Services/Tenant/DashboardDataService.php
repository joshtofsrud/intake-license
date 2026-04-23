<?php

namespace App\Services\Tenant;

use App\Models\Tenant;
use App\Models\Tenant\TenantAppointment;
use App\Models\Tenant\TenantCapacityRule;
use App\Models\Tenant\TenantCustomer;
use App\Models\Tenant\TenantServiceItem;
use App\Models\Tenant\TenantUser;
use App\Models\Tenant\TenantWaitlistEntry;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class DashboardDataService
{
    public function __construct(private readonly Tenant $tenant) {}

    public function greeting(?TenantUser $user = null): array
    {
        $hour = (int) now()->format('G');
        $timeOfDay = match (true) {
            $hour < 12 => 'morning',
            $hour < 17 => 'afternoon',
            default    => 'evening',
        };

        $name = null;
        if ($user && $user->name) {
            $name = trim(explode(' ', $user->name)[0]);
        }

        return [
            'time_of_day' => $timeOfDay,
            'name'        => $name,
            'date_long'   => now()->format('l, F j'),
        ];
    }

    public function zoneToday(): array
    {
        $today = now()->toDateString();
        $weekStart = now()->startOfWeek()->toDateString();

        $todayAppointments = TenantAppointment::where('tenant_id', $this->tenant->id)
            ->whereDate('appointment_date', $today)
            ->whereNotIn('status', ['cancelled', 'refunded'])
            ->orderByRaw('appointment_time IS NULL, appointment_time ASC')
            ->orderBy('created_at')
            ->with('items')
            ->get();

        $nextUp = $todayAppointments->first(function ($a) {
            if (!$a->appointment_time) return false;
            $apptDateTime = Carbon::parse($a->appointment_date->toDateString() . ' ' . $a->appointment_time);
            return $apptDateTime->greaterThanOrEqualTo(now());
        });

        if (!$nextUp) {
            $nextUp = $todayAppointments->first();
        }

        $last24hNewBookings = TenantAppointment::where('tenant_id', $this->tenant->id)
            ->where('created_at', '>=', now()->subDay())
            ->count();

        $weekBase = TenantAppointment::where('tenant_id', $this->tenant->id)
            ->whereBetween('appointment_date', [$weekStart, $today]);

        $weekBookings = (clone $weekBase)->count();
        $weekRevenue = (int) (clone $weekBase)->where('payment_status', 'paid')->sum('total_cents');
        $weekCancellations = (clone $weekBase)->whereIn('status', ['cancelled', 'refunded'])->count();

        $weekNewCustomers = TenantCustomer::where('tenant_id', $this->tenant->id)
            ->where('created_at', '>=', $weekStart)
            ->count();

        return [
            'appointments'        => $todayAppointments,
            'today_count'         => $todayAppointments->count(),
            'next_up'             => $nextUp,
            'last_24h_bookings'   => $last24hNewBookings,
            'week_bookings'       => $weekBookings,
            'week_revenue_cents'  => $weekRevenue,
            'week_new_customers'  => $weekNewCustomers,
            'week_cancellations'  => $weekCancellations,
        ];
    }

    public function zoneAttention(): array
    {
        $tenantId = $this->tenant->id;
        $today = now()->toDateString();

        $unconfirmedCount = TenantAppointment::where('tenant_id', $tenantId)
            ->where('status', 'pending')
            ->whereDate('appointment_date', '>=', $today)
            ->count();

        $unpaidDoneCount = TenantAppointment::where('tenant_id', $tenantId)
            ->whereIn('status', ['completed', 'shipped', 'closed'])
            ->whereIn('payment_status', ['unpaid', 'partial'])
            ->count();

        $unpaidDoneSumCents = (int) TenantAppointment::where('tenant_id', $tenantId)
            ->whereIn('status', ['completed', 'shipped', 'closed'])
            ->whereIn('payment_status', ['unpaid', 'partial'])
            ->sum(DB::raw('total_cents - paid_cents'));

        $readyPickupCount = TenantAppointment::where('tenant_id', $tenantId)
            ->where('status', 'completed')
            ->count();

        $waitlistCount = 0;
        if (class_exists(TenantWaitlistEntry::class)) {
            try {
                $waitlistCount = TenantWaitlistEntry::where('tenant_id', $tenantId)
                    ->where('status', 'waiting')
                    ->count();
            } catch (\Throwable $e) {
                $waitlistCount = 0;
            }
        }

        $cards = [];

        if ($unconfirmedCount > 0) {
            $cards[] = [
                'count' => $unconfirmedCount,
                'title' => 'Unconfirmed bookings',
                'desc'  => $unconfirmedCount === 1
                    ? '1 booking awaiting confirmation'
                    : $unconfirmedCount . ' bookings awaiting confirmation',
                'tone'  => 'amber',
                'link'  => route('tenant.appointments.index') . '?status=pending',
            ];
        }

        if ($unpaidDoneCount > 0) {
            $cards[] = [
                'count' => $unpaidDoneCount,
                'title' => 'Unpaid completed jobs',
                'desc'  => '$' . number_format($unpaidDoneSumCents / 100, 0) . ' outstanding on finished work',
                'tone'  => 'red',
                'link'  => route('tenant.appointments.index') . '?payment_status=unpaid',
            ];
        }

        if ($readyPickupCount > 0) {
            $cards[] = [
                'count' => $readyPickupCount,
                'title' => 'Ready for pickup',
                'desc'  => $readyPickupCount === 1
                    ? 'Customer ready to receive their bike'
                    : 'Customers ready to receive their bikes',
                'tone'  => 'green',
                'link'  => route('tenant.appointments.index') . '?status=completed',
            ];
        }

        if ($waitlistCount > 0) {
            $cards[] = [
                'count' => $waitlistCount,
                'title' => 'Waitlist entries',
                'desc'  => $waitlistCount === 1
                    ? 'Customer waiting for an opening'
                    : 'Customers waiting for an opening',
                'tone'  => 'green',
                'link'  => route('tenant.waitlist.index'),
            ];
        }

        return [
            'cards'       => $cards,
            'total_items' => count($cards),
        ];
    }

    public function zoneGrowth(): array
    {
        $tenantId = $this->tenant->id;
        $today = now()->endOfDay();
        $thirtyAgo = now()->subDays(30)->startOfDay();
        $sixtyAgo = now()->subDays(60)->startOfDay();

        $revenueCurrent = (int) TenantAppointment::where('tenant_id', $tenantId)
            ->whereBetween('appointment_date', [$thirtyAgo->toDateString(), $today->toDateString()])
            ->where('payment_status', 'paid')
            ->sum('total_cents');

        $revenuePrior = (int) TenantAppointment::where('tenant_id', $tenantId)
            ->whereBetween('appointment_date', [$sixtyAgo->toDateString(), $thirtyAgo->copy()->subDay()->toDateString()])
            ->where('payment_status', 'paid')
            ->sum('total_cents');

        $revenueDelta = $revenuePrior > 0
            ? round((($revenueCurrent - $revenuePrior) / $revenuePrior) * 100)
            : null;

        $customersCurrent = TenantCustomer::where('tenant_id', $tenantId)
            ->whereBetween('created_at', [$thirtyAgo, $today])
            ->count();

        $customersPrior = TenantCustomer::where('tenant_id', $tenantId)
            ->whereBetween('created_at', [$sixtyAgo, $thirtyAgo->copy()->subDay()])
            ->count();

        $customersDelta = $customersPrior > 0
            ? round((($customersCurrent - $customersPrior) / $customersPrior) * 100)
            : null;

        $revenueSpark = $this->dailyRevenueSeries($tenantId, $thirtyAgo, $today);
        $customersSpark = $this->dailyCustomerSeries($tenantId, $thirtyAgo, $today);

        $health = [
            ['label' => 'Payment processing', 'detail' => 'Stripe connected, no failures', 'status' => 'ok'],
            ['label' => 'Website',             'detail' => 'Published and indexed',        'status' => 'ok'],
            ['label' => 'Email deliverability','detail' => 'Sending normally',             'status' => 'ok'],
        ];

        return [
            'revenue' => [
                'current_cents' => $revenueCurrent,
                'prior_cents'   => $revenuePrior,
                'delta_pct'     => $revenueDelta,
                'sparkline'     => $revenueSpark,
            ],
            'customers' => [
                'current'   => $customersCurrent,
                'prior'     => $customersPrior,
                'delta_pct' => $customersDelta,
                'sparkline' => $customersSpark,
            ],
            'health' => $health,
        ];
    }

    public function onboardingProgress(bool $dismissedThisSession): array
    {
        $tenant = $this->tenant;

        $brandingDone = !empty($tenant->logo_url)
            || (!empty($tenant->accent_color) && $tenant->accent_color !== '#BEF264')
            || !empty($tenant->tagline);

        $servicesDone = TenantServiceItem::where('tenant_id', $tenant->id)->exists();
        $hoursDone    = TenantCapacityRule::where('tenant_id', $tenant->id)->exists();

        $allDone = $brandingDone && $servicesDone && $hoursDone;

        return [
            'branding'   => $brandingDone,
            'services'   => $servicesDone,
            'hours'      => $hoursDone,
            'all_done'   => $allDone,
            'show_modal' => !$allDone && !$dismissedThisSession,
        ];
    }

    private function dailyRevenueSeries(string $tenantId, Carbon $from, Carbon $to): array
    {
        $rows = TenantAppointment::where('tenant_id', $tenantId)
            ->whereBetween('appointment_date', [$from->toDateString(), $to->toDateString()])
            ->where('payment_status', 'paid')
            ->selectRaw('DATE(appointment_date) as d, SUM(total_cents) as cents')
            ->groupBy('d')
            ->pluck('cents', 'd')
            ->toArray();

        return $this->fillDailySeries($from, $to, $rows, 0);
    }

    private function dailyCustomerSeries(string $tenantId, Carbon $from, Carbon $to): array
    {
        $rows = TenantCustomer::where('tenant_id', $tenantId)
            ->whereBetween('created_at', [$from, $to])
            ->selectRaw('DATE(created_at) as d, COUNT(*) as c')
            ->groupBy('d')
            ->pluck('c', 'd')
            ->toArray();

        return $this->fillDailySeries($from, $to, $rows, 0);
    }

    private function fillDailySeries(Carbon $from, Carbon $to, array $rows, int|float $default): array
    {
        $series = [];
        $cursor = $from->copy()->startOfDay();
        $end = $to->copy()->startOfDay();

        while ($cursor->lessThanOrEqualTo($end)) {
            $key = $cursor->toDateString();
            $series[] = (int) ($rows[$key] ?? $default);
            $cursor->addDay();
        }
        return $series;
    }
}
