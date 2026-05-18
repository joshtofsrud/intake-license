<?php

namespace App\Services\Tenant;

use App\Models\Tenant;
use App\Models\Tenant\TenantAppointment;
use App\Models\Tenant\TenantCapacityRule;
use App\Models\Tenant\TenantCustomer;
use App\Models\Tenant\TenantResource;
use App\Models\Tenant\TenantServiceItem;
use App\Models\Tenant\TenantUser;
use App\Models\Tenant\TenantWaitlistEntry;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class DashboardDataService
{
    public function __construct(private readonly Tenant $tenant) {}

    /**
     * "Now" in tenant local time. Use for any date-of-day calculation
     * the tenant will see (greeting hour, today's appointments, week boundaries).
     * For storage timestamps and created_at/updated_at comparisons, use plain now() — those are UTC.
     */
    private function tnow(): Carbon
    {
        return Carbon::now($this->tenant->timezone());
    }

    public function greeting(?object $user = null): array
    {
        $hour = (int) $this->tnow()->format('G');
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
            'date_long'   => $this->tnow()->format('l, F j'),
        ];
    }

    public function zoneToday(): array
    {
        $today = $this->tnow()->toDateString();
        $weekStart = $this->tnow()->startOfWeek()->toDateString();

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
            return $apptDateTime->greaterThanOrEqualTo($this->tnow());
        });

        // Patch 47: no fallback to first-of-day. If today's appointments are all
        // in the past, $nextUp stays null and the Blade hides the card. Showing
        // a completed 8am appointment as "Next up" at 9pm is worse than hiding
        // the card entirely. Future: fall through to tomorrow's first appointment.
        // (No fallback assignment — $nextUp may legitimately be null.)

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
            'strip'               => $this->build7DayStripCenteredOn($this->tnow()->startOfDay()),
        ];
    }

    public function zoneAttention(): array
    {
        $tenantId = $this->tenant->id;
        $today = $this->tnow()->toDateString();

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
                'title' => 'Pending bookings',
                'desc'  => $unconfirmedCount === 1
                    ? '1 booking awaiting confirmation or drop-off'
                    : $unconfirmedCount . ' bookings awaiting confirmation or drop-off',
                'tone'  => 'red',  // your action: review and confirm
                'link'  => route('tenant.appointments.index', ['filter' => 'unconfirmed_bookings']),
            ];
        }

        if ($unpaidDoneCount > 0) {
            $cards[] = [
                'count' => $unpaidDoneCount,
                'title' => 'Unpaid completed jobs',
                'desc'  => '$' . number_format($unpaidDoneSumCents / 100, 0) . ' outstanding on finished work',
                'tone'  => 'amber',  // customer's action: send payment
                'link'  => route('tenant.appointments.index', ['filter' => 'unpaid_completed']),
            ];
        }

        if ($readyPickupCount > 0) {
            $cards[] = [
                'count' => $readyPickupCount,
                'title' => 'Ready for pickup',
                'desc'  => $readyPickupCount === 1
                    ? 'Customer ready to receive their bike'
                    : 'Customers ready to receive their bikes',
                'tone'  => 'amber',  // customer's action: collect their item
                'link'  => route('tenant.appointments.index', ['filter' => 'ready_pickup']),
            ];
        }

        if ($waitlistCount > 0) {
            $cards[] = [
                'count' => $waitlistCount,
                'title' => 'Waitlist entries',
                'desc'  => $waitlistCount === 1
                    ? 'Customer waiting for an opening'
                    : 'Customers waiting for an opening',
                'tone'  => 'amber',  // customer's action: accept the opening (waitlist page, not appointments)
                'link'  => route('tenant.waitlist.index'),
            ];
        }

        // ---- Overdue categories ----
        $overdueUnstartedCount = TenantAppointment::where('tenant_id', $tenantId)
            ->whereIn('status', ['pending', 'confirmed'])
            ->whereDate('appointment_date', '<', $today)
            ->count();

        if ($overdueUnstartedCount > 0) {
            $cards[] = [
                'count' => $overdueUnstartedCount,
                'title' => 'Overdue: not started',
                'desc'  => $overdueUnstartedCount === 1
                    ? 'Appointment past its scheduled date and never started'
                    : 'Appointments past their scheduled date and never started',
                'tone'  => 'red',
                'link'  => route('tenant.appointments.index', ['filter' => 'overdue_unstarted']),
            ];
        }

        $overdueInProgressCount = TenantAppointment::where('tenant_id', $tenantId)
            ->where('status', 'in_progress')
            ->whereDate('appointment_date', '<', $today)
            ->count();

        if ($overdueInProgressCount > 0) {
            $cards[] = [
                'count' => $overdueInProgressCount,
                'title' => 'Overdue: in progress',
                'desc'  => $overdueInProgressCount === 1
                    ? 'Job started but not closed out'
                    : 'Jobs started but not closed out',
                'tone'  => 'red',  // your action: close out the job (more concerning than unstarted)
                'link'  => route('tenant.appointments.index', ['filter' => 'overdue_in_progress']),
            ];
        }

        $stalePickupCount = TenantAppointment::where('tenant_id', $tenantId)
            ->where('status', 'completed')
            ->where('updated_at', '<', now()->subDays(3))
            ->count();

        if ($stalePickupCount > 0) {
            $cards[] = [
                'count' => $stalePickupCount,
                'title' => 'Stale pickups',
                'desc'  => $stalePickupCount === 1
                    ? 'Completed 3+ days ago, customer not collected'
                    : 'Completed 3+ days ago, customers not collected',
                'tone'  => 'amber',
                'link'  => route('tenant.appointments.index', ['filter' => 'stale_pickups']),
            ];
        }

        // patch-92 SO triage cards — appended to the existing rule set.
        // Arrived: status=arrived (waiting on staff to pull from bench).
        // Overdue: status=ordered AND expected_arrival_date past today
        //   (vendor missed promised date, chase them).
        $soArrivedCount = \App\Models\Tenant\TenantSpecialOrder::where('tenant_id', $tenantId)
            ->where('status', \App\Models\Tenant\TenantSpecialOrder::STATUS_ARRIVED)
            ->count();

        if ($soArrivedCount > 0) {
            $cards[] = [
                'count' => $soArrivedCount,
                'title' => 'Special orders arrived',
                'desc'  => $soArrivedCount === 1
                    ? 'Customer part on the bench, ready to pull and notify'
                    : 'Customer parts on the bench, ready to pull and notify',
                'tone'  => 'amber',  // your action: pull from bench + tell customer
                'link'  => route('tenant.special-orders.index', ['view' => 'arrived_bench']),
            ];
        }

        $soOverdueCount = \App\Models\Tenant\TenantSpecialOrder::where('tenant_id', $tenantId)
            ->where('status', \App\Models\Tenant\TenantSpecialOrder::STATUS_ORDERED)
            ->whereNotNull('expected_arrival_date')
            ->whereDate('expected_arrival_date', '<', $today)
            ->count();

        if ($soOverdueCount > 0) {
            $cards[] = [
                'count' => $soOverdueCount,
                'title' => 'Special orders overdue',
                'desc'  => $soOverdueCount === 1
                    ? 'Vendor missed expected arrival — chase them'
                    : 'Vendors missed expected arrivals — chase them',
                'tone'  => 'red',  // your action: contact vendor about delay
                'link'  => route('tenant.special-orders.index', ['view' => 'overdue']),
            ];
        }

        // patch-100b transfer requests tile — pending requests need
        // action by staff at the source location to physically move stock.
        $trPendingCount = \App\Models\Tenant\TenantTransferRequest::where('tenant_id', $tenantId)
            ->where('status', 'pending')
            ->count();

        if ($trPendingCount > 0) {
            $cards[] = [
                'count' => $trPendingCount,
                'title' => 'Transfer requests',
                'desc'  => $trPendingCount === 1
                    ? 'A staff member requested stock be transferred between locations'
                    : 'Staff members have requested stock be transferred between locations',
                'tone'  => 'amber',
                'link'  => route('tenant.transfer-requests.index'),
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
        $today = $this->tnow()->endOfDay();
        $thirtyAgo = $this->tnow()->subDays(30)->startOfDay();
        $sixtyAgo = $this->tnow()->subDays(60)->startOfDay();

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

        // Honest operational health. Each item: ['label', 'detail', 'status'].
        // Statuses match dashboard.css: 'ok' (green), 'warn' (amber), 'err' (red/grey).
        $health = [];

        // Payment processing — driven by actual processor connection state. Stripe
        // Connect / PayPal / Square aren't wired yet, so until a tenant finishes a
        // real connection flow this stays 'err' (or 'warn' if they recorded intent).
        $ppStatus = $this->tenant->payment_processor_status ?? 'not_started';
        $ppLabel  = ucfirst($this->tenant->payment_processor ?? 'Processor');
        $health[] = match ($ppStatus) {
            'connected' => [
                'label'  => 'Payment processing',
                'detail' => $ppLabel . ' connected',
                'status' => 'ok',
            ],
            'intent_recorded', 'connecting' => [
                'label'  => 'Payment processing',
                'detail' => 'Pending — finish ' . $ppLabel . ' setup',
                'status' => 'warn',
            ],
            default => [
                'label'  => 'Payment processing',
                'detail' => 'Not connected',
                'status' => 'err',
            ],
        };

        // Website — fresh tenants seed Home as is_published=false. Any published
        // page means the tenant has actually pushed something live.
        $publishedCount = \App\Models\Tenant\TenantPage::where('tenant_id', $tenantId)
            ->where('is_published', true)
            ->count();
        $health[] = $publishedCount > 0
            ? [
                'label'  => 'Website',
                'detail' => $publishedCount . ' page' . ($publishedCount === 1 ? '' : 's') . ' published',
                'status' => 'ok',
            ]
            : [
                'label'  => 'Website',
                'detail' => 'No pages published yet',
                'status' => 'err',
            ];

        // Email deliverability — bounce/complaint webhook tracking isn't wired yet.
        // Until we have real signal, show 'warn' rather than fake green.
        $health[] = [
            'label'  => 'Email deliverability',
            'detail' => 'Setup not complete',
            'status' => 'warn',
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
            // The 8-step onboarding wizard replaces this modal entirely. The
            // dashboard now redirects incomplete tenants to the wizard up front,
            // so the modal never needs to fire. Leaving the field for backward
            // compatibility with the Blade partial; flag is permanently false.
            'show_modal' => false,
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

    /**
     * Banner state for prompting tenants to set up work order fields.
     * Returns null if no banner should show.
     */
    /**
     * Returns appointment list + counts for a specific date + 7-day strip counts.
     * Used by both server render (for initial dashboard load with ?date=) and
     * the AJAX day-swap endpoint.
     */
    public function dayData(string $date): array
    {
        $tenantId = $this->tenant->id;
        $target = \Illuminate\Support\Carbon::parse($date)->startOfDay();

        $appointments = TenantAppointment::where('tenant_id', $tenantId)
            ->whereDate('appointment_date', $target->toDateString())
            ->whereNotIn('status', ['cancelled', 'refunded'])
            ->orderByRaw('appointment_time IS NULL, appointment_time ASC')
            ->orderBy('created_at')
            ->with('items')
            ->get();

        // 7-day strip: 3 days before, target, 3 days after.
        // Level (0-3) powers the heatmap-style load indicator on each day card.
        $strip = $this->build7DayStripCenteredOn($target);

        return [
            'target_date'       => $target->toDateString(),
            'target_date_long'  => $target->format('l, F j'),
            'appointments'      => $appointments,
            'appointment_count' => $appointments->count(),
            'strip'             => $strip,
        ];
    }

    /**
     * Build the 7-day strip array (3 days before, target, 3 days after)
     * with appointment counts and a 0-3 load level for each day. Used by
     * both the initial dashboard render (zoneToday) and the AJAX day-swap
     * endpoint (dayData).
     */
    private function build7DayStripCenteredOn(\Illuminate\Support\Carbon $target): array
    {
        $tenantId = $this->tenant->id;

        $activeResourceCount = max(1, TenantResource::where('tenant_id', $tenantId)
            ->where('is_active', true)
            ->count());

        $rulesByDow = TenantCapacityRule::where('tenant_id', $tenantId)
            ->where('rule_type', 'default')
            ->get()
            ->keyBy('day_of_week');

        $strip = [];
        for ($i = -3; $i <= 3; $i++) {
            $d = $target->copy()->addDays($i);
            $count = TenantAppointment::where('tenant_id', $tenantId)
                ->whereDate('appointment_date', $d->toDateString())
                ->whereNotIn('status', ['cancelled', 'refunded'])
                ->count();

            $strip[] = [
                'date'       => $d->toDateString(),
                'day_short'  => $d->format('D'),
                'day_num'    => (int) $d->format('j'),
                'is_today'   => $d->isToday(),
                'is_target'  => $i === 0,
                'count'      => $count,
                'load_level' => $this->loadLevelForDay($d, $count, $rulesByDow, $activeResourceCount),
            ];
        }
        return $strip;
    }

    /**
     * Compute a 0-3 load level for a given day based on appointment count
     * vs. theoretical max slots (capacity rule's open hours × resources).
     * 0 = closed or zero appointments
     * 1 = 1-33% full
     * 2 = 34-66% full
     * 3 = 67-100% full
     */
    private function loadLevelForDay(
        \Illuminate\Support\Carbon $date,
        int $count,
        \Illuminate\Support\Collection $rulesByDow,
        int $activeResourceCount
    ): int {
        if ($count === 0) {
            return 0;
        }
        $rule = $rulesByDow->get($date->dayOfWeek);
        if (!$rule || !$rule->open_time || !$rule->close_time) {
            // Day with bookings but no capacity rule: show light load.
            return 1;
        }
        $open  = \Illuminate\Support\Carbon::parse($date->toDateString() . ' ' . $rule->open_time);
        $close = \Illuminate\Support\Carbon::parse($date->toDateString() . ' ' . $rule->close_time);
        $intervalMin = max(1, (int) ($rule->slot_interval_minutes ?? 30));
        $minutesOpen = max(0, $close->diffInMinutes($open));
        $slotsPerResource = intdiv($minutesOpen, $intervalMin);
        $maxSlots = max(1, $slotsPerResource * $activeResourceCount);
        $ratio = $count / $maxSlots;
        if ($ratio >= 0.67) return 3;
        if ($ratio >= 0.34) return 2;
        return 1;
    }

        public function workOrderBanner(bool $dismissed): ?array
    {
        if ($dismissed) { return null; }

        $hasFields = \App\Models\Tenant\TenantWorkOrderField::where('tenant_id', $this->tenant->id)
            ->exists();

        if ($hasFields) { return null; }

        return [
            'title' => 'Set up your work order fields',
            'body'  => 'Track serial numbers, models, and whatever else your team needs to record when receiving a job. Tenants in your industry usually configure this once and forget about it.',
            'cta_label' => 'Configure now',
            'cta_url' => route('tenant.work-order-fields.index'),
        ];
    }

}
