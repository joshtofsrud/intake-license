<?php

namespace App\Services\Tenant;

use App\Models\Tenant;
use App\Models\Tenant\TenantAppointment;
use App\Support\AppointmentStatus;
use App\Models\Tenant\TenantCapacityRule;
use App\Models\Tenant\TenantCustomer;
use App\Models\Tenant\TenantResource;
use App\Models\Tenant\TenantServiceItem;
use App\Models\Tenant\TenantUser;
use App\Models\Tenant\TenantWaitlistEntry;
use App\Models\Tenant\TenantInventoryItem;
use App\Services\Tenant\CustomersReportService;
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
            ->whereNotIn('status', AppointmentStatus::terminalStatuses())
            ->orderByRaw('appointment_time IS NULL, appointment_time ASC')
            ->orderBy('created_at')
            ->with(['items', 'customer'])
            ->get();

        $nextUp = $todayAppointments->first(function ($a) {
            if (!$a->appointment_time) return false;
            // appointment_time is naive tenant-local wall-clock;
            // parse it in the tenant timezone so "is it still upcoming?" compares
            // real instants against tnow() (was ~7h early, which hid the next-up
            // banner for genuinely upcoming appointments).
            $apptDateTime = Carbon::parse($a->appointment_date->toDateString() . ' ' . $a->appointment_time, $this->tenant->timezone());
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
        // week revenue = payments received (sale ledger).
        $tzW = $this->tenant->timezone();
        // $weekStart is a Y-m-d string; parse in tenant tz for the UTC window.
        $weekRevenue = (int) \App\Models\Tenant\TenantSalePayment::where('tenant_id', $this->tenant->id)
            ->whereBetween('recorded_at', [
                Carbon::parse($weekStart, $tzW)->startOfDay()->utc(),
                $this->tnow()->copy()->setTimezone($tzW)->endOfDay()->utc(),
            ])
            ->sum('amount_cents');
        $weekCancellations = (clone $weekBase)->whereIn('status', AppointmentStatus::terminalStatuses())->count();

        $weekNewCustomers = TenantCustomer::where('tenant_id', $this->tenant->id)
            ->where('created_at', '>=', $weekStart)
            ->count();

        // today's deliveries for the dashboard mini-section.
        $todayDeliveries = collect();
        try {
            $todayDeliveries = (new \App\Services\Tenant\TenantDeliveryService($this->tenant))
                ->forDay($this->tnow());
        } catch (\Throwable $e) {
            $todayDeliveries = collect();
        }

        // an empty today points at the next day that
        // has work (within 30 days), for appointments and for pickups/drop-offs
        // separately, so the dashboard says what's coming instead of "nothing".
        $tz = $this->tenant->timezone();
        $horizon = $this->tnow()->copy()->addDays(30);
        $nextApptDay = null;
        $nextAppts = collect();
        if ($todayAppointments->isEmpty()) {
            $d = TenantAppointment::where('tenant_id', $this->tenant->id)
                ->whereDate('appointment_date', '>', $today)
                ->whereDate('appointment_date', '<=', $horizon->toDateString())
                ->whereNotIn('status', AppointmentStatus::terminalStatuses())
                ->min('appointment_date');
            if ($d) {
                $nextApptDay = Carbon::parse(substr((string) $d, 0, 10), $tz)->startOfDay();
                $nextAppts = TenantAppointment::where('tenant_id', $this->tenant->id)
                    ->whereDate('appointment_date', $nextApptDay->toDateString())
                    ->whereNotIn('status', AppointmentStatus::terminalStatuses())
                    ->orderByRaw('appointment_time IS NULL, appointment_time ASC')
                    ->orderBy('created_at')
                    ->with(['items', 'customer'])
                    ->get();
            }
        }
        $nextDeliveryDay = null;
        $nextDeliveries = collect();
        if ($todayDeliveries->isEmpty()) {
            try {
                $first = \App\Models\Tenant\TenantDelivery::where('tenant_id', $this->tenant->id)
                    ->where('status', '!=', \App\Models\Tenant\TenantDelivery::STATUS_CANCELLED)
                    ->where('scheduled_at', '>', $this->tnow()->copy()->setTimezone($tz)->endOfDay()->utc())
                    ->where('scheduled_at', '<=', $horizon->copy()->utc())
                    ->orderBy('scheduled_at')
                    ->value('scheduled_at');
                if ($first) {
                    $nextDeliveryDay = Carbon::parse($first, 'UTC')->setTimezone($tz)->startOfDay();
                    $nextDeliveries = (new \App\Services\Tenant\TenantDeliveryService($this->tenant))->forDay($nextDeliveryDay);
                }
            } catch (\Throwable $e) {
                $nextDeliveryDay = null;
                $nextDeliveries = collect();
            }
        }

        return [
            'next_day'            => $nextApptDay,
            'next_day_appointments' => $nextAppts,
            'next_delivery_day'   => $nextDeliveryDay,
            'next_day_deliveries' => $nextDeliveries,
            'appointments'        => $todayAppointments,
            'today_count'         => $todayAppointments->count(),
            'today_deliveries'    => $todayDeliveries,
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
        $today = $this->tnow()->toDateString();

        $unconfirmedCount = TenantAppointment::where('tenant_id', $tenantId)
            ->whereIn('status', AppointmentStatus::awaitingStatuses())
            ->whereDate('appointment_date', '>=', $today)
            ->count();

        $pickupOutreachCount = TenantAppointment::where('tenant_id', $tenantId)
            ->where('pickup_outreach_pending', true)
            ->count();

        $unpaidDoneCount = TenantAppointment::where('tenant_id', $tenantId)
            ->whereIn('status', AppointmentStatus::doneStatuses())
            ->whereIn('payment_status', ['unpaid', 'partial'])
            ->count();

        $unpaidDoneSumCents = (int) TenantAppointment::where('tenant_id', $tenantId)
            ->whereIn('status', AppointmentStatus::doneStatuses())
            ->whereIn('payment_status', ['unpaid', 'partial'])
            ->sum(DB::raw('total_cents - paid_cents'));

        $readyPickupCount = TenantAppointment::where('tenant_id', $tenantId)
            ->whereIn('status', AppointmentStatus::doneStatuses())
            ->whereIn('payment_status', ['unpaid', 'partial'])
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

        // completed jobs with no scheduled drop-off (P&D tenants).
        // No-reply proposals (customer never picked from the options link) called out.
        $awaitingDeliveryCount = 0;
        $awaitingNoReplyCount  = 0;
        if ($this->tenant->deliveries_enabled) {
            $base = TenantAppointment::query()
                ->where('tenant_appointments.tenant_id', $tenantId)
                ->where('tenant_appointments.status', 'completed')
                ->whereNotNull('tenant_appointments.completed_at')
                ->where('tenant_appointments.completed_at', '>=', now()->subDays(14))
                // a decided job is gone from the
                // queue; a snoozed one is hidden until its wake time.
                ->whereNull('tenant_appointments.delivery_resolution')
                ->where(function ($q) {
                    $q->whereNull('tenant_appointments.delivery_snooze_until')
                      ->orWhere('tenant_appointments.delivery_snooze_until', '<=', now());
                })
                ->whereNotExists(function ($q) {
                    $q->selectRaw('1')
                        ->from('tenant_deliveries')
                        ->whereColumn('tenant_deliveries.appointment_id', 'tenant_appointments.id')
                        ->where('tenant_deliveries.type', 'dropoff')
                        ->where('tenant_deliveries.status', '!=', 'cancelled');
                });
            $awaitingDeliveryCount = (clone $base)->count();
            if ($awaitingDeliveryCount > 0) {
                $awaitingNoReplyCount = (clone $base)
                    ->whereExists(function ($q) use ($tenantId) {
                        $q->selectRaw('1')
                            ->from('tenant_delivery_proposals')
                            ->whereColumn('tenant_delivery_proposals.appointment_id', 'tenant_appointments.id')
                            ->where('tenant_delivery_proposals.tenant_id', $tenantId)
                            ->where('tenant_delivery_proposals.status', 'no_reply');
                    })->count();
                // asked to be phoned instead.
                $awaitingCallCount = (clone $base)
                    ->whereExists(function ($q) use ($tenantId) {
                        $q->selectRaw('1')
                            ->from('tenant_delivery_proposals')
                            ->whereColumn('tenant_delivery_proposals.appointment_id', 'tenant_appointments.id')
                            ->where('tenant_delivery_proposals.tenant_id', $tenantId)
                            ->where('tenant_delivery_proposals.status', 'call_requested');
                    })->count();
            }
        }

        $cards = [];

        if ($awaitingDeliveryCount > 0) {
            $singular = $this->tenant->asset_label_singular ?: 'job';
            $plural   = $this->tenant->asset_label_plural ?: 'jobs';
            $cards[] = [
                'count' => $awaitingDeliveryCount,
                'title' => 'Awaiting delivery',
                'key'   => 'awaiting_delivery',
                'icon'  => '🚚',
                'desc'  => ($awaitingDeliveryCount === 1
                        ? "1 completed {$singular} with no drop-off scheduled"
                        : "{$awaitingDeliveryCount} completed {$plural} with no drop-off scheduled")
                    . ($awaitingNoReplyCount > 0 ? " — {$awaitingNoReplyCount} never replied to the options link" : '')
                    . (($awaitingCallCount ?? 0) > 0 ? " — {$awaitingCallCount} asked for a call" : ''),
                'tone'  => 'amber',
                'link'  => route('tenant.appointments.index', ['filter' => 'awaiting_delivery']),
            ];
        }

        if ($unconfirmedCount > 0) {
            $cards[] = [
                'count' => $unconfirmedCount,
                'title' => 'Pending bookings',
                'key'   => 'pending_bookings',
                'icon'  => '🛎️',
                'desc'  => $unconfirmedCount === 1
                    ? '1 booking awaiting confirmation or drop-off'
                    : $unconfirmedCount . ' bookings awaiting confirmation or drop-off',
                'tone'  => 'red',  // your action: review and confirm
                'link'  => route('tenant.appointments.index', ['filter' => 'unconfirmed_bookings']),
            ];
        }

        // bookings that asked for pickup outreach
        if ($pickupOutreachCount > 0) {
            $cards[] = [
                'count' => $pickupOutreachCount,
                'title' => 'Pickup to arrange',
                'key'   => 'pickup_outreach',
                'icon'  => '🚚',
                'desc'  => $pickupOutreachCount === 1
                    ? '1 booking asked you to reach out about pickup'
                    : $pickupOutreachCount . ' bookings asked you to reach out about pickup',
                'tone'  => 'amber',  // your action: contact and schedule
                'link'  => route('tenant.appointments.index', ['filter' => 'pickup_outreach']),
            ];
        }

        if ($unpaidDoneCount > 0) {
            $cards[] = [
                'count' => $unpaidDoneCount,
                'title' => 'Unpaid completed jobs',
                'key'   => 'unpaid_completed',
                'icon'  => '💳',
                'desc'  => '$' . number_format($unpaidDoneSumCents / 100, 0) . ' outstanding on finished work',
                'tone'  => 'amber',  // customer's action: send payment
                'link'  => route('tenant.appointments.index', ['filter' => 'unpaid_completed']),
            ];
        }

        if ($readyPickupCount > 0) {
            $cards[] = [
                'count' => $readyPickupCount,
                'title' => 'Ready for pickup',
                'key'   => 'ready_pickup',
                'icon'  => '✅',
                'desc'  => $readyPickupCount === 1
                    ? 'Customer ready to receive their ' . ($this->tenant->asset_label_singular ?: 'item')
                    : 'Customers ready to receive their ' . ($this->tenant->asset_label_plural ?: 'items'),
                'tone'  => 'amber',  // customer's action: collect their item
                'link'  => route('tenant.appointments.index', ['filter' => 'ready_pickup']),
            ];
        }

        if ($waitlistCount > 0) {
            $cards[] = [
                'count' => $waitlistCount,
                'title' => 'Waitlist entries',
                'key'   => 'waitlist',
                'icon'  => '⏳',
                'desc'  => $waitlistCount === 1
                    ? 'Customer waiting for an opening'
                    : 'Customers waiting for an opening',
                'tone'  => 'amber',  // customer's action: accept the opening (waitlist page, not appointments)
                'link'  => route('tenant.waitlist.index'),
            ];
        }

        // ---- Overdue categories ----
        $overdueUnstartedCount = TenantAppointment::where('tenant_id', $tenantId)
            ->whereIn('status', AppointmentStatus::notStartedStatuses())
            ->whereDate('appointment_date', '<', $today)
            ->count();

        if ($overdueUnstartedCount > 0) {
            $cards[] = [
                'count' => $overdueUnstartedCount,
                'title' => 'Overdue: not started',
                'key'   => 'overdue_unstarted',
                'icon'  => '⏰',
                'desc'  => $overdueUnstartedCount === 1
                    ? 'Appointment past its scheduled date and never started'
                    : 'Appointments past their scheduled date and never started',
                'tone'  => 'red',
                'link'  => route('tenant.appointments.index', ['filter' => 'overdue_unstarted']),
            ];
        }

        $overdueInProgressCount = TenantAppointment::where('tenant_id', $tenantId)
            ->whereIn('status', AppointmentStatus::inProgressStatuses())
            ->whereDate('appointment_date', '<', $today)
            ->count();

        if ($overdueInProgressCount > 0) {
            $cards[] = [
                'count' => $overdueInProgressCount,
                'title' => 'Overdue: in progress',
                'key'   => 'overdue_in_progress',
                'icon'  => '🔧',
                'desc'  => $overdueInProgressCount === 1
                    ? 'Job started but not closed out'
                    : 'Jobs started but not closed out',
                'tone'  => 'red',  // your action: close out the job (more concerning than unstarted)
                'link'  => route('tenant.appointments.index', ['filter' => 'overdue_in_progress']),
            ];
        }

        $stalePickupCount = TenantAppointment::where('tenant_id', $tenantId)
            ->whereIn('status', AppointmentStatus::doneStatuses())
            ->whereIn('payment_status', ['unpaid', 'partial'])
            ->where('updated_at', '<', now()->subDays(3))
            ->count();

        if ($stalePickupCount > 0) {
            $cards[] = [
                'count' => $stalePickupCount,
                'title' => 'Stale pickups',
                'key'   => 'stale_pickups',
                'icon'  => '📅',
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
        // Needed: status=needed (soft request, not yet ordered from a vendor).
        $soNeededCount = \App\Models\Tenant\TenantSpecialOrder::where('tenant_id', $tenantId)
            ->where('status', \App\Models\Tenant\TenantSpecialOrder::STATUS_NEEDED)
            ->count();

        if ($soNeededCount > 0) {
            $cards[] = [
                'count' => $soNeededCount,
                'title' => 'Special orders to place',
                'key'   => 'so_needed',
                'icon'  => '🛒',
                'desc'  => $soNeededCount === 1
                    ? 'Customer part not yet ordered from a vendor'
                    : 'Customer parts not yet ordered from a vendor',
                'tone'  => 'amber',  // your action: pick a vendor + place the order
                'link'  => route('tenant.special-orders.index'),
            ];
        }

        $soArrivedCount = \App\Models\Tenant\TenantSpecialOrder::where('tenant_id', $tenantId)
            ->where('status', \App\Models\Tenant\TenantSpecialOrder::STATUS_ARRIVED)
            ->count();

        if ($soArrivedCount > 0) {
            $cards[] = [
                'count' => $soArrivedCount,
                'title' => 'Special orders arrived',
                'key'   => 'so_arrived',
                'icon'  => '📦',
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
                'key'   => 'so_overdue',
                'icon'  => '⚠️',
                'desc'  => $soOverdueCount === 1
                    ? 'Vendor missed expected arrival — chase them'
                    : 'Vendors missed expected arrivals — chase them',
                'tone'  => 'red',  // your action: contact vendor about delay
                'link'  => route('tenant.special-orders.index', ['view' => 'overdue']),
            ];
        }

        // patch-102 location-scoped transfer tiles — show two separate tiles
        // when relevant: items needing to be SENT FROM here, and items
        // currently IN TRANSIT TO here.
        $sessionLocId = session('current_location_id');

        if ($sessionLocId) {
            $toSendCount = \App\Models\Tenant\TenantTransferRequest::where('tenant_id', $tenantId)
                ->where('status', 'pending')
                ->where('from_location_id', $sessionLocId)
                ->count();

            if ($toSendCount > 0) {
                $cards[] = [
                    'count' => $toSendCount,
                    'title' => 'Transfers to send',
                    'key'   => 'transfers_to_send',
                    'icon'  => '📤',
                    'desc'  => $toSendCount === 1
                        ? 'Another location is asking for stock from here'
                        : 'Other locations are asking for stock from here',
                    'tone'  => 'amber',
                    'link'  => route('tenant.transfer-requests.index', ['view' => 'to_send']),
                ];
            }

            $toReceiveCount = \App\Models\Tenant\TenantTransferRequest::where('tenant_id', $tenantId)
                ->where('status', 'in_transit')
                ->where('to_location_id', $sessionLocId)
                ->count();

            if ($toReceiveCount > 0) {
                $cards[] = [
                    'count' => $toReceiveCount,
                    'title' => 'Transfers arriving',
                    'key'   => 'transfers_arriving',
                    'icon'  => '📥',
                    'desc'  => $toReceiveCount === 1
                        ? 'Stock is in transit to this location'
                        : 'Stock items are in transit to this location',
                    'tone'  => 'blue',
                    'link'  => route('tenant.transfer-requests.index', ['view' => 'to_receive']),
                ];
            }
        }

        // Low stock + Win-back triage rules
        // Both rules are tenant-scoped and use existing indexed columns.

        // Low stock: items at or below shop_reorder_threshold. Mirrors the
        // 'stock=low' filter on the inventory index. NULL threshold = item
        // isn't being tracked for reorder, so it's excluded.
        $lowStockCount = TenantInventoryItem::where('tenant_id', $tenantId)
            ->where('is_active', true)
            ->whereNotNull('shop_reorder_threshold')
            ->whereColumn('computed_stock_count', '<=', 'shop_reorder_threshold')
            ->count();

        if ($lowStockCount > 0) {
            $cards[] = [
                'count' => $lowStockCount,
                'title' => 'Low stock',
                'key'   => 'low_stock',
                'icon'  => '📉',
                'desc'  => $lowStockCount === 1
                    ? 'Item at or below its reorder threshold'
                    : 'Items at or below their reorder thresholds',
                'tone'  => 'amber',  // your action: plan replenishment
                'link'  => route('tenant.inventory.index', ['stock' => 'low']),
            ];
        }

        // catalog attention: open pricing/MAP/MSRP flags from
        // distributor sync. Same count as the Catalog attention page header.
        try {
            $catalogAttn = \App\Models\Tenant\TenantPricingAttentionFlag::query()
                ->where('tenant_id', $this->tenant->id)
                ->where('status', 'open')
                ->count();
        } catch (\Throwable $e) {
            $catalogAttn = 0;
        }

        if ($catalogAttn > 0) {
            $cards[] = [
                'count' => $catalogAttn,
                'title' => 'Catalog attention',
                'key'   => 'catalog_attention',
                'icon'  => '🏷️',
                'desc'  => $catalogAttn === 1
                    ? 'Price or MAP change from your distributor needs review'
                    : 'Price or MAP changes from your distributor need review',
                'tone'  => 'amber',
                'link'  => route('tenant.distributors.attention'),
            ];
        }

        // Win-back: customers lapsed 180+ days (had a delivered appointment
        // but not in 180+ days). Delegates to CustomersReportService for the
        // same definition as the Reports → Customers tab, so the numbers
        // agree across surfaces. aggregatesOnly skips the heavy list query.
        try {
            $lapsed = (new CustomersReportService($this->tenant))
                ->lapsedCustomers(aggregatesOnly: true);
            $winbackCount = (int) ($lapsed['lapsed_count'] ?? 0);
        } catch (\Throwable $e) {
            $winbackCount = 0;
        }

        if ($winbackCount > 0) {
            $cards[] = [
                'count' => $winbackCount,
                'title' => 'Win-back candidates',
                'key'   => 'win_back',
                'icon'  => '👋',
                'desc'  => $winbackCount === 1
                    ? 'Customer has not been in for 180+ days'
                    : 'Customers have not been in for 180+ days',
                'tone'  => 'violet',  // your action: start a re-engagement campaign
                'link'  => route('tenant.customers.index'),
            ];
        }

        // MARKER-CUSTOM-ITEMS: lines rung up by hand that nobody has sorted yet
        try {
            if (app(\App\Services\FeatureAccessService::class)->hasAddon($this->tenant, 'retail')) {
                $ci = (new CustomItemsService($this->tenant))->counts();
                if ($ci['open'] > 0) {
                    $cards[] = [
                        'count' => $ci['today'] > 0 ? $ci['today'] : $ci['open'],
                        'title' => $ci['today'] > 0 ? 'Custom items sold today' : 'Custom items to sort',
                        'key'   => 'custom_items',
                        'icon'  => '🏷️',
                        'desc'  => 'Add them to inventory so they scan next time'
                            . ($ci['today'] > 0 && $ci['earlier'] > 0 ? ' · ' . $ci['earlier'] . ' more from earlier' : ''),
                        'tone'  => 'amber',
                        'link'  => route('tenant.register.custom-items'),
                    ];
                }
            }
        } catch (\Throwable $e) {
            // the dashboard never fails over a count
        }

        return [
            'cards'       => $cards,
            'total_items' => count($cards),
        ];
    }

    public function zoneGrowth(): array
    {
        // match Reports' revenue definition:
        //   - status IN ('completed','closed') so only delivered work counts
        //   - 30-day window inclusive of today (Reports' last_30 uses the
        //     same subDays(29) bound).
        $tenantId = $this->tenant->id;
        $today = $this->tnow()->endOfDay();
        $thirtyAgo = $this->tnow()->subDays(29)->startOfDay();   // start of current 30d window
        $sixtyAgo  = $this->tnow()->subDays(59)->startOfDay();   // start of prior 30d window

        // revenue = payments received (sale ledger), matching
        // Reports. recorded_at is UTC; bound by tenant-local windows -> UTC.
        $tzG = $this->tenant->timezone();
        $curStart = $thirtyAgo->copy()->setTimezone($tzG)->startOfDay()->utc();
        $curEnd   = $today->copy()->setTimezone($tzG)->endOfDay()->utc();
        $priStart = $sixtyAgo->copy()->setTimezone($tzG)->startOfDay()->utc();
        $priEnd   = $thirtyAgo->copy()->subDay()->setTimezone($tzG)->endOfDay()->utc();

        $revenueCurrent = (int) \App\Models\Tenant\TenantSalePayment::where('tenant_id', $tenantId)
            ->whereBetween('recorded_at', [$curStart, $curEnd])
            ->sum('amount_cents');

        $revenuePrior = (int) \App\Models\Tenant\TenantSalePayment::where('tenant_id', $tenantId)
            ->whereBetween('recorded_at', [$priStart, $priEnd])
            ->sum('amount_cents');

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

    /**
     * MARKER-DASH-BRIEF: the next seven days for the Brief dashboard — each
     * day's opening hours (an override for that date beats the weekly rule)
     * and its bookings with start and length, for the day timeline.
     *
     * @return array{days: array<int,array>, start_hour: int, end_hour: int, now_hour: float, total: int}
     */
    public function weekAhead(): array
    {
        $tz    = $this->tenant->timezone();
        $now   = $this->tnow()->copy()->setTimezone($tz);
        $start = $now->copy()->startOfDay();
        $end   = $start->copy()->addDays(6);

        $appts = TenantAppointment::where('tenant_id', $this->tenant->id)
            ->whereBetween('appointment_date', [$start->toDateString(), $end->toDateString()])
            ->whereNotIn('status', AppointmentStatus::terminalStatuses())
            ->orderByRaw('appointment_time IS NULL, appointment_time ASC')
            ->with(['customer', 'items'])
            ->get()
            ->groupBy(fn ($a) => $a->appointment_date->toDateString());

        $rules     = TenantCapacityRule::where('tenant_id', $this->tenant->id)->get();
        $weekly    = $rules->where('rule_type', 'default')->keyBy('day_of_week');
        $overrides = $rules->where('rule_type', 'override')->filter(fn ($r) => $r->specific_date)
            ->keyBy(fn ($r) => $r->specific_date->toDateString());
        $hasHours  = $rules->isNotEmpty();
        $awaiting  = AppointmentStatus::awaitingStatuses();

        $toHour = function (?string $t): ?float {
            if (! $t) return null;
            [$h, $m] = array_pad(explode(':', $t), 2, 0);
            return (int) $h + ((int) $m) / 60;
        };

        $days = [];
        $lo = 24.0; $hi = 0.0; $total = 0;
        for ($i = 0; $i < 7; $i++) {
            $d    = $start->copy()->addDays($i);
            $key  = $d->toDateString();
            $rule = $overrides[$key] ?? $weekly[$d->dayOfWeek] ?? null;

            $closed = $hasHours && (! $rule || $rule->is_closed || (! $rule->open_time && ! $rule->close_time));
            $open   = $rule ? $toHour($rule->open_time) : null;
            $close  = $rule ? $toHour($rule->close_time) : null;
            if (! $closed && $open !== null && $close !== null && $close > $open) {
                $lo = min($lo, $open); $hi = max($hi, $close);
            }

            $bookings = [];
            $untimed  = [];
            foreach ($appts[$key] ?? [] as $a) {
                $first = optional($a->items->first())->item_name_snapshot;
                $more  = max(0, $a->items->count() - 1);
                $row = [
                    'who'      => $a->customerName() ?: 'Walk-in',
                    'what'     => $first ? $first . ($more ? ' +' . $more : '') : 'Booking',
                    'awaiting' => in_array($a->status, $awaiting, true),
                    'link'     => route('tenant.appointments.show', $a->id),
                ];
                $at = $toHour($a->appointment_time);
                if ($at === null) { $untimed[] = $row; continue; }

                $len = $a->total_duration_minutes ? $a->total_duration_minutes / 60 : null;
                if (! $len && $a->appointment_end_time && ($endH = $toHour($a->appointment_end_time)) > $at) {
                    $len = $endH - $at;
                }
                $len = max(0.5, $len ?: 1);
                $bookings[] = $row + ['at' => $at, 'len' => $len];
                $lo = min($lo, floor($at)); $hi = max($hi, ceil($at + $len));
            }
            $total += count($bookings) + count($untimed);

            $days[] = [
                'date'     => $key,
                'dow'      => $d->format('D'),
                'num'      => (int) $d->format('j'),
                'name'     => $i === 0 ? 'Today, ' . $d->format('l') : ($i === 1 ? 'Tomorrow, ' . $d->format('l') : $d->format('l, M j')),
                'is_today' => $i === 0,
                'closed'   => $closed,
                'open'     => $closed ? null : $open,
                'close'    => $closed ? null : $close,
                'bookings' => $bookings,
                'untimed'  => $untimed,
            ];
        }

        // no hours anywhere and nothing booked: a plain working day
        if ($lo >= $hi) { $lo = 8; $hi = 18; }

        return [
            'days'       => $days,
            'start_hour' => (int) floor($lo),
            'end_hour'   => (int) ceil($hi),
            'now_hour'   => $now->hour + $now->minute / 60,
            'total'      => $total,
        ];
    }

    /** MARKER-DASH-BRIEF: jobs completed in the last 30 days, and the 30 before. */
    public function jobsCompleted30(): array
    {
        $tz  = $this->tenant->timezone();
        $now = $this->tnow()->copy()->setTimezone($tz);
        $cur = [$now->copy()->subDays(29)->startOfDay()->utc(), $now->copy()->endOfDay()->utc()];
        $pri = [$now->copy()->subDays(59)->startOfDay()->utc(), $now->copy()->subDays(30)->endOfDay()->utc()];
        $q = fn ($w) => TenantAppointment::where('tenant_id', $this->tenant->id)
            ->whereIn('status', AppointmentStatus::doneStatuses())
            ->whereBetween('completed_at', $w)->count();
        $c = $q($cur); $p = $q($pri);
        return ['current' => $c, 'prior' => $p, 'delta_pct' => $p > 0 ? round(($c - $p) / $p * 100) : null];
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

    /**
     * Launcher tile sub-stats. One DB hit per stat where the data isn't
     * already in zoneToday/zoneAttention. Order matters — tiles render
     * in array order.
     *
     * Cheap stats only: counts and simple aggregates. Anything that would
     * require a join across 3+ tables stays static label-only for now.
     */
    public function zoneLauncher(array $today, array $attention): array
    {
        $tenantId = $this->tenant->id;
        $todayStr = $this->tnow()->toDateString();

        // Today's register total. Sums tenant_sales paid today.
        // paid_at is a UTC instant; whereDate() compared
        // its UTC date to the tenant-local date, so evening sales vanished
        // from today's tile. Compare against the tenant day's UTC range.
        [$dayStartUtc, $dayEndUtc] = tenant_day_utc_range($this->tnow());
        $todaySalesTotal = (int) DB::table('tenant_sales')
            ->where('tenant_id', $tenantId)
            ->where('paid_at', '>=', $dayStartUtc)
            ->where('paid_at', '<',  $dayEndUtc)
            ->where('payment_status', 'paid')
            ->sum('total_cents');

        // Customer count — single COUNT, cheap.
        $customerCount = (int) DB::table('tenant_customers')
            ->where('tenant_id', $tenantId)
            ->count();

        // Waitlist count — same query as zoneAttention waitlist card uses.
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

        // Inventory counts — active items, plus low-stock pulled from
        // already-computed attention cards if present.
        $activeItemsCount = (int) TenantInventoryItem::where('tenant_id', $tenantId)
            ->where('is_active', true)
            ->count();

        $lowStockCount = collect($attention['cards'] ?? [])
            ->firstWhere('title', 'Low stock')['count'] ?? 0;

        // Special order counts — pulled from existing attention cards.
        $soArrivedCount = collect($attention['cards'] ?? [])
            ->firstWhere('title', 'Special orders arrived')['count'] ?? 0;
        $soOverdueCount = collect($attention['cards'] ?? [])
            ->firstWhere('title', 'Special orders overdue')['count'] ?? 0;

        // Services count — active service items, single COUNT.
        $servicesCount = (int) TenantServiceItem::where('tenant_id', $tenantId)
            ->where('is_active', true)
            ->count();

        // Resources count — active resources.
        $resourcesCount = (int) TenantResource::where('tenant_id', $tenantId)
            ->where('is_active', true)
            ->count();

        // Staff count — tenant_users (excluding owner, if needed).
        $staffCount = (int) TenantUser::where('tenant_id', $tenantId)->count();

        // Published page count — same query zoneGrowth uses for health.
        $publishedPageCount = (int) \App\Models\Tenant\TenantPage::where('tenant_id', $tenantId)
            ->where('is_published', true)
            ->count();

        return [
            'calendar' => [
                'today_count' => $today['today_count'] ?? 0,
                'cap'         => null,  // Cap calculation deferred — needs resource summation.
            ],
            'register' => [
                'today_total_cents' => $todaySalesTotal,
            ],
            'customers' => [
                'count' => $customerCount,
            ],
            'waitlist' => [
                'count' => $waitlistCount,
            ],
            'inventory' => [
                'active_count'    => $activeItemsCount,
                'low_stock_count' => $lowStockCount,
            ],
            'special_orders' => [
                'arrived_count' => $soArrivedCount,
                'overdue_count' => $soOverdueCount,
            ],
            'services' => [
                'count' => $servicesCount,
            ],
            'resources' => [
                'count'       => $resourcesCount,
                'staff_count' => $staffCount,
            ],
            'pages' => [
                'published_count' => $publishedPageCount,
            ],
        ];
    }

    private function dailyRevenueSeries(string $tenantId, Carbon $from, Carbon $to): array
    {
        // daily revenue spark = payments received (ledger),
        // bucketed by recorded_at in tenant tz.
        $tzS = $this->tenant->timezone();
        // DST-correct per-row offset.
        $sparkStart = $from->copy()->setTimezone($tzS)->startOfDay()->utc();
        $sparkEnd   = $to->copy()->setTimezone($tzS)->endOfDay()->utc();
        [$tzExpr, $tzBind] = tenant_tz_offset_expr('recorded_at', $tzS, $sparkStart, $sparkEnd);
        $rows = \App\Models\Tenant\TenantSalePayment::where('tenant_id', $tenantId)
            ->whereBetween('recorded_at', [$sparkStart, $sparkEnd])
            ->selectRaw("DATE({$tzExpr}) as d, SUM(amount_cents) as cents", $tzBind)
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
