#!/bin/bash
# delivery-resolution — the "Awaiting delivery" tile gets a workflow.
#   THE PROBLEM: Skip on the completion modal literally just closed it —
#   nothing recorded — so the job landed on the tile and only left when the
#   14-day window forgot it. Skip was doing two different jobs at once:
#   "not now" and "this one is already handled".
#   AT THE SOURCE (completion modal), three outcomes instead of two:
#     · Text the options   — as before, in flight, still queued (correct)
#     · Not yet            — old Skip, honest: stays queued as "no contact yet"
#     · No delivery needed — records customer_pickup; never queues at all
#     Headline changed from "…delivery?" (invites yes/no) to "How is this
#     getting back to them?" so every button answers the real question.
#   IN THE QUEUE (appointments?filter=awaiting_delivery), a triage panel:
#     every row states WHY it is waiting (no contact yet / options sent /
#     no reply yet / replied, needs scheduling), how many days it has waited
#     (red past 10), and carries Open, Picked up, No delivery needed, and
#     Snooze 3d — each resolving inline with an Undo. The standard table is
#     hidden for this filter so jobs are not listed twice.
#   DATA: delivery_resolution + resolved_at + resolved_by + snooze_until on
#   tenant_appointments. The tile and the filter both exclude resolved jobs
#   and respect snoozes, so the count reflects decisions, not a timer.
#   Resolutions write a system note on the appointment.
#   NOTE: the 14-day window is deliberately KEPT for now — removing it would
#   surface every historical completed job at once. Ask before changing it.
# No new routes (ops ride the existing PATCH /appointments/{id} endpoint).
# Server: MIGRATION REQUIRED, then view:clear.
set -e
cd "$(git rev-parse --show-toplevel)"
if grep -q "MARKER-DELIVERY-RESOLUTION" app/Services/Tenant/DashboardDataService.php; then
  echo "delivery-resolution already applied — aborting."; exit 1
fi
if ! grep -q "MARKER-TZ-WAVE1" app/Services/Tenant/DashboardDataService.php; then
  echo "wrong base — aborting."; exit 1
fi

cat > 'database/migrations/2026_07_23_000002_add_delivery_resolution_to_tenant_appointments.php' <<'DELRES_0_EOF'
<?php

// MARKER-DELIVERY-RESOLUTION — a completed job leaves the "Awaiting delivery"
// queue because someone decided something, not because a 14-day window
// forgot about it. Records what was decided, by whom, and when.

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenant_appointments', function (Blueprint $t) {
            // customer_pickup | handed_over | not_needed  (null = still open)
            $t->string('delivery_resolution', 32)->nullable()->index();
            $t->timestamp('delivery_resolved_at')->nullable();
            $t->uuid('delivery_resolved_by_user_id')->nullable();
            // Actively being chased — hidden from the queue until this passes.
            $t->timestamp('delivery_snooze_until')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('tenant_appointments', function (Blueprint $t) {
            $t->dropColumn([
                'delivery_resolution',
                'delivery_resolved_at',
                'delivery_resolved_by_user_id',
                'delivery_snooze_until',
            ]);
        });
    }
};
DELRES_0_EOF

cat > 'app/Models/Tenant/TenantAppointment.php' <<'DELRES_1_EOF'
<?php
namespace App\Models\Tenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;
use App\Models\Tenant;
use App\Support\AppointmentStatus;

class TenantAppointment extends Model
{
    use HasUuids;
    protected $table    = 'tenant_appointments';
    protected $fillable = [
        'tenant_id','customer_id','resource_id','location_id','ra_number',
        'customer_first_name','customer_last_name','customer_email','customer_phone',
        'appointment_date','appointment_time','appointment_end_time',
        'promised_at', // MARKER-PATCH-311
        'pickup_outreach_pending', // MARKER-PICKUP-OUTREACH
        'delivery_resolution', 'delivery_resolved_at',            // MARKER-DELIVERY-RESOLUTION
        'delivery_resolved_by_user_id', 'delivery_snooze_until',  // MARKER-DELIVERY-RESOLUTION
        'total_duration_minutes','prep_before_minutes_snapshot','cleanup_after_minutes_snapshot',
        'slot_weight','slot_weight_auto','slot_weight_overridden',
        'receiving_method_snapshot','receiving_time_snapshot','tracking_number',
        'status','payment_status','payment_method',
        'stripe_payment_intent_id','paypal_order_id',
        'subtotal_cents','tax_cents','total_cents','paid_cents','staff_notes',
        'invoice_note','invoice_terms', // MARKER-PATCH-204
        'needs_time_review',
        'reminded_at', // MARKER-PATCH-154
        'completed_at', // MARKER-PATCH-481
    ];
    protected $casts = [
        'appointment_date'         => 'date',
        'total_duration_minutes'         => 'integer',
        'prep_before_minutes_snapshot'   => 'integer',
        'cleanup_after_minutes_snapshot' => 'integer',
        'slot_weight'                    => 'integer',
        'slot_weight_auto'         => 'integer',
        'slot_weight_overridden'   => 'boolean',
        'needs_time_review'        => 'boolean',
        'subtotal_cents'           => 'integer',
        'tax_cents'                => 'integer',
        'total_cents'              => 'integer',
        'paid_cents'               => 'integer',
        'reminded_at'              => 'datetime', // MARKER-PATCH-154
        'promised_at'              => 'datetime', // MARKER-PATCH-311
        'completed_at'             => 'datetime', // MARKER-PATCH-481
    ];

    // MARKER-PATCH-481 — stamp the actual completion instant once, on the first
    // transition into a done state, from any write path. Pairs with promised_at to
    // measure late_completion; never overwritten (records the first completion).
    protected static function booted(): void
    {
        static::saving(function (self $appt) {
            if (! $appt->completed_at
                && $appt->isDirty('status')
                && in_array($appt->status, ['completed', 'shipped', 'closed'], true)) {
                $appt->completed_at = tnow()->utc();
            }
        });

        // MARKER-PATCH-482 — once a completion is stamped, evaluate quality signals
        // (late_completion) for the customer's recovery history.
        static::saved(function (self $appt) {
            if ($appt->wasChanged('completed_at') && $appt->completed_at && $appt->customer_id) {
                app(\App\Services\Tenant\RecoverySignalService::class)->evaluate($appt);
            }
        });

        // MARKER-PATCH-485 — a shop-side date move on a live appointment (one the
        // customer was already expecting) is a reschedule signal. New bookings and
        // pending/cancelled rows don't count.
        static::saved(function (self $appt) {
            if ($appt->wasChanged('appointment_date')
                && ! $appt->wasRecentlyCreated
                && $appt->customer_id
                && ! in_array($appt->status, ['pending', 'cancelled', 'refunded'], true)) {
                app(\App\Services\Tenant\RecoverySignalService::class)
                    ->reschedule($appt, $appt->getOriginal('appointment_date'));
            }
        });
    }

    public function tenant(): BelongsTo    { return $this->belongsTo(Tenant::class); }
    public function customer(): BelongsTo  { return $this->belongsTo(TenantCustomer::class, 'customer_id'); }
    public function resource(): BelongsTo  { return $this->belongsTo(TenantResource::class, 'resource_id'); }
    public function location(): BelongsTo  { return $this->belongsTo(TenantLocation::class, 'location_id'); }
    public function items(): HasMany       { return $this->hasMany(TenantAppointmentItem::class, 'appointment_id'); }
    public function addons(): HasMany      { return $this->hasMany(TenantAppointmentAddon::class, 'appointment_id'); }

    // MARKER-PATCH-158-A — multi-asset support
    public function assets(): HasMany
    {
        return $this->hasMany(TenantAppointmentAsset::class, 'appointment_id')->orderBy('sort_order');
    }
    public function parts(): HasMany       { return $this->hasMany(TenantAppointmentPart::class, 'appointment_id'); }
    public function responses(): HasMany   { return $this->hasMany(TenantAppointmentResponse::class, 'appointment_id'); }
    public function notes(): HasMany       { return $this->hasMany(TenantAppointmentNote::class, 'appointment_id')->orderBy('created_at'); }
    public function charges(): HasMany     { return $this->hasMany(TenantAppointmentCharge::class, 'appointment_id'); }
    // MARKER-PATCH-176 — payments now live on the linked SALE ledger (sales-as-
    // money). An appointment reaches its payments THROUGH its sale(s):
    // appointment_id on tenant_sales, sale_id on tenant_sale_payments. Same row
    // shape (kind/amount_cents/recorded_at) so existing reads keep working.
    public function payments(): \Illuminate\Database\Eloquent\Relations\HasManyThrough
    {
        return $this->hasManyThrough(
            TenantSalePayment::class,
            TenantSale::class,
            'appointment_id', // FK on tenant_sales -> appointments
            'sale_id',        // FK on tenant_sale_payments -> sales
            'id',             // local key on appointments
            'id'              // local key on sales
        )->orderBy('tenant_sale_payments.recorded_at');
    }
    public function sales(): HasMany       { return $this->hasMany(TenantSale::class, 'appointment_id'); }
    public function specialOrders(): HasMany { return $this->hasMany(TenantSpecialOrder::class, 'appointment_id'); }

    public function scopeActive($q)        { return $q->whereNotIn('status', AppointmentStatus::terminalStatuses()); }
    public function customerName(): string
    {
        // MARKER-PATCH-421 — live customer via customer_id is the source of truth;
        // the snapshot is only a fallback for a deleted customer record.
        return $this->customer
            ? trim($this->customer->first_name . ' ' . $this->customer->last_name)
            : trim(($this->customer_first_name ?? '') . ' ' . ($this->customer_last_name ?? ''));
    }
    public function isPaid(): bool         { return $this->payment_status === 'paid'; }

    public function customerVisibleMinutes(): int
    {
        $total = 0;
        foreach ($this->items as $item) {
            $total += (int) ($item->duration_minutes_snapshot ?? 0);
        }
        foreach ($this->addons as $addon) {
            $total += (int) ($addon->duration_minutes_snapshot ?? 0);
        }
        return $total;
    }

    public static function generateRaNumber(string $tenantId, ?string $appointmentDate = null): string
    {
        $date = $appointmentDate ? new \DateTimeImmutable($appointmentDate) : new \DateTimeImmutable('today');
        $datePart = $date->format('mdy');
        $alphabet = 'ABCDEFGHJKMNPQRSTUVWXYZ23456789';

        for ($attempt = 0; $attempt < 6; $attempt++) {
            $random = '';
            for ($i = 0; $i < 5; $i++) {
                $random .= $alphabet[random_int(0, strlen($alphabet) - 1)];
            }
            $candidate = "ITO-{$datePart}-{$random}";
            $exists = static::where('tenant_id', $tenantId)->where('ra_number', $candidate)->exists();
            if (!$exists) return $candidate;
        }
        throw new \RuntimeException('Could not generate a unique RA number after 6 attempts.');
    }

    public function workOrderResponses()
    {
        return $this->hasMany(TenantAppointmentWorkOrderResponse::class, 'appointment_id');
    }

    public function workOrderFields()
    {
        return $this->hasMany(TenantWorkOrderField::class, 'tenant_id', 'tenant_id')
            ->orderBy('sort_order');
    }

    /**
     * Sum the ledger. Authoritative — paid_cents column is just a cache.
     * Use this when you need to be sure (e.g. in the status hook).
     */
    public function paidCentsFromLedger(): int
    {
        // Allow callers to use already-loaded relation without an extra query.
        if ($this->relationLoaded('payments')) {
            return (int) $this->payments->sum('amount_cents');
        }
        return (int) $this->payments()->sum('amount_cents');
    }

    /**
     * What the customer still owes. Negative means tenant owes customer (overage).
     * Reads from cached paid_cents — load payments and call paidCentsFromLedger()
     * if you need ledger-truth.
     */
    public function balanceDueCents(): int
    {
        return (int) $this->total_cents - (int) $this->paid_cents;
    }

    /**
     * Whether there's an active (non-voided) register sale tied to this
     * appointment. If true, the appointment is locked from edits.
     *
     * "Active" = sale exists, status is not 'cancelled'.
     */
    public function hasActiveRegisterSale(): bool
    {
        if ($this->relationLoaded('sales')) {
            return $this->sales->where('status', '!=', 'cancelled')->isNotEmpty();
        }
        return $this->sales()->where('status', '!=', 'cancelled')->exists();
    }

    /**
     * The single open draft sale created by the auto-send-on-Completed flow.
     * Returns null if there is no sale, or if the sale is closed/paid.
     *
     * Uses the SaleService convention: drafts have payment_status='draft'.
     */
    public function openRegisterSale(): ?TenantSale
    {
        return $this->sales()
            ->whereNotIn('status', ['cancelled', 'closed'])
            ->where('payment_status', 'draft')
            ->latest('created_at')
            ->first();
    }

    /**
     * MARKER-PATCH-194 — a live payment-link sale awaiting the customer:
     * has a Stripe checkout session, not yet paid, not cancelled. Drives the
     * "payment pending" banner so a link sent from this appointment is visible
     * and trackable instead of floating until it resolves.
     */
    public function pendingPaymentLinkSale(): ?TenantSale
    {
        return $this->sales()
            ->whereNotNull('checkout_session_id')
            ->whereNotIn('status', ['cancelled', 'closed', 'completed'])
            ->whereNotIn('payment_status', ['paid', 'refunded'])
            ->latest('created_at')
            ->first();
    }

}
DELRES_1_EOF

cat > 'app/Services/Tenant/DashboardDataService.php' <<'DELRES_2_EOF'
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
use App\Models\Tenant\TenantInventoryItem;  // MARKER-PATCH-110-STEP-1
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
            // MARKER-PATCH-362 — appointment_time is naive tenant-local wall-clock;
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
        // MARKER-PATCH-185 — week revenue = payments received (sale ledger).
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

        // MARKER-PATCH-183 — today's deliveries for the dashboard mini-section.
        $todayDeliveries = collect();
        try {
            $todayDeliveries = (new \App\Services\Tenant\TenantDeliveryService($this->tenant))
                ->forDay($this->tnow());
        } catch (\Throwable $e) {
            $todayDeliveries = collect();
        }

        return [
            'appointments'        => $todayAppointments,
            'today_count'         => $todayAppointments->count(),
            'today_deliveries'    => $todayDeliveries,
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
            ->whereIn('status', AppointmentStatus::awaitingStatuses())
            ->whereDate('appointment_date', '>=', $today)
            ->count();

        // MARKER-PICKUP-OUTREACH
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

        // MARKER-PATCH-539 — completed jobs with no scheduled drop-off (P&D tenants).
        // No-reply proposals (customer never picked from the options link) called out.
        $awaitingDeliveryCount = 0;
        $awaitingNoReplyCount  = 0;
        if ($this->tenant->deliveries_enabled) {
            $base = TenantAppointment::query()
                ->where('tenant_appointments.tenant_id', $tenantId)
                ->where('tenant_appointments.status', 'completed')
                ->whereNotNull('tenant_appointments.completed_at')
                ->where('tenant_appointments.completed_at', '>=', now()->subDays(14))
                // MARKER-DELIVERY-RESOLUTION — a decided job is gone from the
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
            }
        }

        $cards = [];

        if ($awaitingDeliveryCount > 0) {
            $singular = $this->tenant->asset_label_singular ?: 'job';   // MARKER-PATCH-539
            $plural   = $this->tenant->asset_label_plural ?: 'jobs';
            $cards[] = [
                'count' => $awaitingDeliveryCount,
                'title' => 'Awaiting delivery',
                'key'   => 'awaiting_delivery',
                'icon'  => '🚚',
                'desc'  => ($awaitingDeliveryCount === 1
                        ? "1 completed {$singular} with no drop-off scheduled"
                        : "{$awaitingDeliveryCount} completed {$plural} with no drop-off scheduled")
                    . ($awaitingNoReplyCount > 0 ? " — {$awaitingNoReplyCount} never replied to the options link" : ''),
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

        // MARKER-PICKUP-OUTREACH — bookings that asked for pickup outreach
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
        // MARKER-PATCH-422 — Needed: status=needed (soft request, not yet ordered from a vendor).
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

        // MARKER-PATCH-110-STEP-2 — Low stock + Win-back triage rules
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

        // MARKER-PATCH-609 — catalog attention: open pricing/MAP/MSRP flags from
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

        return [
            'cards'       => $cards,
            'total_items' => count($cards),
        ];
    }

    public function zoneGrowth(): array
    {
        // MARKER-PATCH-115 — match Reports' revenue definition:
        //   - status IN ('completed','closed') so only delivered work counts
        //   - 30-day window inclusive of today (Reports' last_30 uses the
        //     same subDays(29) bound).
        $tenantId = $this->tenant->id;
        $today = $this->tnow()->endOfDay();
        $thirtyAgo = $this->tnow()->subDays(29)->startOfDay();   // start of current 30d window
        $sixtyAgo  = $this->tnow()->subDays(59)->startOfDay();   // start of prior 30d window

        // MARKER-PATCH-185 — revenue = payments received (sale ledger), matching
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
     * MARKER-PATCH-110-STEP-3
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
        // MARKER-TZ-WAVE1 — paid_at is a UTC instant; whereDate() compared
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
        // MARKER-PATCH-185 — daily revenue spark = payments received (ledger),
        // bucketed by recorded_at in tenant tz.
        $tzS = $this->tenant->timezone();
        // MARKER-TZ-WAVE4 — DST-correct per-row offset.
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
            ->whereNotIn('status', AppointmentStatus::terminalStatuses())
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
                ->whereNotIn('status', AppointmentStatus::terminalStatuses())
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
DELRES_2_EOF

cat > 'app/Http/Controllers/Tenant/AppointmentController.php' <<'DELRES_3_EOF'
<?php

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Support\AppointmentStatus;
use App\Models\Tenant\TenantAppointment;
use App\Models\Tenant\TenantAppointmentNote;
use App\Models\Tenant\TenantAppointmentCharge;
use App\Models\Tenant\TenantAppointmentPart;
use App\Models\Tenant\TenantCustomer;
use App\Models\Tenant\TenantInventoryItem;
use App\Services\Tenant\AppointmentInventoryService;
use App\Services\Tenant\AppointmentRegisterBridgeService;
use App\Services\Tenant\DeliveryProposalService; // MARKER-PATCH-527
use App\Services\Tenant\InventoryStockException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class AppointmentController extends Controller
{
    public function __construct(
        protected AppointmentInventoryService $appointmentInventory,
        protected AppointmentRegisterBridgeService $registerBridge,
    ) {}

    // Active statuses can move freely between each other (forward or backward).
    // Terminal statuses (cancelled/refunded) can only be reopened to pending.
    // The UI is responsible for confirming destructive or backward moves;
    // this controller only enforces "is the target status valid at all?"
    // MARKER-PATCH-287 — status transitions/labels/destructive now live in the
    // single source: App\Support\AppointmentStatus. (Dead ACTIVE/TERMINAL consts removed.)

    public function index(Request $request)
    {
        $tenant = tenant();

        if ($request->has('detail') && ($request->expectsJson() || $request->ajax())) {
            return $this->jsonDetail($tenant, $request->input('detail'));
        }

        $search   = $request->input('s', '');
        $status   = $request->input('status', '');
        $payment  = $request->input('payment', '');
        $dateFrom = $request->input('date_from', '');
        $dateTo   = $request->input('date_to', '');
        $filter      = $request->input('filter', '');
        $resourceId  = $request->input('resource_id', ''); // MARKER-PATCH-113
        $sort        = $request->input('sort', 'date_desc');
        $page     = max(1, (int) $request->input('page', 1));
        $perPage  = 25;

        // Mapping from dashboard "Needs your attention" card slugs to query scopes.
        // Keep in sync with DashboardDataService::zoneAttention(). Each slug here
        // mirrors a card on the dashboard so clicking the card lands here filtered.
        $filterLabels = [
            'unconfirmed_bookings' => 'Unconfirmed bookings',
            'pickup_outreach' => 'Pickup to arrange', // MARKER-PICKUP-OUTREACH
            'unpaid_completed'     => 'Unpaid completed jobs',
            'ready_pickup'         => 'Ready for pickup',
            'overdue_unstarted'    => 'Overdue: not started',
            'overdue_in_progress'  => 'Overdue: in progress',
            'stale_pickups'        => 'Stale pickups',
            'awaiting_delivery'    => 'Awaiting delivery', // MARKER-PATCH-539
        ];
        $filter = array_key_exists($filter, $filterLabels) ? $filter : '';

        $q = TenantAppointment::where('tenant_id', $tenant->id);

        // Apply the high-level filter slug from the dashboard cards.
        $today = now($tenant->timezone())->toDateString();
        switch ($filter) {
            case 'unconfirmed_bookings':
                $q->whereIn('status', AppointmentStatus::awaitingStatuses())
                  ->whereDate('appointment_date', '>=', $today);
                break;
            case 'pickup_outreach': // MARKER-PICKUP-OUTREACH
                $q->where('pickup_outreach_pending', true);
                break;
            case 'unpaid_completed':
                $q->whereIn('status', AppointmentStatus::doneStatuses())
                  ->whereIn('payment_status', ['unpaid', 'partial']);
                break;
            case 'ready_pickup':
                $q->whereIn('status', AppointmentStatus::doneStatuses())
                  ->whereIn('payment_status', ['unpaid', 'partial']);
                break;
            case 'overdue_unstarted':
                $q->whereIn('status', AppointmentStatus::notStartedStatuses())
                  ->whereDate('appointment_date', '<', $today);
                break;
            case 'overdue_in_progress':
                $q->whereIn('status', AppointmentStatus::inProgressStatuses())
                  ->whereDate('appointment_date', '<', $today);
                break;
            case 'stale_pickups':
                $q->whereIn('status', AppointmentStatus::doneStatuses())
                  ->whereIn('payment_status', ['unpaid', 'partial'])
                  ->where('updated_at', '<', now()->subDays(3));
                break;
            case 'awaiting_delivery': // MARKER-PATCH-539 — mirrors DashboardDataService card
                $q->where('status', 'completed')
                  ->whereNotNull('completed_at')
                  ->where('completed_at', '>=', now()->subDays(14))
                  ->whereNotExists(function ($sub) {
                      $sub->selectRaw('1')
                          ->from('tenant_deliveries')
                          ->whereColumn('tenant_deliveries.appointment_id', 'tenant_appointments.id')
                          ->where('tenant_deliveries.type', 'dropoff')
                          ->where('tenant_deliveries.status', '!=', 'cancelled');
                  });
                  // MARKER-DELIVERY-RESOLUTION — mirror the tile exactly
                $q->whereNull('delivery_resolution')
                  ->where(function ($w) {
                      $w->whereNull('delivery_snooze_until')
                        ->orWhere('delivery_snooze_until', '<=', now());
                  });
                break;
        }

        if ($search) {
            $q->where(function ($q2) use ($search) {
                $q2->where('ra_number', 'like', "%{$search}%")
                   ->orWhere('customer_first_name', 'like', "%{$search}%")
                   ->orWhere('customer_last_name', 'like', "%{$search}%")
                   ->orWhere('customer_email', 'like', "%{$search}%");
            });
        }
        if ($status)     $q->where('status', $status);
        if ($payment)    $q->where('payment_status', $payment);
        if ($dateFrom)   $q->where('appointment_date', '>=', $dateFrom);
        if ($dateTo)     $q->where('appointment_date', '<=', $dateTo);
        if ($resourceId) $q->where('resource_id', $resourceId);

        // Sort
        switch ($sort) {
            case 'date_asc':
                $q->orderBy('appointment_date')->orderBy('created_at');
                break;
            case 'name_asc':
                $q->orderBy('customer_last_name')->orderBy('customer_first_name');
                break;
            case 'name_desc':
                $q->orderByDesc('customer_last_name')->orderByDesc('customer_first_name');
                break;
            case 'status':
                $q->orderByRaw("FIELD(status,'pending','confirmed','in_progress','completed','shipped','closed','cancelled','refunded')")->orderByDesc('appointment_date');
                break;
            case 'total_desc':
                $q->orderByDesc('total_cents')->orderByDesc('appointment_date');
                break;
            case 'total_asc':
                $q->orderBy('total_cents')->orderByDesc('appointment_date');
                break;
            default: // date_desc
                $q->orderByDesc('appointment_date')->orderByDesc('created_at');
                break;
        }

        $total = $q->count();
        // Resolve the active resource filter for display (name + color in chip).
        $resourceFilter = null;
        if ($resourceId) {
            $resourceFilter = \App\Models\Tenant\TenantResource::where('tenant_id', $tenant->id)
                ->where('id', $resourceId)
                ->first(['id', 'name', 'color_hex']);
        }

        $appointments = $q->with(['resource:id,name,color_hex', 'customer'])
                          ->offset(($page - 1) * $perPage)
                          ->limit($perPage)
                          ->get();

        $totalPages = max(1, ceil($total / $perPage));

        // MARKER-DELIVERY-RESOLUTION — why each job is still waiting, for the
        // triage panel. One query, no N+1.
        $deliveryWhy = [];
        if ($filter === 'awaiting_delivery' && $appointments->isNotEmpty()) {
            $props = \App\Models\Tenant\TenantDeliveryProposal::where('tenant_id', $tenant->id)
                ->whereIn('appointment_id', $appointments->pluck('id'))
                ->orderBy('created_at')
                ->get(['appointment_id', 'status']);
            foreach ($props as $p) {
                $deliveryWhy[$p->appointment_id] = $p->status; // last one wins
            }
        }

        // Active resources for the inline-edit dropdown on the table.
        // Cheap query; tenants typically have <20 active resources.
        $resources = \App\Models\Tenant\TenantResource::where('tenant_id', $tenant->id)
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get(['id', 'name', 'color_hex']);

        return view('tenant.appointments.index', compact(
            'appointments', 'total', 'page', 'totalPages',
            'search', 'status', 'payment', 'dateFrom', 'dateTo', 'sort',
            'filter', 'filterLabels', 'resources', 'resourceFilter',
            'deliveryWhy' // MARKER-DELIVERY-RESOLUTION
        ));
    }

    public function store(Request $request)
    {
        $tenant = tenant();

        if ($request->has('update')) {
            return $this->handleUpdate($tenant, $request->input('update'), $request);
        }

        $data = $request->validate([
            'customer_id'         => ['nullable', 'string', 'uuid'],
            'customer_first_name' => ['required_without:customer_id', 'string', 'max:100'],
            'customer_last_name'  => ['required_without:customer_id', 'string', 'max:100'],
            'customer_email'      => ['required_without:customer_id', 'email', 'max:255'],
            'customer_phone'      => ['nullable', 'string', 'max:32'],
            'appointment_date'    => ['required', 'date'],
            'appointment_time'    => ['nullable', 'string'],
            'resource_id'         => ['nullable', 'string', 'uuid'],
            'staff_notes'         => ['nullable', 'string', 'max:1000'],
            'items'               => ['required', 'array', 'min:1'],
            'items.*.service_item_id'      => ['required', 'string', 'uuid'],
            'items.*.price_override_cents' => ['nullable', 'integer', 'min:0'],
        ]);

        // If customer_id provided, hydrate name/email/phone from the existing record.
        $first = $data['customer_first_name'] ?? '';
        $last  = $data['customer_last_name']  ?? '';
        $email = $data['customer_email']      ?? '';
        $phone = $data['customer_phone']      ?? null;

        if (!empty($data['customer_id'])) {
            $existing = TenantCustomer::where('tenant_id', $tenant->id)
                ->where('id', $data['customer_id'])
                ->first();
            if ($existing) {
                $first = $existing->first_name ?: $first;
                $last  = $existing->last_name  ?: $last;
                $email = $existing->email      ?: $email;
                $phone = $existing->phone      ?: $phone;
            }
        }

        if (!$email) {
            return response()->json(['ok' => false, 'errors' => ['customer_email' => ['Email is required.']]], 422);
        }

        // Time defaults to noon if not provided (date-only flow).
        $apptTime = !empty($data['appointment_time'])
            ? (strlen($data['appointment_time']) === 5 ? $data['appointment_time'] . ':00' : $data['appointment_time'])
            : '12:00:00';

        $payload = [
            'first_name'       => $first,
            'last_name'        => $last,
            'email'            => $email,
            'phone'            => $phone,
            'date'             => $data['appointment_date'],
            'appointment_time' => $apptTime,
            'resource_id'      => $data['resource_id'] ?? null,
            // MARKER-PATCH-519 — P&D fields; createAppointment re-validates both.
            'route_window_id'  => $request->input('route_window_id') ?: null,
            // MARKER-PICKUP-OUTREACH — assigning a window resolves the outreach flag downstream
            'need_by'          => $request->input('need_by') ?: null,
            'items'            => array_map(function ($item) {
                return [
                    'service_item_id'      => $item['service_item_id'],
                    'price_override_cents' => $item['price_override_cents'] ?? null,
                    'addon_ids'            => [],
                ];
            }, $data['items']),
            'payment_method'   => 'none',
        ];

        try {
            $appointment = app(\App\Services\BookingService::class)
                ->createAppointment($payload, $tenant->id);
        } catch (\App\Exceptions\LockAcquisitionException $e) {
            return response()->json([
                'ok'      => false,
                'code'    => 'lock_timeout',
                'message' => 'Could not hold the slot. Try again.',
            ], 409);
        } catch (\RuntimeException $e) {
            return response()->json([
                'ok'      => false,
                'message' => $e->getMessage(),
            ], 422);
        }

        // Persist staff notes via TenantAppointmentNote — same as old flow.
        if (!empty($data['staff_notes'])) {
            \App\Models\Tenant\TenantAppointmentNote::create([
                'appointment_id'      => $appointment->id,
                'user_id'             => Auth::guard('tenant')->id(),
                'note_type'           => 'manual',
                'is_customer_visible' => false,
                'note_content'        => $data['staff_notes'],
                'created_at'          => now(),
            ]);
        }

        if ($request->expectsJson()) {
            return response()->json([
                'ok'       => true,
                'id'       => $appointment->id,
                'ra'       => $appointment->ra_number,
                'redirect' => route('tenant.appointments.show', [
                    'id'        => $appointment->id,
                ]),
            ]);
        }

        return redirect()->route('tenant.appointments.show', [
            'id'        => $appointment->id,
        ])->with('success', 'Appointment created.');
    }

    /**
     * JSON endpoint that powers the create-appointment modal.
     *
     * Modes:
     *   - Default: services + customers + resources (full picker setup)
     *   - With service_ids[]: ALSO returns next-available + per-resource
     *     alternatives via BookingService availability methods
     */
    public function pickerData(Request $request)
    {
        $tenant = tenant();
        $search = trim((string) $request->query('q', ''));

        $services = \App\Models\Tenant\TenantServiceItem::where('tenant_id', $tenant->id)
            ->where('is_active', true)
            ->orderBy('sort_order')->orderBy('name')
            ->get(['id', 'name', 'duration_minutes', 'price_cents',
                   'prep_before_minutes', 'cleanup_after_minutes']);

        $customersQuery = TenantCustomer::where('tenant_id', $tenant->id);
        if ($search !== '') {
            $customersQuery->where(function ($q) use ($search) {
                $q->where('first_name', 'like', "%{$search}%")
                  ->orWhere('last_name',  'like', "%{$search}%")
                  ->orWhere('email',      'like', "%{$search}%")
                  ->orWhere('phone',      'like', "%{$search}%");
            });
        }
        $customers = $customersQuery
            ->orderBy('last_name')->orderBy('first_name')
            ->limit(15)
            ->get(['id', 'first_name', 'last_name', 'email', 'phone']);

        $resources = \App\Models\Tenant\TenantResource::where('tenant_id', $tenant->id)
            ->where('is_active', true)
            ->orderBy('sort_order')->orderBy('name')
            ->get(['id', 'name', 'subtitle']);

        $availability = null;
        $serviceIds = (array) $request->query('service_ids', []);
        $serviceIds = array_values(array_filter($serviceIds, fn($id) => is_string($id) && $id !== ''));

        if (!empty($serviceIds)) {
            $picked = $services->whereIn('id', $serviceIds);
            $required = 0;
            foreach ($picked as $svc) {
                $required += (int) ($svc->prep_before_minutes ?? 0)
                           + (int) ($svc->duration_minutes ?? 0)
                           + (int) ($svc->cleanup_after_minutes ?? 0);
            }

            if ($required > 0) {
                $bookingService = app(\App\Services\BookingService::class);
                $earliest = $bookingService->nextAvailableSlot($tenant, $required, null);
                $perResource = $bookingService->nextAvailablePerResource($tenant, $required);

                $availability = [
                    'required_minutes' => $required,
                    'earliest'         => $earliest,
                    'per_resource'     => $perResource,
                ];
            }
        }

        return response()->json([
            'services'     => $services,
            'customers'    => $customers,
            'resources'    => $resources,
            'availability' => $availability,
        ]);
    }

    /**
     * SEQUENTIAL-PICKER-ENDPOINTS v1
     *
     * Returns the active resources eligible to perform a given service.
     * If the service has no eligibility rows, all active resources qualify.
     * Used by the rebuilt big-modal sequential picker (service → resource → times).
     */
    public function eligibleResources(Request $request)
    {
        $tenant = tenant();
        $serviceId = (string) $request->query('service_id', '');
        if ($serviceId === '') {
            return response()->json(['resources' => []]);
        }

        $bookingService = app(\App\Services\BookingService::class);
        $eligibleIds = $bookingService->eligibleResourcesForService($tenant->id, $serviceId);

        if (empty($eligibleIds)) {
            return response()->json(['resources' => []]);
        }

        $resources = \App\Models\Tenant\TenantResource::where('tenant_id', $tenant->id)
            ->whereIn('id', $eligibleIds)
            ->where('is_active', true)
            ->orderBy('sort_order')->orderBy('name')
            ->get(['id', 'name', 'subtitle']);

        return response()->json(['resources' => $resources]);
    }

    /**
     * Returns up to 7 days of available time slots starting from start_date.
     * Each result is a flat list across the week. The frontend paginates by
     * advancing/retreating start_date by 7 days on prev/next clicks.
     *
     * Required query params:
     *   service_id    — single service UUID (single-service modal at launch)
     *   resource_id   — single resource UUID (selected by user)
     *   start_date    — YYYY-MM-DD; results begin on this date
     *
     * Response:
     *   {
     *     slots: [{date: "YYYY-MM-DD", time: "HH:MM", date_label: "...", time_label: "..."}],
     *     required_minutes: int,
     *     start_date: "YYYY-MM-DD",
     *     end_date: "YYYY-MM-DD"
     *   }
     */
    public function weekTimes(Request $request)
    {
        $tenant = tenant();
        $serviceId  = (string) $request->query('service_id', '');
        $resourceId = (string) $request->query('resource_id', '');
        $startDate  = (string) $request->query('start_date', now()->toDateString());

        if ($serviceId === '' || $resourceId === '') {
            return response()->json(['slots' => [], 'required_minutes' => 0]);
        }

        $svc = \App\Models\Tenant\TenantServiceItem::where('tenant_id', $tenant->id)
            ->where('id', $serviceId)
            ->first(['duration_minutes', 'prep_before_minutes', 'cleanup_after_minutes']);

        if (!$svc) {
            return response()->json(['slots' => [], 'required_minutes' => 0]);
        }

        $required = (int) ($svc->prep_before_minutes ?? 0)
                  + (int) ($svc->duration_minutes ?? 0)
                  + (int) ($svc->cleanup_after_minutes ?? 0);

        if ($required === 0) {
            return response()->json(['slots' => [], 'required_minutes' => 0]);
        }

        $bookingService = app(\App\Services\BookingService::class);

        $minNoticeHours = (int) ($tenant->min_notice_hours ?? 0);
        $cutoff = now()->addHours($minNoticeHours);

        $slots = [];
        $cursor = \Carbon\Carbon::parse($startDate);
        $endDate = $cursor->copy()->addDays(6);

        for ($i = 0; $i < 7; $i++) {
            $dateStr = $cursor->toDateString();
            $times = $bookingService->availableSlotsForDate($tenant, $dateStr, $resourceId, $required);

            // For today, drop any slots earlier than the min-notice cutoff.
            if ($cursor->isToday() && $minNoticeHours > 0) {
                $cutoffHi = $cutoff->format('H:i');
                $times = array_values(array_filter($times, fn($t) => $t >= $cutoffHi));
            }
            // Past dates: skip entirely.
            if ($cursor->isPast() && !$cursor->isToday()) {
                $cursor->addDay();
                continue;
            }

            $dateLabel = $cursor->format('D, M j');
            foreach ($times as $t) {
                $slots[] = [
                    'date'       => $dateStr,
                    'time'       => $t,
                    'date_label' => $dateLabel,
                    'time_label' => self::formatTimeLabel($t),
                ];
            }
            $cursor->addDay();
        }

        return response()->json([
            'slots'            => $slots,
            'required_minutes' => $required,
            'start_date'       => $startDate,
            'end_date'         => $endDate->toDateString(),
        ]);
    }

    private static function formatTimeLabel(string $hi): string
    {
        // "14:30" → "2:30 PM"
        $parts = explode(':', $hi);
        if (count($parts) < 2) return $hi;
        $h = (int) $parts[0];
        $m = $parts[1];
        $ampm = $h >= 12 ? 'PM' : 'AM';
        $h12 = $h % 12 === 0 ? 12 : $h % 12;
        $minPart = $m === '00' ? '' : ':' . $m;
        return $h12 . $minPart . ' ' . $ampm;
    }

    public function dayStrip(Request $request)
    {
        $tenant = tenant();

        $serviceIds = (array) $request->query('service_ids', []);
        $serviceIds = array_values(array_filter($serviceIds, fn($id) => is_string($id) && $id !== ''));

        if (empty($serviceIds)) {
            return response()->json(['days' => [], 'required_minutes' => 0]);
        }

        $services = \App\Models\Tenant\TenantServiceItem::where('tenant_id', $tenant->id)
            ->whereIn('id', $serviceIds)
            ->get(['duration_minutes', 'prep_before_minutes', 'cleanup_after_minutes']);

        $required = 0;
        foreach ($services as $svc) {
            $required += (int) ($svc->prep_before_minutes ?? 0)
                       + (int) ($svc->duration_minutes ?? 0)
                       + (int) ($svc->cleanup_after_minutes ?? 0);
        }

        if ($required === 0) {
            return response()->json(['days' => [], 'required_minutes' => 0]);
        }

        $startDate = (string) $request->query('start_date', now()->toDateString());
        $days      = max(1, min(14, (int) $request->query('days', 7)));
        $resourceId = $request->query('resource_id') ?: null;

        $bookingService = app(\App\Services\BookingService::class);
        $dayData = $bookingService->dayCounts($tenant, $required, $startDate, $days, $resourceId);

        return response()->json([
            'days'             => $dayData,
            'required_minutes' => $required,
        ]);
    }

    public function dayTimes(Request $request)
    {
        $tenant = tenant();

        $serviceIds = (array) $request->query('service_ids', []);
        $serviceIds = array_values(array_filter($serviceIds, fn($id) => is_string($id) && $id !== ''));
        $date = (string) $request->query('date', '');

        if (empty($serviceIds) || $date === '') {
            return response()->json(['times' => [], 'required_minutes' => 0]);
        }

        $services = \App\Models\Tenant\TenantServiceItem::where('tenant_id', $tenant->id)
            ->whereIn('id', $serviceIds)
            ->get(['duration_minutes', 'prep_before_minutes', 'cleanup_after_minutes']);

        $required = 0;
        foreach ($services as $svc) {
            $required += (int) ($svc->prep_before_minutes ?? 0)
                       + (int) ($svc->duration_minutes ?? 0)
                       + (int) ($svc->cleanup_after_minutes ?? 0);
        }

        if ($required === 0) {
            return response()->json(['times' => [], 'required_minutes' => 0]);
        }

        $resourceId = $request->query('resource_id') ?: null;

        $bookingService = app(\App\Services\BookingService::class);
        $times = $bookingService->availableSlotsForDate($tenant, $date, $resourceId, $required);

        if (\Carbon\Carbon::parse($date)->isToday()) {
            $minNoticeHours = (int) ($tenant->min_notice_hours ?? 0);
            $cutoff = now()->addHours($minNoticeHours)->format('H:i');
            $times = array_values(array_filter($times, fn($t) => $t >= $cutoff));
        }

        return response()->json([
            'times'            => $times,
            'required_minutes' => $required,
        ]);
    }

    public function resolveResource(Request $request)
    {
        $tenant = tenant();

        $serviceIds = (array) $request->query('service_ids', []);
        $serviceIds = array_values(array_filter($serviceIds, fn($id) => is_string($id) && $id !== ''));
        $date = (string) $request->query('date', '');
        $time = (string) $request->query('time', '');

        if (empty($serviceIds) || $date === '' || $time === '') {
            return response()->json(['resource_id' => null]);
        }

        $services = \App\Models\Tenant\TenantServiceItem::where('tenant_id', $tenant->id)
            ->whereIn('id', $serviceIds)
            ->get(['duration_minutes', 'prep_before_minutes', 'cleanup_after_minutes']);

        $required = 0;
        foreach ($services as $svc) {
            $required += (int) ($svc->prep_before_minutes ?? 0)
                       + (int) ($svc->duration_minutes ?? 0)
                       + (int) ($svc->cleanup_after_minutes ?? 0);
        }

        if ($required === 0) {
            return response()->json(['resource_id' => null]);
        }

        $bookingService = app(\App\Services\BookingService::class);

        // If a specific resource is requested, verify it's free at that time.
        // Otherwise auto-resolve the first available active resource.
        $requestedResourceId = $request->query('resource_id') ?: null;
        if ($requestedResourceId) {
            $slots = $bookingService->availableSlotsForDate($tenant, $date, $requestedResourceId, $required);
            $resourceId = in_array($time, $slots, true) ? $requestedResourceId : null;
        } else {
            $resourceId = $bookingService->resolveResourceForSlot($tenant, $date, $time, $required);
        }

        return response()->json(['resource_id' => $resourceId]);
    }


    public function show(Request $request, string $id)
    {
        if ($request->expectsJson() || $request->ajax()) {
            return $this->jsonDetail(tenant(), $id);
        }

        $tenant = tenant();
        $appointment = \App\Models\Tenant\TenantAppointment::where('tenant_id', $tenant->id)
            ->where('id', $id)
            ->with(['items', 'addons', 'parts.inventoryItem', 'parts.specialOrder', 'notes', 'charges', 'customer', 'workOrderResponses', 'workOrderFields', 'payments.registerSale', 'sales'])
            ->firstOrFail();

        $transitions = AppointmentStatus::TRANSITIONS[$appointment->status] ?? [];
        $destructive = AppointmentStatus::DESTRUCTIVE;

        // Active services + addons for the line-item editor.
        // Loaded once at render; the inline editor shows them in select dropdowns.
        $availableServices = \App\Models\Tenant\TenantServiceItem::where('tenant_id', $tenant->id)
            ->where('is_active', true)
            ->with('category:id,name')
            ->orderBy('name')
            ->get(['id', 'name', 'price_cents', 'duration_minutes', 'category_id']);

        $availableAddons = \App\Models\Tenant\TenantAddon::where('tenant_id', $tenant->id)
            ->where('is_active', true)
            ->orderBy('name')
            ->get(['id', 'name', 'price_cents', 'default_duration_minutes']);

        // Active resources for the sidebar resource-change dropdown.
        // Ordered to match the calendar column order.
        $availableResources = \App\Models\Tenant\TenantResource::where('tenant_id', $tenant->id)
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get(['id', 'name', 'subtitle', 'color_hex']);

        // Special orders for this appointment (added by patch 88, Stage 5)
        $specialOrdersForAppt = \App\Models\Tenant\TenantSpecialOrder::where('tenant_id', $tenant->id)
            ->where('appointment_id', $id)
            ->with(['vendor', 'item'])
            ->orderBy('status')
            ->orderBy('expected_arrival_date')
            ->get();
        $soVendors = \App\Models\Tenant\TenantVendor::where('tenant_id', $tenant->id)
            ->where('is_active', true)
            ->orderBy('name')
            ->get(['id', 'name']);

        // MARKER-PATCH-158-F — branch to multi-asset view whenever the feature is on
        // (drops the previous "must have at least one asset attached" requirement,
        // which created a chicken-and-egg where users couldn't attach the first
        // asset because the view wasn't rendered yet).
        if ($tenant->multi_asset_enabled) {
            $appointmentAssets = \App\Models\Tenant\TenantAppointmentAsset::where('tenant_id', $tenant->id)
                ->where('appointment_id', $appointment->id)
                ->with(['customerAsset', 'items.serviceItem', 'addons.addon', 'parts.inventoryItem', 'parts.specialOrder', 'workOrderResponses']) // MARKER-PATCH-158-G5
                ->orderBy('sort_order')
                ->get();

            // Loose items/addons/parts = NOT pinned to any asset (back-compat)
            $looseItems  = $appointment->items->whereNull('appointment_asset_id');
            $looseAddons = $appointment->addons->whereNull('appointment_asset_id');
            $looseParts  = $appointment->parts->whereNull('appointment_asset_id'); // MARKER-PATCH-158-G4

            // Picker data: customer's saved assets not already attached
            $attachedAssetIds = $appointmentAssets->pluck('customer_asset_id')->filter()->values()->all();
            $pickerAssets = \App\Models\Tenant\TenantCustomerAsset::where('tenant_id', $tenant->id)
                ->where('customer_id', $appointment->customer_id)
                ->whereNull('archived_at')
                ->whereNotIn('id', $attachedAssetIds)
                ->orderBy('name')
                ->get();

            return view('tenant.appointments.show-multi-asset', compact(
                'appointment', 'appointmentAssets', 'looseItems', 'looseAddons', 'looseParts',
                'pickerAssets',
                'transitions', 'destructive',
                'availableServices', 'availableAddons', 'availableResources',
                'specialOrdersForAppt', 'soVendors'));
        }

        return view('tenant.appointments.show', compact(
            'appointment', 'transitions', 'destructive',
            'availableServices', 'availableAddons', 'availableResources', 'specialOrdersForAppt', 'soVendors'));
    }

    // MARKER-PATCH-313 — render the printable 80mm service tag(s) for a job.
    public function printTag(Request $request, string $id)
    {
        $tenant = tenant();

        $appointment = \App\Models\Tenant\TenantAppointment::where('tenant_id', $tenant->id)
            ->where('id', $id)
            ->with(['items', 'customer'])
            ->firstOrFail();

        $cfg  = (array) (($tenant->settings['work_order_tag'] ?? []));
        $show = fn($k) => ($cfg[$k] ?? true) ? true : false;
        $tag  = array_merge([
            'show_header'   => $show('show_header'),
            'show_phone'    => $show('show_phone'),
            'show_bike'     => $show('show_bike'),
            'show_services' => $show('show_services'),
            'show_note'     => $show('show_note'),
            'show_qr'       => $show('show_qr'),
            'show_stub'     => $show('show_stub'),
        ], \App\Services\PrintIdentityService::forTenant($tenant)); // MARKER-PATCH-332

        // One slip per attached asset; otherwise a single slip for the job.
        $assets = \App\Models\Tenant\TenantAppointmentAsset::where('tenant_id', $tenant->id)
            ->where('appointment_id', $appointment->id)
            ->orderBy('sort_order')
            ->get();

        $slips = [];
        if ($assets->isNotEmpty()) {
            foreach ($assets as $asset) {
                $slips[] = [
                    'bike'     => trim(($asset->asset_name_snapshot ?? '') . ($asset->identifier_snapshot ? ' · ' . $asset->identifier_snapshot : '')),
                    'services' => $appointment->items->where('appointment_asset_id', $asset->id)->pluck('item_name_snapshot')->filter()->values()->all(),
                ];
            }
        } else {
            $slips[] = [
                'bike'     => '',
                'services' => $appointment->items->pluck('item_name_snapshot')->filter()->values()->all(),
            ];
        }

        $jobUrl = route('tenant.appointments.show', $appointment->id);
        $embed  = $request->boolean('embed'); // MARKER-PATCH-314 — modal/iframe mode

        return view('tenant.appointments.tag', compact('tenant', 'appointment', 'tag', 'slips', 'jobUrl', 'embed'));
    }

    public function update(Request $request, string $id)
    {
        return $this->handleUpdate(tenant(), $id, $request);
    }

    public function drawer(Request $request, string $id)
    {
        $tenant = tenant();
        $appointment = \App\Models\Tenant\TenantAppointment::where('tenant_id', $tenant->id)
            ->where('id', $id)
            ->with(['items', 'addons', 'parts', 'workOrderResponses.field', 'notes', 'customer'])
            ->firstOrFail();

        $identifierField = \App\Models\Tenant\TenantWorkOrderField::where('tenant_id', $tenant->id)
            ->where('is_identifier', true)
            ->first();
        $identifierFieldId = $identifierField?->id;

        $identifierValue = null;
        if ($identifierField) {
            $resp = $appointment->workOrderResponses->firstWhere('field_id', $identifierField->id);
            $identifierValue = $resp?->response_value;
        }

        // ── MARKER-PATCH-212 — enriched drawer data ──

        // Assets (multi-asset). Empty collection for single-asset appointments.
        $assets = \App\Models\Tenant\TenantAppointmentAsset::where('tenant_id', $tenant->id)
            ->where('appointment_id', $appointment->id)
            ->with(['items', 'addons', 'parts'])
            ->orderBy('sort_order')
            ->get()
            ->map(function ($a) {
                $lines = [];
                $assetCents = $a->items->sum('price_cents')
                    + $a->addons->sum('price_cents')
                    + $a->parts->sum(fn($p) => $p->lineTotalCents());
                foreach ($a->items as $it) {
                    $lines[] = ['name' => $it->item_name_snapshot, 'price' => format_money($it->price_cents), 'addon' => false];
                }
                foreach ($a->addons as $ad) {
                    $lines[] = ['name' => $ad->addon_name_snapshot, 'price' => format_money($ad->price_cents), 'addon' => true];
                }
                foreach ($a->parts as $pt) {
                    $qty = $pt->quantity > 1 ? ' ×' . $pt->quantity : '';
                    $lines[] = ['name' => $pt->item_name_snapshot . $qty, 'price' => format_money($pt->lineTotalCents()), 'addon' => false];
                }
                return [
                    'name'     => $a->asset_name_snapshot ?: 'Asset',
                    'subtotal' => format_money($assetCents),
                    'lines'    => $lines,
                ];
            })->values()->toArray();

        // Customer visit count — single COUNT, no row load.
        $visits = $appointment->customer_id
            ? \App\Models\Tenant\TenantAppointment::where('tenant_id', $tenant->id)
                ->where('customer_id', $appointment->customer_id)->count()
            : null;
        $customerSince = $appointment->customer?->created_at?->format('M Y');

        // Intake answers (exclude the identifier field; only answered ones).
        $workOrder = $appointment->workOrderResponses
            ->filter(fn($r) => $r->field_id !== $identifierFieldId
                && $r->field
                && trim((string) $r->response_value) !== '')
            ->map(fn($r) => ['label' => $r->field->label, 'value' => $r->response_value])
            ->values()->toArray();

        // Shop notes (skip audit/system/status entries).
        $notes = $appointment->notes
            ->reject(fn($n) => in_array($n->note_type, ['audit', 'system', 'status_change', 'status'], true))
            ->filter(fn($n) => trim((string) $n->note_content) !== '')
            ->sortByDesc('created_at')->take(3)
            ->map(fn($n) => [
                'content' => $n->note_content,
                'type'    => ucwords(str_replace('_', ' ', (string) $n->note_type)),
                'date'    => optional($n->created_at)->format('M j'),
            ])->values()->toArray();

        // Activity — this appointment's notification log, newest first.
        $activity = \App\Models\Tenant\TenantNotificationLog::where('tenant_id', $tenant->id)
            ->where('related_id', $appointment->id)
            ->orderByDesc('created_at')
            ->limit(6)->get()
            ->map(fn($l) => [
                'event'   => ucwords(str_replace(['_', '.'], ' ', (string) $l->event_type)),
                'channel' => $l->channel,
                'status'  => $l->status,
                'date'    => optional($l->created_at)->format('M j · g:i A'),
            ])->values()->toArray();

        $paid    = (int) $appointment->paid_cents;
        $total   = (int) $appointment->total_cents;
        $balance = max(0, $total - $paid);

        return response()->json([
            'ok' => true,
            'appointment' => [
                'id'                    => $appointment->id,
                'ra_number'             => $appointment->ra_number,
                'status'                => $appointment->status,
                'status_label'          => AppointmentStatus::label($appointment->status),
                'payment_status'        => $appointment->payment_status,
                'payment_status_label'  => ucfirst($appointment->payment_status),
                'customer_name'         => trim(($appointment->customer_first_name ?? '') . ' ' . ($appointment->customer_last_name ?? '')),
                'customer_email'        => $appointment->customer_email,
                'customer_phone'        => $appointment->customer_phone,
                'customer_visits'       => $visits,
                'customer_since'        => $customerSince,
                'appointment_date'      => $appointment->appointment_date?->format('Y-m-d'),
                'appointment_date_long' => $appointment->appointment_date?->format('l, F j, Y'),
                'appointment_time'      => $appointment->appointment_time,
                'total_cents'           => $total,
                'total_formatted'       => format_money($total),
                'paid_cents'            => $paid,
                'duration_minutes'      => (int) ($appointment->total_duration_minutes ?? 0),
                'identifier_label'      => $identifierField?->label,
                'identifier_value'      => $identifierValue,
                'payment'               => [
                    'subtotal'      => format_money($appointment->subtotal_cents),
                    'tax'           => format_money($appointment->tax_cents),
                    'total'         => format_money($total),
                    'paid'          => format_money($paid),
                    'balance'       => format_money($balance),
                    'balance_cents' => $balance,
                ],
                'assets'                => $assets,
                'work_order'            => $workOrder,
                'notes'                 => $notes,
                'activity'              => $activity,
                ...$this->appointmentLineItems($appointment),
                'full_url'              => route('tenant.appointments.show', ['id' => $appointment->id]),
            ],
        ]);
    }

    /**
     * MARKER-PATCH-290 — single line-item serializer shared by the detail modal
     * and the calendar drawer, so both reconcile to the same subtotal
     * (services + add-ons + parts). Returns the richer modal shape; the drawer
     * ignores prep/cleanup.
     */
    private function appointmentLineItems(\App\Models\Tenant\TenantAppointment $appointment): array
    {
        return [
            'items' => $appointment->items->map(fn($i) => [
                'name' => $i->item_name_snapshot,
                'duration' => $i->duration_minutes_snapshot,
                'prep_min' => (int) ($i->prep_before_minutes_snapshot ?? 0),
                'cleanup_min' => (int) ($i->cleanup_after_minutes_snapshot ?? 0),
                'price' => format_money($i->price_cents),
            ])->values()->toArray(),
            'addons' => $appointment->addons->map(fn($a) => [
                'name' => $a->addon_name_snapshot,
                'price' => format_money($a->price_cents),
            ])->values()->toArray(),
            'parts' => $appointment->parts->map(fn($p) => [
                'name' => $p->item_name_snapshot,
                'quantity' => $p->quantity,
                'price' => format_money($p->lineTotalCents()),
            ])->values()->toArray(),
        ];
    }

        private function jsonDetail($tenant, string $id)
    {
        $appointment = TenantAppointment::where('tenant_id', $tenant->id)
            ->where('id', $id)
            ->with([
                'items', 'addons', 'parts', 'notes', 'charges', 'customer',
                'responses', 'workOrderResponses.field', 'resource',
            ])
            ->firstOrFail();

        $transitions = AppointmentStatus::TRANSITIONS[$appointment->status] ?? [];

        return response()->json([
            'ok' => true,
            'appointment' => [
                'id' => $appointment->id, 'ra_number' => $appointment->ra_number,
                'status' => $appointment->status, 'status_label' => AppointmentStatus::label($appointment->status),
                'pipeline' => AppointmentStatus::pipeline(), 'is_done' => AppointmentStatus::isDone($appointment->status),
                'payment_status' => $appointment->payment_status, 'payment_label' => ucfirst($appointment->payment_status),
                'customer_name' => $appointment->customerName(), 'customer_email' => $appointment->customer_email,
                'customer_phone' => $appointment->customer_phone, 'customer_id' => $appointment->customer_id,
                'appointment_date' => $appointment->appointment_date->format('M j, Y'),
                'appointment_date_raw' => $appointment->appointment_date->format('Y-m-d'),
                'staff_notes' => $appointment->staff_notes,
                'subtotal_cents' => $appointment->subtotal_cents, 'tax_cents' => $appointment->tax_cents,
                'total_cents' => $appointment->total_cents, 'paid_cents' => $appointment->paid_cents,
                'total_display' => format_money($appointment->total_cents),
                'paid_display' => format_money($appointment->paid_cents),
                'subtotal_display' => format_money($appointment->subtotal_cents),
                'created_at' => $appointment->created_at->format('M j, Y g:i a'),
                'slot_weight' => $appointment->slot_weight ?? 1,
                'resource' => $appointment->resource ? [
                    'id' => $appointment->resource->id,
                    'name' => $appointment->resource->name,
                    'subtitle' => $appointment->resource->subtitle,
                    'color_hex' => $appointment->resource->color_hex,
                ] : null,
                'appointment_time' => $appointment->appointment_time
                    ? \Carbon\Carbon::parse($appointment->appointment_time)->format('g:i a')
                    : null,
                'appointment_end_time' => $appointment->appointment_end_time
                    ? \Carbon\Carbon::parse($appointment->appointment_end_time)->format('g:i a')
                    : null,
                'total_duration_minutes' => $appointment->total_duration_minutes,
                ...$this->appointmentLineItems($appointment),
                'charges' => $appointment->charges->map(fn($c) => ['id' => $c->id, 'description' => $c->description, 'amount' => format_money($c->amount_cents), 'is_paid' => $c->is_paid, 'date' => \Carbon\Carbon::parse($c->created_at)->format('M j')]),
                'notes' => $appointment->notes->sortByDesc('created_at')->values()->map(fn($n) => ['id' => $n->id, 'note' => $n->note_content, 'author' => $n->user?->name ?? ($n->note_type === 'system' ? 'System' : 'Staff'), 'type' => $n->note_type, 'created_at' => tlocal($n->created_at, 'M j, g:i a') /* MARKER-PATCH-532 */]),
                'work_order_responses' => $appointment->workOrderResponses
                    ->filter(fn($r) => $r->field !== null)
                    ->map(fn($r) => [
                        'field_label' => $r->field->label,
                        'field_type' => $r->field->field_type,
                        'is_identifier' => (bool) $r->field->is_identifier,
                        'value' => $r->response_value,
                    ])
                    ->values(),
                'form_responses' => $appointment->responses->map(fn($r) => [
                    'field_label' => $r->field_label_snapshot,
                    'value' => $r->response_value,
                ]),
            ],
            'transitions' => collect($transitions)->map(fn($t) => ['status' => $t, 'label' => AppointmentStatus::TRANSITION_LABELS[$t] ?? ucfirst($t), 'destructive' => in_array($t, AppointmentStatus::DESTRUCTIVE)])->values(),
        ]);
    }

    private function handleUpdate($tenant, string $id, Request $request)
    {
        $appointment = TenantAppointment::where('tenant_id', $tenant->id)->where('id', $id)->firstOrFail();
        $op = $request->input('op');

        // MARKER-PATCH-311 — set or clear the promised-back datetime.
        if ($op === 'promised') {
            $raw = trim((string) $request->input('promised_date', ''));
            if ($raw === '') {
                $appointment->update(['promised_at' => null]);
            } else {
                $tz    = method_exists($tenant, 'timezone') ? $tenant->timezone() : config('app.timezone', 'UTC');
                $local = \Carbon\Carbon::parse($raw, $tz)->setTime(17, 0);
                $appointment->update(['promised_at' => $local->setTimezone('UTC')]);
            }
            return response()->json([
                'ok' => true,
                'promised_local' => $appointment->promised_at ? tlocal_date($appointment->promised_at) : null,
            ]);
        }

        if ($op === 'status') {
            $newStatus = $request->input('status');
            $allowed = AppointmentStatus::TRANSITIONS[$appointment->status] ?? [];
            if (!in_array($newStatus, $allowed, true)) return response()->json(['ok' => false, 'message' => 'Invalid status transition.'], 422);

            $oldStatus = $appointment->status;
            $wasCommitted = AppointmentInventoryService::isCommittedStatus($oldStatus);
            $willCommit   = AppointmentInventoryService::isCommittedStatus($newStatus);

            // Inventory transitions:
            //   not-committed → committed       : commit (decrement)
            //   committed     → not-committed   : revert (increment)
            //   committed     → committed (e.g. completed → shipped) : no-op
            //   not-committed → not-committed   : no-op
            //
            // We commit BEFORE writing the status so a stock failure doesn't
            // leave the appointment in a "completed but inventory not deducted"
            // state. Revert happens AFTER status change since it can't fail
            // for stock reasons (incrementing always succeeds).
            if (!$wasCommitted && $willCommit) {
                try {
                    $this->appointmentInventory->commitParts($appointment);
                } catch (InventoryStockException $e) {
                    return response()->json([
                        'ok' => false,
                        'message' => 'Cannot complete: ' . $e->getMessage(),
                    ], 422);
                }
            }

            $appointment->update(['status' => $newStatus]);

            // MARKER-PATCH-160 — appointment receipt on configured terminal states.
            // Defaults to ['completed']; tenants can add 'shipped' or 'closed' via
            // settings.receipt_appointment_trigger_states.
            $tenantSettings = $tenant->settings ?? [];
            $triggerStates  = $tenantSettings['receipt_appointment_trigger_states'] ?? ['completed'];
            if (in_array($newStatus, $triggerStates, true) && !in_array($oldStatus, $triggerStates, true)) {
                \App\Jobs\SendAppointmentReceiptJob::dispatch($appointment->id)->afterCommit();
            }

            // Register bridge:
            //   entering committed status → create draft sale (or mark paid / overage)
            //   leaving committed status  → void any open draft sale
            $bridgeResult = null;
            if (!$wasCommitted && $willCommit) {
                try {
                    $bridgeResult = $this->registerBridge->onAppointmentEnteringCommittedStatus($appointment);
                } catch (\Throwable $e) {
                    \Illuminate\Support\Facades\Log::error('Register bridge enter failed', [
                        'appointment_id' => $appointment->id,
                        'error'          => $e->getMessage(),
                    ]);
                    // Don't fail the status change — it's already written and inventory committed.
                    // The bridge can be retried via a "regenerate sale" action later.
                }
            } elseif ($wasCommitted && !$willCommit) {
                try {
                    $this->registerBridge->onAppointmentLeavingCommittedStatus($appointment);
                } catch (\Throwable $e) {
                    \Illuminate\Support\Facades\Log::error('Register bridge leave failed', [
                        'appointment_id' => $appointment->id,
                        'error'          => $e->getMessage(),
                    ]);
                }
            }

            if ($wasCommitted && !$willCommit) {
                $this->appointmentInventory->revertParts($appointment);
            }

            if ($newStatus === 'cancelled' && $appointment->appointment_date) {
                try {
                    $firstItem = $appointment->items()->first();
                    if ($firstItem && $firstItem->service_item_id) {
                        \App\Jobs\ProcessWaitlistOpeningJob::dispatch(
                            $appointment->tenant_id,
                            $appointment->appointment_date->toDateTimeString(),
                            $firstItem->service_item_id,
                            'cancellation',
                            $appointment->id
                        )->afterCommit();
                    }
                } catch (\Throwable $e) {
                    \Illuminate\Support\Facades\Log::error('Waitlist dispatch failed', [
                        'appointment_id' => $appointment->id,
                        'error'          => $e->getMessage(),
                    ]);
                }
            }
            TenantAppointmentNote::create(['appointment_id' => $appointment->id, 'user_id' => Auth::guard('tenant')->id(), 'note_type' => 'system', 'is_customer_visible' => false, 'note_content' => 'Status changed to ' . ucwords(str_replace('_', ' ', $newStatus)) . '.', 'created_at' => now()]);
            // MARKER-PATCH-527 — offer to text delivery windows when work hits Completed
            $proposeDelivery = null;
            if (
                $newStatus === 'completed' && $oldStatus !== 'completed'
                && $tenant->deliveries_enabled
                && (bool) (($tenant->settings['pd_auto_propose'] ?? true))
            ) {
                try {
                    $appointment->loadMissing('customer');
                    $cust = $appointment->customer;
                    // MARKER-PATCH-538 — pending proposal no longer blocks the modal (re-send supersedes)
                    if ($cust && !empty($cust->phone)) {
                        $svc = DeliveryProposalService::forTenant($tenant);
                        $cands = $svc->candidates();
                        if (!empty($cands)) {
                            $settings   = (array) ($tenant->settings ?? []);
                            $assumeHour = max(12, min(23, (int) ($settings['pd_assume_first_hour'] ?? 20)));
                            $tz = $tenant->timezone();
                            $dl = \Carbon\Carbon::now($tz)->setTime($assumeHour, 0);
                            if ($dl->isPast()) $dl->addDay();
                            $proposeDelivery = [
                                'asset_noun'     => $tenant->asset_label_singular ?: 'work', // MARKER-PATCH-535
                                'customer_name'  => trim(($cust->first_name ?? '') . ' ' . ($cust->last_name ?? '')),
                                'phone_tail'     => substr(preg_replace('/\D/', '', $cust->phone), -4),
                                'windows'        => $cands,
                                'deadline_label' => $dl->isToday() ? ('tonight ' . $dl->format('g A')) : $dl->format('D g A'),
                            ];
                        }
                    }
                } catch (\Throwable $e) {
                    \Illuminate\Support\Facades\Log::error('Delivery propose payload failed', [
                        'appointment_id' => $appointment->id, 'error' => $e->getMessage(),
                    ]);
                }
            }

            return response()->json([
                'ok'             => true,
                'status'         => $newStatus,
                'label'          => ucwords(str_replace('_', ' ', $newStatus)),
                'register_bridge'=> $bridgeResult,
                'propose_delivery' => $proposeDelivery, // MARKER-PATCH-527
                'reload'         => true, // signal client to reload — banner / lock state needs fresh data
            ]);
        }

        // MARKER-PATCH-531 — staff picked a window in the modal: schedule it now
        if ($op === 'delivery_schedule_direct') {
            if (!$tenant->deliveries_enabled) {
                return response()->json(['ok' => false, 'message' => 'Deliveries are not enabled.'], 422);
            }
            try {
                // MARKER-PATCH-534 — per-channel consent from the modal pills
                $channels = array_filter([
                    $request->input('notify_sms') === '1' ? 'sms' : null,
                    $request->input('notify_email') === '1' ? 'email' : null,
                ]);
                $delivery = DeliveryProposalService::forTenant($tenant)->scheduleDirect(
                    $appointment,
                    (string) $request->input('window_id'),
                    (string) $request->input('date'),
                    array_values($channels),
                );
            } catch (\RuntimeException $e) {
                return response()->json(['ok' => false, 'message' => $e->getMessage()], 422);
            } catch (\Throwable $e) {
                \Illuminate\Support\Facades\Log::error('Direct delivery schedule failed', [
                    'appointment_id' => $appointment->id, 'error' => $e->getMessage(),
                ]);
                return response()->json(['ok' => false, 'message' => 'Could not schedule — check logs.'], 500);
            }
            TenantAppointmentNote::create([
                'appointment_id' => $appointment->id,
                'user_id' => Auth::guard('tenant')->id(),
                'note_type' => 'system', 'is_customer_visible' => false,
                'note_content' => 'Delivery scheduled for ' . tlocal($delivery->scheduled_at, 'D M j, g:i A') . ' from the completion modal' . (count($channels) ? ' — confirmation by ' . implode(' + ', $channels) . '.' : ' — no customer notification.'), // MARKER-PATCH-534
                'created_at' => now(),
            ]);
            return response()->json(['ok' => true]);
        }

        // MARKER-PATCH-527 — staff confirmed the modal: create + text the proposal
        if ($op === 'delivery_proposal_send') {
            if (!$tenant->deliveries_enabled) {
                return response()->json(['ok' => false, 'message' => 'Deliveries are not enabled.'], 422);
            }
            // MARKER-PATCH-536 — options go out only on the channels staff chose
            $propChannels = array_values(array_filter([
                $request->input('notify_sms', '1') === '1' ? 'sms' : null,
                $request->input('notify_email') === '1' ? 'email' : null,
            ]));
            if (empty($propChannels)) {
                return response()->json(['ok' => false, 'message' => 'Pick at least one channel (text or email).'], 422);
            }
            try {
                $proposal = DeliveryProposalService::forTenant($tenant)->proposeForAppointment($appointment, $propChannels);
            } catch (\Throwable $e) {
                \Illuminate\Support\Facades\Log::error('Delivery proposal send failed', [
                    'appointment_id' => $appointment->id, 'error' => $e->getMessage(),
                ]);
                return response()->json(['ok' => false, 'message' => 'Could not send — check logs.'], 500);
            }
            if (!$proposal) {
                return response()->json(['ok' => false, 'message' => 'Nothing to send — no contact info for the chosen channels, or no open windows.'], 422); // MARKER-PATCH-538
            }
            if (!$proposal->sent_channels) {
                return response()->json(['ok' => false, 'message' => 'Proposal saved but the text failed to send.'], 500);
            }
            TenantAppointmentNote::create([
                'appointment_id' => $appointment->id,
                'user_id' => Auth::guard('tenant')->id(),
                'note_type' => 'system', 'is_customer_visible' => false,
                'note_content' => 'Delivery windows sent to customer by ' . str_replace('sms', 'text', implode(' + ', $propChannels)) . ' (' . count($proposal->windows) . ' options).', // MARKER-PATCH-536
                'created_at' => now(),
            ]);
            return response()->json(['ok' => true]);
        }
        // MARKER-DELIVERY-RESOLUTION — record how a completed job got back to
        // the customer, so the queue reflects decisions rather than a timer.
        if ($op === 'delivery_resolution') {
            $valid = ['customer_pickup', 'handed_over', 'not_needed'];
            $res   = (string) $request->input('resolution');
            if (! in_array($res, $valid, true)) {
                return response()->json(['ok' => false, 'message' => 'Unknown resolution.'], 422);
            }
            $appointment->forceFill([
                'delivery_resolution'          => $res,
                'delivery_resolved_at'         => now(),
                'delivery_resolved_by_user_id' => Auth::guard('tenant')->id(),
                'delivery_snooze_until'        => null,
            ])->save();

            TenantAppointmentNote::create([
                'appointment_id'      => $appointment->id,
                'user_id'             => Auth::guard('tenant')->id(),
                'note_type'           => 'system',
                'is_customer_visible' => false,
                'note_content'        => 'Delivery resolved: ' . str_replace('_', ' ', $res),
                'created_at'          => now(),
            ]);

            return response()->json(['ok' => true]);
        }

        if ($op === 'delivery_snooze') {
            $days = max(1, min(30, (int) $request->input('days', 3)));
            $appointment->forceFill([
                'delivery_snooze_until' => now()->addDays($days),
            ])->save();

            return response()->json(['ok' => true, 'until' => $appointment->delivery_snooze_until->toDateString()]);
        }

        if ($op === 'delivery_resolution_clear') {
            $appointment->forceFill([
                'delivery_resolution'          => null,
                'delivery_resolved_at'         => null,
                'delivery_resolved_by_user_id' => null,
                'delivery_snooze_until'        => null,
            ])->save();

            return response()->json(['ok' => true]);
        }

        if ($op === 'payment') {
            // DEPRECATED: this op used to let staff manually flip
            // payment_status. The ledger is now the source of truth — staff
            // record actual payments via the register, and payment_status
            // is computed from ledger sums. We keep this for backward-compat
            // but only allow flipping to/from 'unpaid' (clearing) since any
            // other state must reflect real ledger entries.
            $newPayment = $request->input('payment_status');
            if (!in_array($newPayment, ['unpaid'], true)) {
                return response()->json([
                    'ok'      => false,
                    'message' => 'Payment status is computed from the payment ledger. Record a payment via the register instead.',
                ], 422);
            }
            $appointment->update(['payment_status' => 'unpaid']);
            return response()->json(['ok' => true, 'payment_status' => 'unpaid']);
        }

        if ($op === 'record_deposit') {
            // Routes a deposit through the register: creates a tiny draft
            // sale with one open_item line for the deposit amount, attached
            // to this appointment. Staff completes the sale in the register
            // to actually take the payment. On sale-close, the bridge
            // writes a payment row.
            //
            // Validation: amount must be positive, balance must allow it
            // (no taking deposits beyond the appointment total).
            $amountCents = (int) $request->input('amount_cents');
            if ($amountCents <= 0) {
                return response()->json(['ok' => false, 'message' => 'Amount must be positive.'], 422);
            }
            $balanceDue = max(0, (int) $appointment->total_cents - (int) $appointment->paid_cents);
            if ($amountCents > $balanceDue && $balanceDue > 0) {
                return response()->json([
                    'ok'      => false,
                    'message' => "Deposit can't exceed remaining balance of " . format_money($balanceDue) . '.',
                ], 422);
            }

            // Build a one-line deposit-collection sale.
            $sale = \DB::transaction(function () use ($appointment, $amountCents, $tenant) {
                $saleNumber = $this->generateDepositSaleNumber($tenant->id);
                $sale = \App\Models\Tenant\TenantSale::create([
                    'id'                  => (string) Str::uuid(),
                    'tenant_id'           => $tenant->id,
                    'sale_number'         => $saleNumber,
                    'sale_date'           => now()->toDateString(),
                    'status'              => 'pending',
                    'payment_status'      => 'draft',
                    'customer_id'         => $appointment->customer_id,
                    'appointment_id'      => $appointment->id,
                    'rang_up_by_user_id'  => Auth::guard('tenant')->id(),
                    'subtotal_cents'      => $amountCents,
                    'tax_cents'           => 0,
                    'total_cents'         => $amountCents,
                    'notes'               => 'Deposit collection for appointment ' . ($appointment->ra_number ?? $appointment->id),
                ]);

                DB::table('tenant_sale_items')->insert([
                    'id'                 => (string) Str::uuid(),
                    'tenant_id'          => $tenant->id,
                    'sale_id'            => $sale->id,
                    'type'               => 'open_item',
                    'name_snapshot'      => 'Deposit toward appointment ' . ($appointment->ra_number ?? ''),
                    'quantity'           => 1,
                    'unit_price_cents'   => $amountCents,
                    'line_total_cents'   => $amountCents,
                    'is_taxable'         => false,
                    'position'           => 0,
                    'notes'              => 'Auto-created deposit line; payment writes to appointment ledger on close.',
                    'created_at'         => now(),
                    'updated_at'         => now(),
                ]);

                return $sale;
            });

            return response()->json([
                'ok'              => true,
                'sale_id'         => $sale->id,
                'sale_number'     => $sale->sale_number,
                'redirect_url'    => route('tenant.register.index', [
                    ]) . '?resume=' . $sale->id,
                'message'         => 'Deposit sale created. Take payment in the register.',
            ]);
        }

        if ($op === 'void_register_sale') {
            // Staff clicked "Edit (voids draft)". Find the open sale, void
            // it through the bridge (which drops ledger rows + recomputes
            // status). Refunds (paid sale) take a different path.
            $sale = $appointment->openRegisterSale();
            if (!$sale) {
                return response()->json([
                    'ok'      => false,
                    'message' => 'No open register sale to void.',
                ], 422);
            }
            $voided = $this->registerBridge->voidDraftSale($sale, 'manual_edit');
            if (!$voided) {
                return response()->json([
                    'ok'      => false,
                    'message' => 'Sale has been paid. Use the refund flow instead of voiding.',
                ], 422);
            }
            return response()->json([
                'ok'      => true,
                'reload'  => true,
                'message' => 'Draft sale voided. Appointment editable again.',
            ]);
        }
        if ($op === 'date') {
            $request->validate(['appointment_date' => ['required', 'date']]);
            $appointment->update(['appointment_date' => $request->input('appointment_date')]);
            return response()->json(['ok' => true]);
        }

        // RESCHEDULE-OP v1 — full reschedule (date + time + resource).
        // Validates availability defensively (slot may have been taken between
        // the picker fetch and this submit). Recomputes appointment_end_time.
        // Records a system note with from/to summary.
        if ($op === 'reschedule') {
            $request->validate([
                'appointment_date' => ['required', 'date'],
                'appointment_time' => ['required', 'string'],
                'resource_id'      => ['required', 'string'],
            ]);

            $newDate     = $request->input('appointment_date');
            $newTime     = $request->input('appointment_time');
            $newResource = $request->input('resource_id');

            // Normalize time to H:i:s for storage. Accepts "14:00" or "14:00:00".
            $newTimeNorm = strlen($newTime) === 5 ? $newTime . ':00' : $newTime;

            // Capture "from" for the system note.
            $fromDate     = $appointment->appointment_date?->format('Y-m-d');
            $fromTime     = $appointment->appointment_time;
            $fromResource = $appointment->resource_id;

            // No-op guard: if nothing actually changed, return early.
            if ($fromDate === $newDate
                && $fromTime === $newTimeNorm
                && $fromResource === $newResource) {
                return response()->json(['ok' => true, 'unchanged' => true]);
            }

            // Resolve the resource — must be a real resource on this tenant.
            $resource = \App\Models\Tenant\TenantResource::where('tenant_id', $tenant->id)
                ->where('id', $newResource)
                ->first();
            if (!$resource) {
                return response()->json(['ok' => false, 'message' => 'Selected resource is not available.'], 422);
            }

            // Defensive availability check: confirm the requested slot is still open.
            // We use the same BookingService method the picker uses, so the answer
            // is consistent with what the user saw.
            $bookingService = app(\App\Services\BookingService::class);
            $required = (int) ($appointment->total_duration_minutes ?? 0);
            if ($required <= 0) {
                return response()->json(['ok' => false, 'message' => 'Appointment has no duration set; cannot reschedule.'], 422);
            }

            $availableTimes = $bookingService->availableSlotsForDate(
                $tenant, $newDate, $newResource, $required
            );

            // availableSlotsForDate returns "H:i" strings. The slot is available
            // unless it overlaps an existing appointment. Special case: if the
            // appointment we're rescheduling is on the SAME date+resource, it
            // already counts itself as busy. We only reject if the new H:i is
            // not in the available list AND the new slot doesn't match the old
            // (which would be a no-op caught above).
            $newTimeShort = substr($newTimeNorm, 0, 5);
            $sameDateResource = ($fromDate === $newDate && $fromResource === $newResource);

            if (!in_array($newTimeShort, $availableTimes, true) && !$sameDateResource) {
                return response()->json([
                    'ok' => false,
                    'message' => 'That time was just taken. Please pick another available slot.',
                ], 409);
            }

            // Compute new end time from new start + duration.
            $newEnd = \Carbon\Carbon::parse($newDate . ' ' . $newTimeNorm)
                ->addMinutes($required)
                ->format('H:i:s');

            $appointment->update([
                'appointment_date'     => $newDate,
                'appointment_time'     => $newTimeNorm,
                'appointment_end_time' => $newEnd,
                'resource_id'          => $newResource,
            ]);

            // Build a human-readable summary for the system note.
            $fmtTime = function ($t) {
                if (!$t) return '—';
                try { return \Carbon\Carbon::parse($t)->format('g:i A'); }
                catch (\Throwable $e) { return $t; }
            };
            $fmtDate = function ($d) {
                if (!$d) return '—';
                try { return \Carbon\Carbon::parse($d)->format('M j, Y'); }
                catch (\Throwable $e) { return $d; }
            };

            $fromResourceName = \App\Models\Tenant\TenantResource::where('tenant_id', $tenant->id)
                ->where('id', $fromResource)->value('name') ?? 'Unassigned';
            $toResourceName   = $resource->name;

            $noteContent = sprintf(
                'Rescheduled from %s · %s · %s to %s · %s · %s.',
                $fmtDate($fromDate), $fmtTime($fromTime), $fromResourceName,
                $fmtDate($newDate),  $fmtTime($newTimeNorm), $toResourceName
            );

            TenantAppointmentNote::create([
                'appointment_id'      => $appointment->id,
                'user_id'             => Auth::guard('tenant')->id(),
                'note_type'           => 'system',
                'is_customer_visible' => false,
                'note_content'        => $noteContent,
                'created_at'          => now(),
            ]);

            return response()->json([
                'ok' => true,
                'appointment' => [
                    'date'        => $newDate,
                    'time'        => $newTimeNorm,
                    'end_time'    => $newEnd,
                    'resource_id' => $newResource,
                ],
                'note' => $noteContent,
            ]);
        }

        if ($op === 'add_charge') {
            $request->validate(['description' => ['required', 'string', 'max:255'], 'amount_cents' => ['required', 'integer', 'min:1']]);
            $charge = TenantAppointmentCharge::create(['appointment_id' => $appointment->id, 'description' => $request->input('description'), 'amount_cents' => (int) $request->input('amount_cents'), 'is_paid' => false, 'created_at' => now()]);
            return response()->json(['ok' => true, 'id' => $charge->id, 'description' => $charge->description, 'amount' => format_money($charge->amount_cents)]);
        }
        if ($op === 'add_note') {
            $note = mb_substr(trim($request->input('note', '')), 0, 500);
            if (!$note) return response()->json(['ok' => false, 'message' => 'Note is required.'], 422);
            // MARKER-PATCH-158-E5 — accept visibility flag (default false = internal)
            $isCustomerVisible = (bool) $request->input('is_customer_visible', false);
            $n = TenantAppointmentNote::create(['appointment_id' => $appointment->id, 'user_id' => Auth::guard('tenant')->id(), 'note_type' => 'staff', 'is_customer_visible' => $isCustomerVisible, 'note_content' => $note, 'created_at' => now()]);
            $user = Auth::guard('tenant')->user();
            return response()->json(['ok' => true, 'id' => $n->id, 'note' => $n->note_content, 'author' => $user->name, 'created_at' => $n->created_at->format('M j, g:i a')]);
        }
        if ($op === 'save_work_order') {
            $values = $request->input('values', []);
            if (!is_array($values)) {
                return response()->json(['ok' => false, 'message' => 'values must be an array.'], 422);
            }

            // MARKER-PATCH-158-G5 — Optional asset scope. NULL = appointment-wide
            // (legacy behavior). When set, the response is pinned to that asset
            // card so multiple assets each carry their own intake answers.
            $assetId = $request->input('appointment_asset_id');
            if ($assetId) {
                $assetExists = \App\Models\Tenant\TenantAppointmentAsset::where('appointment_id', $appointment->id)
                    ->where('id', $assetId)
                    ->exists();
                if (!$assetExists) {
                    return response()->json(['ok' => false, 'message' => 'Asset not on this appointment.'], 422);
                }
            }

            // Load fields once so we can snapshot labels and detect the identifier
            $fields = \App\Models\Tenant\TenantWorkOrderField::where('tenant_id', $tenant->id)
                ->whereIn('id', array_keys($values))
                ->get()
                ->keyBy('id');

            $identifierValue = null;
            $identifierLabel = null;

            foreach ($values as $fieldId => $rawValue) {
                $field = $fields->get($fieldId);
                if (!$field) continue;

                $value = is_string($rawValue) ? trim($rawValue) : $rawValue;
                $value = ($value === '' || $value === null) ? null : (string) $value;

                // MARKER-PATCH-158-G5 — Upsert key now includes appointment_asset_id
                $existing = \App\Models\Tenant\TenantAppointmentWorkOrderResponse::where('tenant_id', $tenant->id)
                    ->where('appointment_id', $appointment->id)
                    ->where('field_id', $field->id)
                    ->where(function ($q) use ($assetId) {
                        if ($assetId === null) {
                            $q->whereNull('appointment_asset_id');
                        } else {
                            $q->where('appointment_asset_id', $assetId);
                        }
                    })
                    ->first();

                if ($value === null) {
                    if ($existing) { $existing->delete(); }
                } elseif ($existing) {
                    $existing->update([
                        'response_value'       => $value,
                        'field_label_snapshot' => $field->label,
                    ]);
                } else {
                    \App\Models\Tenant\TenantAppointmentWorkOrderResponse::create([
                        'tenant_id'            => $tenant->id,
                        'appointment_id'       => $appointment->id,
                        'field_id'             => $field->id,
                        'appointment_asset_id' => $assetId, // MARKER-PATCH-158-G5
                        'field_label_snapshot' => $field->label,
                        'response_value'       => $value,
                    ]);
                }

                if ($field->is_identifier) {
                    $identifierValue = $value;
                    $identifierLabel = $value !== null ? $field->label : null;
                }
            }

            // Update the promoted identifier column if any identifier field was in the payload.
            // MARKER-PATCH-158-G5 — In multi-asset mode this captures the last asset's
            // identifier written. Cross-appointment identifier search (?serial=ABC) hits
            // this column; a future enhancement could index per-asset identifiers separately.
            $identifierTouched = $fields->contains(fn($f) => (bool) $f->is_identifier);
            if ($identifierTouched) {
                $appointment->update([
                    'identifier'       => $identifierValue,
                    'identifier_label' => $identifierLabel,
                ]);
            }

            return response()->json(['ok' => true]);
        }
        if ($op === 'delete_note') {
            TenantAppointmentNote::where('appointment_id', $appointment->id)->where('id', $request->input('note_id'))->delete();
            return response()->json(['ok' => true]);
        }
        if ($op === 'add_service') {
            $serviceItemId = $request->input('service_item_id');
            $service = \App\Models\Tenant\TenantServiceItem::where('id', $serviceItemId)
                ->where('tenant_id', $tenant->id)->where('is_active', true)->first();
            if (!$service) return response()->json(['ok' => false, 'message' => 'Service not found.'], 422);

            \App\Models\Tenant\TenantAppointmentItem::create([
                'id'                             => (string) \Illuminate\Support\Str::uuid(),
                'appointment_id'                 => $appointment->id,
                'service_item_id'                => $service->id,
                'item_name_snapshot'             => $service->name,
                'price_cents'                    => $service->price_cents,
                'duration_minutes_snapshot'      => $service->duration_minutes,
                'prep_before_minutes_snapshot'   => $service->prep_before_minutes ?? 0,
                'cleanup_after_minutes_snapshot' => $service->cleanup_after_minutes ?? 0,
            ]);
            $this->recalcAppointmentTotals($appointment);
            return response()->json(['ok' => true]);
        }

        if ($op === 'remove_service') {
            $itemId = $request->input('item_id');
            $item = \App\Models\Tenant\TenantAppointmentItem::where('id', $itemId)
                ->where('appointment_id', $appointment->id)->first();
            if (!$item) return response()->json(['ok' => false, 'message' => 'Item not found.'], 422);
            // MARKER-PATCH-158-E2 — snapshot asset FK before delete so we can refresh its subtotal
            $assetId = $item->appointment_asset_id;
            $item->delete();
            if ($assetId) {
                $aa = \App\Models\Tenant\TenantAppointmentAsset::find($assetId);
                if ($aa) $aa->refreshSubtotal();
            }
            $this->recalcAppointmentTotals($appointment);
            return response()->json(['ok' => true]);
        }

        if ($op === 'add_addon') {
            $addonId = $request->input('addon_id');
            $addon = \App\Models\Tenant\TenantAddon::where('id', $addonId)
                ->where('tenant_id', $tenant->id)->where('is_active', true)->first();
            if (!$addon) return response()->json(['ok' => false, 'message' => 'Add-on not found.'], 422);

            \App\Models\Tenant\TenantAppointmentAddon::create([
                'id'                        => (string) \Illuminate\Support\Str::uuid(),
                'appointment_id'            => $appointment->id,
                'addon_id'                  => $addon->id,
                'addon_name_snapshot'       => $addon->name,
                'price_cents'               => $addon->price_cents,
                'duration_minutes_snapshot' => $addon->default_duration_minutes ?? 0,
            ]);
            $this->recalcAppointmentTotals($appointment);
            return response()->json(['ok' => true]);
        }

        if ($op === 'remove_addon') {
            $addonId = $request->input('addon_id');
            $addon = \App\Models\Tenant\TenantAppointmentAddon::where('id', $addonId)
                ->where('appointment_id', $appointment->id)->first();
            if (!$addon) return response()->json(['ok' => false, 'message' => 'Add-on not found.'], 422);
            // MARKER-PATCH-158-E2 — snapshot asset FK before delete so we can refresh its subtotal
            $assetId = $addon->appointment_asset_id;
            $addon->delete();
            if ($assetId) {
                $aa = \App\Models\Tenant\TenantAppointmentAsset::find($assetId);
                if ($aa) $aa->refreshSubtotal();
            }
            $this->recalcAppointmentTotals($appointment);
            return response()->json(['ok' => true]);
        }

        // -------------------------------------------------------------------
        // MARKER-PATCH-158-E1 — Multi-asset operations
        //
        // These ops only make sense when the tenant has multi_asset_enabled.
        // Guarded explicitly rather than relying on view-side gating, so that
        // a stale/malicious request can't sneak through to a tenant that
        // never opted in.
        //
        // Ops added:
        //   - attach_existing_asset  → pivots an existing customer asset onto this appointment
        //   - attach_new_asset       → creates a new customer asset AND attaches it
        //   - detach_asset           → removes the pivot, unpins services (set FK null)
        //   - add_service_to_asset   → creates an item or addon pinned to a specific asset
        // -------------------------------------------------------------------
        if (in_array($op, ['attach_existing_asset', 'attach_new_asset', 'detach_asset', 'add_service_to_asset', 'rename_appointment_asset', 'assign_loose_to_asset', 'assign_loose_to_target', 'add_service_to_target'], true)) {
            if (!$tenant->multi_asset_enabled) {
                return response()->json(['ok' => false, 'message' => 'Multi-asset is not enabled for this tenant.'], 403);
            }
        }

        if ($op === 'attach_existing_asset') {
            $request->validate([
                'customer_asset_id' => ['required', 'uuid'],
            ]);
            $asset = \App\Models\Tenant\TenantCustomerAsset::where('tenant_id', $tenant->id)
                ->where('customer_id', $appointment->customer_id)
                ->where('id', $request->input('customer_asset_id'))
                ->whereNull('archived_at')
                ->first();
            if (!$asset) return response()->json(['ok' => false, 'message' => 'Asset not found or archived.'], 422);

            // Don't double-attach the same asset
            $alreadyAttached = \App\Models\Tenant\TenantAppointmentAsset::where('appointment_id', $appointment->id)
                ->where('customer_asset_id', $asset->id)
                ->exists();
            if ($alreadyAttached) {
                return response()->json(['ok' => false, 'message' => 'Asset already attached to this appointment.'], 422);
            }

            $maxSort = (int) \App\Models\Tenant\TenantAppointmentAsset::where('appointment_id', $appointment->id)
                ->max('sort_order');

            \App\Models\Tenant\TenantAppointmentAsset::create([
                'tenant_id'           => $tenant->id,
                'appointment_id'      => $appointment->id,
                'customer_asset_id'   => $asset->id,
                'asset_name_snapshot' => $asset->name,
                'identifier_snapshot' => $asset->identifier,
                'sort_order'          => $maxSort + 10,
                'subtotal_cents'      => 0,
            ]);

            // Update last-seen on the persistent asset
            $asset->update([
                'last_seen_at'        => now(),
                'last_appointment_id' => $appointment->id,
            ]);

            return response()->json(['ok' => true]);
        }

        if ($op === 'attach_new_asset') {
            $data = $request->validate([
                'name'       => ['required', 'string', 'max:200'],
                'identifier' => ['nullable', 'string', 'max:120'],
                'notes'      => ['nullable', 'string', 'max:5000'],
            ]);

            // Create the persistent customer asset, then attach
            $asset = \App\Models\Tenant\TenantCustomerAsset::create([
                'tenant_id'           => $tenant->id,
                'customer_id'         => $appointment->customer_id,
                'name'                => $data['name'],
                'identifier'          => $data['identifier'] ?? null,
                'notes'               => $data['notes'] ?? null,
                'last_seen_at'        => now(),
                'last_appointment_id' => $appointment->id,
            ]);

            $maxSort = (int) \App\Models\Tenant\TenantAppointmentAsset::where('appointment_id', $appointment->id)
                ->max('sort_order');

            \App\Models\Tenant\TenantAppointmentAsset::create([
                'tenant_id'           => $tenant->id,
                'appointment_id'      => $appointment->id,
                'customer_asset_id'   => $asset->id,
                'asset_name_snapshot' => $asset->name,
                'identifier_snapshot' => $asset->identifier,
                'sort_order'          => $maxSort + 10,
                'subtotal_cents'      => 0,
            ]);

            return response()->json(['ok' => true]);
        }

        if ($op === 'detach_asset') {
            $request->validate(['appointment_asset_id' => ['required', 'uuid']]);
            $aa = \App\Models\Tenant\TenantAppointmentAsset::where('appointment_id', $appointment->id)
                ->where('id', $request->input('appointment_asset_id'))
                ->first();
            if (!$aa) return response()->json(['ok' => false, 'message' => 'Asset attachment not found.'], 422);

            // Unpin all services pinned to this asset rather than deleting them.
            // nullOnDelete in the DB does this automatically when the row is
            // dropped, but doing it explicitly here makes the intent clearer
            // in code.
            \App\Models\Tenant\TenantAppointmentItem::where('appointment_asset_id', $aa->id)
                ->update(['appointment_asset_id' => null]);
            \App\Models\Tenant\TenantAppointmentAddon::where('appointment_asset_id', $aa->id)
                ->update(['appointment_asset_id' => null]);

            $aa->delete();
            $this->recalcAppointmentTotals($appointment);
            return response()->json(['ok' => true]);
        }

        if ($op === 'add_service_to_asset') {
            $data = $request->validate([
                'appointment_asset_id' => ['required', 'uuid'],
                'kind'                 => ['required', 'in:service,addon'],
                'service_item_id'      => ['nullable', 'uuid'],
                'addon_id'             => ['nullable', 'uuid'],
            ]);

            $aa = \App\Models\Tenant\TenantAppointmentAsset::where('appointment_id', $appointment->id)
                ->where('id', $data['appointment_asset_id'])
                ->first();
            if (!$aa) return response()->json(['ok' => false, 'message' => 'Asset not on this appointment.'], 422);

            if ($data['kind'] === 'service') {
                if (empty($data['service_item_id'])) {
                    return response()->json(['ok' => false, 'message' => 'service_item_id required.'], 422);
                }
                $service = \App\Models\Tenant\TenantServiceItem::where('id', $data['service_item_id'])
                    ->where('tenant_id', $tenant->id)->where('is_active', true)->first();
                if (!$service) return response()->json(['ok' => false, 'message' => 'Service not found.'], 422);

                \App\Models\Tenant\TenantAppointmentItem::create([
                    'id'                             => (string) \Illuminate\Support\Str::uuid(),
                    'appointment_id'                 => $appointment->id,
                    'appointment_asset_id'           => $aa->id,
                    'service_item_id'                => $service->id,
                    'item_name_snapshot'             => $service->name,
                    'price_cents'                    => $service->price_cents,
                    'duration_minutes_snapshot'      => $service->duration_minutes,
                    'prep_before_minutes_snapshot'   => $service->prep_before_minutes ?? 0,
                    'cleanup_after_minutes_snapshot' => $service->cleanup_after_minutes ?? 0,
                ]);
            } else {
                if (empty($data['addon_id'])) {
                    return response()->json(['ok' => false, 'message' => 'addon_id required.'], 422);
                }
                $addon = \App\Models\Tenant\TenantAddon::where('id', $data['addon_id'])
                    ->where('tenant_id', $tenant->id)->where('is_active', true)->first();
                if (!$addon) return response()->json(['ok' => false, 'message' => 'Add-on not found.'], 422);

                \App\Models\Tenant\TenantAppointmentAddon::create([
                    'id'                        => (string) \Illuminate\Support\Str::uuid(),
                    'appointment_id'            => $appointment->id,
                    'appointment_asset_id'      => $aa->id,
                    'addon_id'                  => $addon->id,
                    'addon_name_snapshot'       => $addon->name,
                    'price_cents'               => $addon->price_cents,
                    'duration_minutes_snapshot' => $addon->default_duration_minutes ?? 0,
                ]);
            }

            // Recalc this asset's subtotal + the appointment grand total
            $aa->refreshSubtotal();
            $this->recalcAppointmentTotals($appointment);
            return response()->json(['ok' => true]);
        }

        // MARKER-PATCH-470 — move a loose (unassigned) service/add-on under an asset
        if ($op === 'assign_loose_to_asset') {
            $data = $request->validate([
                'appointment_asset_id' => ['required', 'uuid'],
                'kind'                 => ['required', 'in:service,addon'],
                'item_id'              => ['required', 'uuid'],
            ]);

            $aa = \App\Models\Tenant\TenantAppointmentAsset::where('appointment_id', $appointment->id)
                ->where('id', $data['appointment_asset_id'])
                ->first();
            if (!$aa) return response()->json(['ok' => false, 'message' => 'Asset not on this appointment.'], 422);

            $model = $data['kind'] === 'service'
                ? \App\Models\Tenant\TenantAppointmentItem::class
                : \App\Models\Tenant\TenantAppointmentAddon::class;

            $line = $model::where('id', $data['item_id'])
                ->where('appointment_id', $appointment->id)
                ->whereNull('appointment_asset_id')
                ->first();
            if (!$line) return response()->json(['ok' => false, 'message' => 'Unassigned line not found.'], 422);

            $line->update(['appointment_asset_id' => $aa->id]);

            $aa->refreshSubtotal();
            $this->recalcAppointmentTotals($appointment);
            return response()->json(['ok' => true]);
        }

        // MARKER-PATCH-471 — unified assign: ensure the asset is attached (existing
        // appointment asset, a saved customer asset, or a brand-new one), then move the
        // loose line onto it — all atomically, so a failure leaves no half-attached asset.
        if ($op === 'assign_loose_to_target') {
            $data = $request->validate([
                'kind'                 => ['required', 'in:service,addon'],
                'item_id'              => ['required', 'uuid'],
                'target'               => ['required', 'in:appointment_asset,customer_asset,new'],
                'appointment_asset_id' => ['nullable', 'uuid'],
                'customer_asset_id'    => ['nullable', 'uuid'],
                'name'                 => ['nullable', 'string', 'max:200'],
                'identifier'           => ['nullable', 'string', 'max:120'],
                'notes'                => ['nullable', 'string', 'max:5000'],
            ]);

            $model = $data['kind'] === 'service'
                ? \App\Models\Tenant\TenantAppointmentItem::class
                : \App\Models\Tenant\TenantAppointmentAddon::class;
            $line = $model::where('id', $data['item_id'])
                ->where('appointment_id', $appointment->id)
                ->whereNull('appointment_asset_id')
                ->first();
            if (!$line) return response()->json(['ok' => false, 'message' => 'Unassigned line not found.'], 422);

            // Read-only validation up front, so we never start writing on a bad target.
            $customerAsset = null;
            if ($data['target'] === 'appointment_asset') {
                if (empty($data['appointment_asset_id'])) return response()->json(['ok' => false, 'message' => 'appointment_asset_id required.'], 422);
                $exists = \App\Models\Tenant\TenantAppointmentAsset::where('appointment_id', $appointment->id)
                    ->where('id', $data['appointment_asset_id'])->exists();
                if (!$exists) return response()->json(['ok' => false, 'message' => 'Asset not on this appointment.'], 422);
            } elseif ($data['target'] === 'customer_asset') {
                if (empty($data['customer_asset_id'])) return response()->json(['ok' => false, 'message' => 'customer_asset_id required.'], 422);
                $customerAsset = \App\Models\Tenant\TenantCustomerAsset::where('tenant_id', $tenant->id)
                    ->where('customer_id', $appointment->customer_id)
                    ->where('id', $data['customer_asset_id'])
                    ->whereNull('archived_at')
                    ->first();
                if (!$customerAsset) return response()->json(['ok' => false, 'message' => 'Asset not found or archived.'], 422);
            } else {
                if (trim((string) ($data['name'] ?? '')) === '') return response()->json(['ok' => false, 'message' => 'Name is required.'], 422);
            }

            $aa = DB::transaction(function () use ($data, $appointment, $tenant, $customerAsset, $line) {
                if ($data['target'] === 'appointment_asset') {
                    $aa = \App\Models\Tenant\TenantAppointmentAsset::where('appointment_id', $appointment->id)
                        ->where('id', $data['appointment_asset_id'])
                        ->first();
                } elseif ($data['target'] === 'customer_asset') {
                    // Reuse an existing attachment if this asset is somehow already on
                    // the appointment; otherwise attach it now.
                    $aa = \App\Models\Tenant\TenantAppointmentAsset::where('appointment_id', $appointment->id)
                        ->where('customer_asset_id', $customerAsset->id)
                        ->first();
                    if (!$aa) {
                        $maxSort = (int) \App\Models\Tenant\TenantAppointmentAsset::where('appointment_id', $appointment->id)->max('sort_order');
                        $aa = \App\Models\Tenant\TenantAppointmentAsset::create([
                            'tenant_id'           => $tenant->id,
                            'appointment_id'      => $appointment->id,
                            'customer_asset_id'   => $customerAsset->id,
                            'asset_name_snapshot' => $customerAsset->name,
                            'identifier_snapshot' => $customerAsset->identifier,
                            'sort_order'          => $maxSort + 10,
                            'subtotal_cents'      => 0,
                        ]);
                    }
                } else {
                    $asset = \App\Models\Tenant\TenantCustomerAsset::create([
                        'tenant_id'           => $tenant->id,
                        'customer_id'         => $appointment->customer_id,
                        'name'                => trim($data['name']),
                        'identifier'          => $data['identifier'] ?? null,
                        'notes'               => $data['notes'] ?? null,
                        'last_seen_at'        => now(),
                        'last_appointment_id' => $appointment->id,
                    ]);
                    $maxSort = (int) \App\Models\Tenant\TenantAppointmentAsset::where('appointment_id', $appointment->id)->max('sort_order');
                    $aa = \App\Models\Tenant\TenantAppointmentAsset::create([
                        'tenant_id'           => $tenant->id,
                        'appointment_id'      => $appointment->id,
                        'customer_asset_id'   => $asset->id,
                        'asset_name_snapshot' => $asset->name,
                        'identifier_snapshot' => $asset->identifier,
                        'sort_order'          => $maxSort + 10,
                        'subtotal_cents'      => 0,
                    ]);
                }

                $line->update(['appointment_asset_id' => $aa->id]);
                return $aa;
            });

            $aa->refreshSubtotal();
            $this->recalcAppointmentTotals($appointment);
            return response()->json(['ok' => true]);
        }

        // MARKER-PATCH-472 — service-first add: create the service/add-on line and pin it to the
        // chosen target (existing appointment asset, a saved customer asset, a brand-new asset, or
        // leave it loose for "assign later") — all atomically.
        if ($op === 'add_service_to_target') {
            $data = $request->validate([
                'kind'                 => ['required', 'in:service,addon'],
                'service_id'           => ['required', 'uuid'],
                'target'               => ['required', 'in:appointment_asset,customer_asset,new,later'],
                'appointment_asset_id' => ['nullable', 'uuid'],
                'customer_asset_id'    => ['nullable', 'uuid'],
                'name'                 => ['nullable', 'string', 'max:200'],
                'identifier'           => ['nullable', 'string', 'max:120'],
                'notes'                => ['nullable', 'string', 'max:5000'],
            ]);

            if ($data['kind'] === 'service') {
                $svc = \App\Models\Tenant\TenantServiceItem::where('id', $data['service_id'])
                    ->where('tenant_id', $tenant->id)->where('is_active', true)->first();
                if (!$svc) return response()->json(['ok' => false, 'message' => 'Service not found.'], 422);
            } else {
                $svc = \App\Models\Tenant\TenantAddon::where('id', $data['service_id'])
                    ->where('tenant_id', $tenant->id)->where('is_active', true)->first();
                if (!$svc) return response()->json(['ok' => false, 'message' => 'Add-on not found.'], 422);
            }

            $customerAsset = null;
            if ($data['target'] === 'appointment_asset') {
                if (empty($data['appointment_asset_id'])) return response()->json(['ok' => false, 'message' => 'appointment_asset_id required.'], 422);
                $exists = \App\Models\Tenant\TenantAppointmentAsset::where('appointment_id', $appointment->id)
                    ->where('id', $data['appointment_asset_id'])->exists();
                if (!$exists) return response()->json(['ok' => false, 'message' => 'Asset not on this appointment.'], 422);
            } elseif ($data['target'] === 'customer_asset') {
                if (empty($data['customer_asset_id'])) return response()->json(['ok' => false, 'message' => 'customer_asset_id required.'], 422);
                $customerAsset = \App\Models\Tenant\TenantCustomerAsset::where('tenant_id', $tenant->id)
                    ->where('customer_id', $appointment->customer_id)
                    ->where('id', $data['customer_asset_id'])
                    ->whereNull('archived_at')->first();
                if (!$customerAsset) return response()->json(['ok' => false, 'message' => 'Asset not found or archived.'], 422);
            } elseif ($data['target'] === 'new') {
                if (trim((string) ($data['name'] ?? '')) === '') return response()->json(['ok' => false, 'message' => 'Name is required.'], 422);
            }

            $aa = DB::transaction(function () use ($data, $appointment, $tenant, $customerAsset, $svc) {
                $aa = null;
                if ($data['target'] === 'appointment_asset') {
                    $aa = \App\Models\Tenant\TenantAppointmentAsset::where('appointment_id', $appointment->id)
                        ->where('id', $data['appointment_asset_id'])->first();
                } elseif ($data['target'] === 'customer_asset') {
                    $aa = \App\Models\Tenant\TenantAppointmentAsset::where('appointment_id', $appointment->id)
                        ->where('customer_asset_id', $customerAsset->id)->first();
                    if (!$aa) {
                        $maxSort = (int) \App\Models\Tenant\TenantAppointmentAsset::where('appointment_id', $appointment->id)->max('sort_order');
                        $aa = \App\Models\Tenant\TenantAppointmentAsset::create([
                            'tenant_id'           => $tenant->id,
                            'appointment_id'      => $appointment->id,
                            'customer_asset_id'   => $customerAsset->id,
                            'asset_name_snapshot' => $customerAsset->name,
                            'identifier_snapshot' => $customerAsset->identifier,
                            'sort_order'          => $maxSort + 10,
                            'subtotal_cents'      => 0,
                        ]);
                    }
                } elseif ($data['target'] === 'new') {
                    $asset = \App\Models\Tenant\TenantCustomerAsset::create([
                        'tenant_id'           => $tenant->id,
                        'customer_id'         => $appointment->customer_id,
                        'name'                => trim($data['name']),
                        'identifier'          => $data['identifier'] ?? null,
                        'notes'               => $data['notes'] ?? null,
                        'last_seen_at'        => now(),
                        'last_appointment_id' => $appointment->id,
                    ]);
                    $maxSort = (int) \App\Models\Tenant\TenantAppointmentAsset::where('appointment_id', $appointment->id)->max('sort_order');
                    $aa = \App\Models\Tenant\TenantAppointmentAsset::create([
                        'tenant_id'           => $tenant->id,
                        'appointment_id'      => $appointment->id,
                        'customer_asset_id'   => $asset->id,
                        'asset_name_snapshot' => $asset->name,
                        'identifier_snapshot' => $asset->identifier,
                        'sort_order'          => $maxSort + 10,
                        'subtotal_cents'      => 0,
                    ]);
                }
                // 'later' → $aa stays null, line is created loose.

                if ($data['kind'] === 'service') {
                    \App\Models\Tenant\TenantAppointmentItem::create([
                        'id'                             => (string) \Illuminate\Support\Str::uuid(),
                        'appointment_id'                 => $appointment->id,
                        'appointment_asset_id'           => $aa?->id,
                        'service_item_id'                => $svc->id,
                        'item_name_snapshot'             => $svc->name,
                        'price_cents'                    => $svc->price_cents,
                        'duration_minutes_snapshot'      => $svc->duration_minutes,
                        'prep_before_minutes_snapshot'   => $svc->prep_before_minutes ?? 0,
                        'cleanup_after_minutes_snapshot' => $svc->cleanup_after_minutes ?? 0,
                    ]);
                } else {
                    \App\Models\Tenant\TenantAppointmentAddon::create([
                        'id'                        => (string) \Illuminate\Support\Str::uuid(),
                        'appointment_id'            => $appointment->id,
                        'appointment_asset_id'      => $aa?->id,
                        'addon_id'                  => $svc->id,
                        'addon_name_snapshot'       => $svc->name,
                        'price_cents'               => $svc->price_cents,
                        'duration_minutes_snapshot' => $svc->default_duration_minutes ?? 0,
                    ]);
                }
                return $aa;
            });

            if ($aa) $aa->refreshSubtotal();
            $this->recalcAppointmentTotals($appointment);
            return response()->json(['ok' => true]);
        }

        // MARKER-PATCH-158-E2 — rename an appointment-asset (snapshot only,
        // doesn't touch the underlying customer_asset)
        if ($op === 'rename_appointment_asset') {
            $data = $request->validate([
                'appointment_asset_id' => ['required', 'uuid'],
                'name'                 => ['required', 'string', 'max:200'],
            ]);
            $aa = \App\Models\Tenant\TenantAppointmentAsset::where('appointment_id', $appointment->id)
                ->where('id', $data['appointment_asset_id'])
                ->first();
            if (!$aa) return response()->json(['ok' => false, 'message' => 'Asset not on this appointment.'], 422);
            $aa->update(['asset_name_snapshot' => $data['name']]);
            return response()->json(['ok' => true]);
        }

        // -------------------------------------------------------------------
        // Parts: physical inventory items consumed during the appointment.
        // Snapshot-on-add. Stock is checked at add-time (overcommit possible
        // if stock changes between now and completion — by design, we don't
        // hold a reservation). Stock isn't actually decremented until the
        // appointment transitions to a committed status (see status op).
        // -------------------------------------------------------------------

        if ($op === 'add_part') {
            $request->validate([
                'inventory_item_id'     => ['required', 'uuid'],
                'quantity'              => ['nullable', 'integer', 'min:1', 'max:999'],
                'appointment_asset_id'  => ['nullable', 'uuid'], // MARKER-PATCH-158-G4
            ]);

            $invItem = TenantInventoryItem::where('id', $request->input('inventory_item_id'))
                ->where('tenant_id', $tenant->id)
                ->first();
            if (!$invItem) {
                return response()->json(['ok' => false, 'message' => 'Inventory item not found.'], 422);
            }

            $qty = (int) ($request->input('quantity') ?? 1);

            // MARKER-PATCH-158-G4 — validate optional asset FK is on this appointment
            $assetId = $request->input('appointment_asset_id');
            if ($assetId) {
                $assetExists = \App\Models\Tenant\TenantAppointmentAsset::where('appointment_id', $appointment->id)
                    ->where('id', $assetId)
                    ->exists();
                if (!$assetExists) {
                    return response()->json(['ok' => false, 'message' => 'Asset not on this appointment.'], 422);
                }
            }

            // Add-time stock check — warn if we'd go negative without oversell.
            // We don't hard-block; the caller can set allow_oversell on items
            // that should be reservable beyond stock. But for normal items,
            // surface the issue clearly so the front desk knows to order more.
            $currentStock = (int) ($invItem->computed_stock_count ?? 0);
            if ($qty > $currentStock && !$invItem->allow_oversell) {
                return response()->json([
                    'ok'      => false,
                    'message' => "Only {$currentStock} in stock. Adjust quantity, mark the item as oversellable, or order more.",
                ], 422);
            }

            $part = TenantAppointmentPart::create([
                'appointment_id'        => $appointment->id,
                'inventory_item_id'     => $invItem->id,
                'appointment_asset_id'  => $assetId, // MARKER-PATCH-158-G4
                'item_name_snapshot'    => $invItem->name,
                'item_sku_snapshot'     => $invItem->sku,
                'quantity'              => $qty,
                'unit_price_cents'      => (int) ($invItem->effectiveSellPriceCents() ?? 0),
                'cost_cents_at_time'    => $invItem->effectiveCostCents(),
                'is_taxable'            => true,
                'is_special_order'      => $request->boolean('special_order', true), // MARKER-PATCH-419 — default on
                'committed_at'          => null,
            ]);

            // MARKER-PATCH-419 — mirror this part into Special Orders (status 'needed').
            try {
                app(\App\Services\Tenant\SpecialOrderService::class)
                    ->syncForAppointmentPart($part, \Illuminate\Support\Facades\Auth::guard('tenant')->id());
            } catch (\Throwable $e) {
                \Illuminate\Support\Facades\Log::warning('SO sync on part add failed: '.$e->getMessage());
            }

            // Audit note for the activity log.
            TenantAppointmentNote::create([
                'appointment_id'      => $appointment->id,
                'user_id'             => Auth::guard('tenant')->id(),
                'note_type'           => 'system',
                'is_customer_visible' => false,
                'note_content'        => sprintf(
                    '%s added from inventory (qty %d)',
                    $invItem->name,
                    $qty
                ),
                'created_at'          => now(),
            ]);

            $this->recalcAppointmentTotals($appointment);

            return response()->json([
                'ok'   => true,
                'part' => [
                    'id'                 => $part->id,
                    'name'               => $part->item_name_snapshot,
                    'sku'                => $part->item_sku_snapshot,
                    'quantity'           => $part->quantity,
                    'unit_price_cents'   => $part->unit_price_cents,
                    'unit_price_display' => format_money($part->unit_price_cents),
                    'line_total_cents'   => $part->lineTotalCents(),
                    'line_total_display' => format_money($part->lineTotalCents()),
                    'current_stock'      => $currentStock,
                    'projected_stock'    => $currentStock - $qty,
                ],
            ]);
        }

        // Custom item: name + price, no inventory link, doesn't move stock.
        // Useful for one-off shop charges that aren't worth tracking in the
        // inventory module ("scratched paint touch-up — $15"). Stored as a
        // part row with inventory_item_id=null; the inventory service's
        // existing null-check makes commit/refund a no-op for these rows.
        if ($op === 'add_custom_item') {
            $request->validate([
                'name'                 => ['required', 'string', 'max:255'],
                'unit_price_cents'     => ['required', 'integer', 'min:0', 'max:99999999'],
                'quantity'             => ['nullable', 'integer', 'min:1', 'max:999'],
                'is_taxable'           => ['nullable', 'boolean'],
                'appointment_asset_id' => ['nullable', 'uuid'], // MARKER-PATCH-158-G4
            ]);

            $qty = (int) ($request->input('quantity') ?? 1);

            // MARKER-PATCH-158-G4 — validate optional asset FK is on this appointment
            $assetId = $request->input('appointment_asset_id');
            if ($assetId) {
                $assetExists = \App\Models\Tenant\TenantAppointmentAsset::where('appointment_id', $appointment->id)
                    ->where('id', $assetId)
                    ->exists();
                if (!$assetExists) {
                    return response()->json(['ok' => false, 'message' => 'Asset not on this appointment.'], 422);
                }
            }

            $part = TenantAppointmentPart::create([
                'appointment_id'        => $appointment->id,
                'inventory_item_id'     => null,
                'appointment_asset_id'  => $assetId, // MARKER-PATCH-158-G4
                'item_name_snapshot'    => trim($request->input('name')),
                'item_sku_snapshot'     => null,
                'quantity'              => $qty,
                'unit_price_cents'      => (int) $request->input('unit_price_cents'),
                'cost_cents_at_time'    => null,
                'is_taxable'            => $request->boolean('is_taxable', true),
                'is_special_order'      => false, // MARKER-PATCH-419 — custom one-offs aren't ordered
                'committed_at'          => null,
            ]);

            TenantAppointmentNote::create([
                'appointment_id'      => $appointment->id,
                'user_id'             => Auth::guard('tenant')->id(),
                'note_type'           => 'system',
                'is_customer_visible' => false,
                'note_content'        => sprintf(
                    'Custom item added: %s (qty %d)',
                    $part->item_name_snapshot,
                    $qty
                ),
                'created_at'          => now(),
            ]);

            $this->recalcAppointmentTotals($appointment);

            return response()->json([
                'ok'   => true,
                'part' => [
                    'id'                 => $part->id,
                    'name'               => $part->item_name_snapshot,
                    'quantity'           => $part->quantity,
                    'unit_price_cents'   => $part->unit_price_cents,
                    'unit_price_display' => format_money($part->unit_price_cents),
                    'line_total_cents'   => $part->lineTotalCents(),
                    'line_total_display' => format_money($part->lineTotalCents()),
                ],
            ]);
        }

        // MARKER-PATCH-419 — per-line "add to special orders" checkbox toggle
        if ($op === 'toggle_part_special_order') {
            $part = TenantAppointmentPart::where('appointment_id', $appointment->id)
                ->where('id', $request->input('part_id'))
                ->first();
            if (!$part) {
                return response()->json(['ok' => false, 'message' => 'Part not found.'], 422);
            }
            if (!$part->inventory_item_id) {
                return response()->json(['ok' => false, 'message' => 'Custom items can’t be special-ordered.'], 422);
            }
            $part->forceFill(['is_special_order' => $request->boolean('enabled')])->saveQuietly();
            try {
                app(\App\Services\Tenant\SpecialOrderService::class)
                    ->syncForAppointmentPart($part, \Illuminate\Support\Facades\Auth::guard('tenant')->id());
            } catch (\Throwable $e) {
                return response()->json(['ok' => false, 'message' => 'Could not update special order: '.$e->getMessage()], 422);
            }
            $part->refresh()->loadMissing('specialOrder');
            return response()->json([
                'ok'               => true,
                'is_special_order' => (bool) $part->is_special_order,
                'so_number'        => $part->specialOrder?->so_number,
                'so_status'        => $part->specialOrder?->status,
            ]);
        }

        if ($op === 'remove_part') {
            $partId = $request->input('part_id');
            $part = TenantAppointmentPart::where('id', $partId)
                ->where('appointment_id', $appointment->id)
                ->first();
            if (!$part) {
                return response()->json(['ok' => false, 'message' => 'Item not found.'], 422);
            }

            // If this part has already been committed (decremented from stock),
            // we need to give it back BEFORE deleting the row, otherwise the
            // increment helper has nothing to operate on and committed_at
            // tracking is lost.
            if ($part->isCommitted()) {
                $tenantModel = $appointment->tenant;
                $loc = $tenantModel?->defaultLocation;
                if ($loc) {
                    DB::transaction(function () use ($appointment, $part, $loc) {
                        app(\App\Services\Tenant\InventoryService::class)
                            ->incrementForAppointmentPart($appointment, $part, $loc->id);
                    });
                }
            }

            $name = $part->item_name_snapshot;

            // MARKER-SO-ORPHAN-FIX — deleting a part used to leave its linked
            // special order alive as an orphan. Mirror the uncheck logic:
            // a still-"needed" SO is retracted; an ordered/arrived SO stays
            // (goods may be inbound) but the appointment gets a warning note.
            $soWarning = null;
            if ($part->special_order_id) {
                $so = \App\Models\Tenant\TenantSpecialOrder::where('tenant_id', $appointment->tenant_id)
                    ->find($part->special_order_id);
                if ($so && $so->status === \App\Models\Tenant\TenantSpecialOrder::STATUS_NEEDED) {
                    app(\App\Services\Tenant\SpecialOrderService::class)
                        ->cancel($so->id, 'Part removed from work order.');
                } elseif ($so && in_array($so->status, \App\Models\Tenant\TenantSpecialOrder::STATUSES_ACTIVE, true)) {
                    $soWarning = sprintf(' — special order %s is already %s and remains active; review it', $so->so_number, $so->status);
                }
            }

            $part->delete();

            TenantAppointmentNote::create([
                'appointment_id'      => $appointment->id,
                'user_id'             => Auth::guard('tenant')->id(),
                'note_type'           => 'system',
                'is_customer_visible' => false,
                'note_content'        => sprintf('%s removed', $name) . ($soWarning ?? ''),
                'created_at'          => now(),
            ]);

            $this->recalcAppointmentTotals($appointment);
            return response()->json(['ok' => true]);
        }

        if ($op === 'update_part_quantity') {
            $partId = $request->input('part_id');
            $newQty = (int) $request->input('quantity', 0);
            if ($newQty < 1) {
                return response()->json(['ok' => false, 'message' => 'Quantity must be at least 1.'], 422);
            }

            $part = TenantAppointmentPart::where('id', $partId)
                ->where('appointment_id', $appointment->id)
                ->first();
            if (!$part) {
                return response()->json(['ok' => false, 'message' => 'Item not found.'], 422);
            }

            // Prevent quantity edits after the appointment has been completed
            // — but only for inventory-linked parts where stock is actually
            // decremented. Custom items don't move stock, so editing their
            // quantity post-commit is harmless.
            if ($part->isCommitted() && $part->inventory_item_id) {
                return response()->json([
                    'ok'      => false,
                    'message' => 'Move appointment back to In Progress to edit committed items.',
                ], 422);
            }

            // Add-time stock re-check on the new quantity.
            if ($part->inventory_item_id) {
                $invItem = TenantInventoryItem::find($part->inventory_item_id);
                if ($invItem) {
                    $stock = (int) ($invItem->computed_stock_count ?? 0);
                    if ($newQty > $stock && !$invItem->allow_oversell) {
                        return response()->json([
                            'ok'      => false,
                            'message' => "Only {$stock} in stock.",
                        ], 422);
                    }
                }
            }

            $part->update(['quantity' => $newQty]);
            $this->recalcAppointmentTotals($appointment);
            return response()->json([
                'ok' => true,
                'line_total_cents'   => $part->lineTotalCents(),
                'line_total_display' => format_money($part->lineTotalCents()),
            ]);
        }

        if ($op === 'update_line_item') {
            $itemId   = $request->input('item_id');
            $kind     = $request->input('kind', 'service');  // 'service' or 'addon'
            $price    = $request->input('price_cents');      // null = clear override
            $duration = $request->input('duration_minutes'); // null = clear override

            $model = $kind === 'addon'
                ? \App\Models\Tenant\TenantAppointmentAddon::class
                : \App\Models\Tenant\TenantAppointmentItem::class;
            $row = $model::where('id', $itemId)->where('appointment_id', $appointment->id)->first();
            if (!$row) return response()->json(['ok' => false, 'message' => 'Line item not found.'], 422);

            $row->price_cents_override      = ($price === null || $price === '')      ? null : (int) $price;
            $row->duration_minutes_override = ($duration === null || $duration === '') ? null : (int) $duration;
            $row->save();
            // MARKER-PATCH-158-E2 — refresh asset subtotal if this row is pinned to one
            if ($row->appointment_asset_id) {
                $aa = \App\Models\Tenant\TenantAppointmentAsset::find($row->appointment_asset_id);
                if ($aa) $aa->refreshSubtotal();
            }
            $this->recalcAppointmentTotals($appointment);
            return response()->json(['ok' => true]);
        }

        if ($op === 'change_resource') {
            $newResourceId = $request->input('resource_id');
            $force         = (bool) $request->input('force', false);

            if (!$newResourceId) {
                return response()->json([
                    'ok' => false, 'message' => 'Resource is required.'
                ], 422);
            }

            // Validate the resource belongs to this tenant and is active.
            $resource = \App\Models\Tenant\TenantResource::where('id', $newResourceId)
                ->where('tenant_id', $appointment->tenant_id)
                ->where('is_active', true)
                ->first();

            if (!$resource) {
                return response()->json([
                    'ok' => false, 'message' => 'Selected resource is not available.'
                ], 422);
            }

            // No-op: same resource selected.
            if ($resource->id === $appointment->resource_id) {
                return response()->json(['ok' => true, 'unchanged' => true]);
            }

            $oldResource = \App\Models\Tenant\TenantResource::find($appointment->resource_id);
            $oldName     = $oldResource?->name ?? 'Unassigned';
            $newName     = $resource->name;

            // Conflict check — unless force is set, refuse to move into a
            // busy slot. Returns 409 with the conflicting appointment's
            // shape so the JS can render a clear confirm modal.
            if (!$force) {
                $apptDate = $appointment->appointment_date instanceof \Carbon\Carbon
                    ? $appointment->appointment_date->toDateString()
                    : (string) $appointment->appointment_date;

                $bookingService = app(\App\Services\BookingService::class);
                $conflict = $bookingService->resourceIsFreeDuring(
                    $appointment->tenant_id,
                    $resource->id,
                    $apptDate,
                    $appointment->appointment_time,
                    (int) $appointment->total_duration_minutes,
                    $appointment->id
                );

                if ($conflict) {
                    return response()->json([
                        'ok'        => false,
                        'conflict'  => $conflict,
                        'old_name'  => $oldName,
                        'new_name'  => $newName,
                        'message'   => 'That resource is busy at this time.',
                    ], 409);
                }
            }

            // Apply the change.
            $appointment->resource_id = $resource->id;
            $appointment->save();

            // Audit note. Mirrors the status-change note pattern.
            $noteContent = $force
                ? sprintf('Resource changed from %s to %s (override — conflict accepted).', $oldName, $newName)
                : sprintf('Resource changed from %s to %s.', $oldName, $newName);

            \App\Models\Tenant\TenantAppointmentNote::create([
                'appointment_id'      => $appointment->id,
                'user_id'             => \Illuminate\Support\Facades\Auth::guard('tenant')->id(),
                'note_type'           => 'system',
                'is_customer_visible' => false,
                'note_content'        => $noteContent,
                'created_at'          => now(),
            ]);

            return response()->json([
                'ok'           => true,
                'resource_id'  => $resource->id,
                'resource_name'=> $newName,
                'forced'       => $force,
            ]);
        }

        if ($op === 'slot_weight') {
            $newWeight = (int) $request->input('slot_weight', 0);
            if ($newWeight < 1 || $newWeight > 4) {
                return response()->json([
                    'ok' => false, 'message' => 'Slot weight must be between 1 and 4.'
                ], 422);
            }

            $oldWeight = (int) $appointment->slot_weight;
            if ($newWeight === $oldWeight) {
                return response()->json(['ok' => true, 'unchanged' => true]);
            }

            $appointment->slot_weight = $newWeight;
            $appointment->slot_weight_overridden = true;
            $appointment->save();

            // Audit note — mirrors status-change + resource-change pattern.
            \App\Models\Tenant\TenantAppointmentNote::create([
                'appointment_id'      => $appointment->id,
                'user_id'             => \Illuminate\Support\Facades\Auth::guard('tenant')->id(),
                'note_type'           => 'system',
                'is_customer_visible' => false,
                'note_content'        => sprintf('Slot weight changed from %d to %d.', $oldWeight, $newWeight),
                'created_at'          => now(),
            ]);

            return response()->json([
                'ok'          => true,
                'slot_weight' => $newWeight,
                'overridden'  => true,
            ]);
        }

        if ($op === 'reschedule_time') {
            // Drag-to-reschedule: change appointment_time and optionally resource_id
            // in one operation. Mirrors the change_resource pattern with a soft-warn
            // on conflicts and an audit note on success.
            $newTime       = $request->input('appointment_time');  // HH:MM:SS
            $newResourceId = $request->input('resource_id');       // optional, may match current
            $force         = (bool) $request->input('force', false);

            if (!$newTime) {
                return response()->json([
                    'ok' => false, 'message' => 'New time is required.'
                ], 422);
            }

            // Normalize H:i to H:i:s if needed (drag JS sends HH:MM:00 already, but be defensive)
            $parts = explode(':', $newTime);
            if (count($parts) === 2) $newTime = $newTime . ':00';

            // Resolve target resource — defaults to current if not supplied
            $targetResourceId = $newResourceId ?: $appointment->resource_id;
            $resource = \App\Models\Tenant\TenantResource::where('id', $targetResourceId)
                ->where('tenant_id', $appointment->tenant_id)
                ->where('is_active', true)
                ->first();

            if (!$resource) {
                return response()->json([
                    'ok' => false, 'message' => 'Selected resource is not available.'
                ], 422);
            }

            // No-op detection: same time + same resource = nothing to do
            $currentTime = $appointment->appointment_time;
            if ($targetResourceId === $appointment->resource_id && $currentTime === $newTime) {
                return response()->json(['ok' => true, 'unchanged' => true]);
            }

            // Resolve appointment date as string for conflict check
            $apptDate = $appointment->appointment_date instanceof \Carbon\Carbon
                ? $appointment->appointment_date->toDateString()
                : (string) $appointment->appointment_date;

            // Conflict check unless override is in effect
            if (!$force) {
                $bookingService = app(\App\Services\BookingService::class);
                $conflict = $bookingService->resourceIsFreeDuring(
                    $appointment->tenant_id,
                    $resource->id,
                    $apptDate,
                    $newTime,
                    (int) $appointment->total_duration_minutes,
                    $appointment->id  // exclude self
                );

                if ($conflict) {
                    $oldResource = \App\Models\Tenant\TenantResource::find($appointment->resource_id);
                    return response()->json([
                        'ok'        => false,
                        'conflict'  => $conflict,
                        'old_name'  => $oldResource?->name ?? 'current resource',
                        'new_name'  => $resource->name,
                        'message'   => 'That slot is busy.',
                    ], 409);
                }
            }

            // Capture before-state for the audit note
            $oldTime         = $appointment->appointment_time;
            $oldResource     = \App\Models\Tenant\TenantResource::find($appointment->resource_id);
            $oldResourceName = $oldResource?->name ?? 'unassigned';
            $newResourceName = $resource->name;

            // Compute new end time. total_duration already includes prep + cleanup.
            $startDateTime = new \DateTimeImmutable($apptDate . ' ' . $newTime);
            $endDateTime   = $startDateTime->modify('+' . (int) $appointment->total_duration_minutes . ' minutes');

            $appointment->appointment_time     = $newTime;
            $appointment->appointment_end_time = $endDateTime->format('H:i:s');
            $appointment->resource_id          = $resource->id;
            $appointment->save();

            // Audit note — describe what actually changed.
            $resourceChanged = $oldResource?->id !== $resource->id;
            $timeChanged     = $oldTime !== $newTime;

            $formatTime = function (string $hms): string {
                $t = \Carbon\Carbon::createFromFormat('H:i:s', $hms);
                return $t ? $t->format('g:i A') : $hms;
            };

            if ($resourceChanged && $timeChanged) {
                $note = sprintf(
                    'Rescheduled from %s on %s to %s on %s.',
                    $formatTime($oldTime), $oldResourceName,
                    $formatTime($newTime), $newResourceName
                );
            } elseif ($timeChanged) {
                $note = sprintf('Rescheduled from %s to %s.', $formatTime($oldTime), $formatTime($newTime));
            } else {
                $note = sprintf('Resource changed from %s to %s.', $oldResourceName, $newResourceName);
            }

            if ($force) {
                $note .= ' (override — conflict accepted)';
            }

            \App\Models\Tenant\TenantAppointmentNote::create([
                'appointment_id'      => $appointment->id,
                'user_id'             => \Illuminate\Support\Facades\Auth::guard('tenant')->id(),
                'note_type'           => 'system',
                'is_customer_visible' => false,
                'note_content'        => $note,
                'created_at'          => now(),
            ]);

            return response()->json([
                'ok'              => true,
                'appointment_time'=> $newTime,
                'resource_id'     => $resource->id,
                'resource_name'   => $resource->name,
                'forced'          => $force,
            ]);
        }

        return response()->json(['ok' => false, 'message' => 'Unknown operation.'], 422);
    }
    /**
     * Recalculate appointment totals from current items + addons.
     * Uses effective (override-aware) values. Called after any line-item mutation.
     */
    /**
     * Lightweight sale number generator for deposit sales spawned from the
     * appointment page. Format: DP-YYYYMMDD-### per tenant.
     *
     * Uses a different prefix than register-spawned sales so reports can
     * easily distinguish deposit-collection sales from regular completion
     * sales if needed.
     */
    private function generateDepositSaleNumber(string $tenantId): string
    {
        $today = now()->format('Ymd');
        $prefix = "DP-{$today}-";
        $maxNumber = \DB::table('tenant_sales')
            ->where('tenant_id', $tenantId)
            ->where('sale_number', 'like', $prefix . '%')
            ->orderByDesc('sale_number')
            ->value('sale_number');

        $next = 1;
        if ($maxNumber) {
            $parts = explode('-', $maxNumber);
            $lastNum = (int) end($parts);
            $next = $lastNum + 1;
        }

        return $prefix . str_pad((string) $next, 3, '0', STR_PAD_LEFT);
    }

    /**
     * Search active inventory items for the part-picker autocomplete.
     * Returns minimal payload so the picker can render fast.
     */
    public function searchInventoryItems(Request $request)
    {
        $tenant = tenant();
        $q = trim((string) $request->input('q', ''));

        $query = TenantInventoryItem::where('tenant_id', $tenant->id)
            ->where('is_active', true);

        if ($q !== '') {
            $query->where(function ($w) use ($q) {
                $w->where('name', 'like', "%{$q}%")
                  ->orWhere('sku',  'like', "%{$q}%");
            });
        }

        $items = $query->orderBy('name')->limit(15)->get(['id', 'name', 'sku', 'shop_sell_price_cents', 'catalog_msrp_cents', 'computed_stock_count', 'allow_oversell']);

        return response()->json([
            'ok'    => true,
            'items' => $items->map(function ($i) {
                $price = (int) ($i->effectiveSellPriceCents() ?? 0);
                return [
                    'id'             => $i->id,
                    'name'           => $i->name,
                    'sku'            => $i->sku,
                    'price_cents'    => $price,
                    'price_display'  => format_money($price),
                    'stock'          => (int) ($i->computed_stock_count ?? 0),
                    'allow_oversell' => (bool) $i->allow_oversell,
                ];
            })->values(),
        ]);
    }

    /**
     * Recompute subtotal, tax, and total for an appointment based on the
     * current items, addons, and parts. Honors:
     *   - tenant.tax_services_default for service items + addons
     *   - per-part is_taxable for parts
     *   - tenant.default_tax_rate for the actual rate
     *
     * Tax is computed on each line independently (not on the subtotal) so
     * fractional cents from rounding don't compound.
     */
    protected function recalcAppointmentTotals(\App\Models\Tenant\TenantAppointment $appointment): void
    {
        $appointment->load(['items', 'addons', 'parts', 'tenant']);

        $tenant = $appointment->tenant;
        $taxRate = (float) ($tenant->default_tax_rate ?? 0); // e.g. 8.875 for 8.875%
        $servicesTaxable = (bool) ($tenant->tax_services_default ?? true);

        $subtotalCents = 0;
        $taxCents      = 0;
        $totalDuration = 0;

        // Services
        foreach ($appointment->items as $item) {
            $line = (int) $item->effectivePriceCents();
            $subtotalCents += $line;
            $totalDuration += (int) $item->prep_before_minutes_snapshot
                            + $item->effectiveDurationMinutes()
                            + (int) $item->cleanup_after_minutes_snapshot;

            if ($servicesTaxable && $taxRate > 0) {
                $taxCents += (int) round($line * $taxRate / 100);
            }
        }

        // Addons (treated like services for taxability — they're service extras)
        foreach ($appointment->addons as $addon) {
            $line = (int) $addon->effectivePriceCents();
            $subtotalCents += $line;
            $totalDuration += $addon->effectiveDurationMinutes();

            if ($servicesTaxable && $taxRate > 0) {
                $taxCents += (int) round($line * $taxRate / 100);
            }
        }

        // Parts (per-row taxability)
        foreach ($appointment->parts as $part) {
            $line = (int) $part->lineTotalCents();
            $subtotalCents += $line;
            // Parts don't have a duration — they're physical goods.

            if ($part->is_taxable && $taxRate > 0) {
                $taxCents += (int) round($line * $taxRate / 100);
            }
        }

        // Recompute appointment_end_time if the appointment has a start time
        $endTime = $appointment->appointment_end_time;
        if ($appointment->appointment_time && $totalDuration > 0) {
            $start = new \DateTimeImmutable($appointment->appointment_time);
            $end   = $start->modify("+{$totalDuration} minutes");
            $endTime = $end->format('H:i:s');
        }

        $appointment->update([
            'subtotal_cents'         => $subtotalCents,
            'tax_cents'              => $taxCents,
            'total_cents'            => $subtotalCents + $taxCents,
            'total_duration_minutes' => $totalDuration,
            'appointment_end_time'   => $endTime,
        ]);
    }

}
DELRES_3_EOF

cat > 'resources/views/tenant/appointments/_delivery_propose_modal.blade.php' <<'DELRES_4_EOF'
{{-- MARKER-PATCH-534 — completion modal, reworked: pick a window and the
     primary button says exactly what goes out (pills toggle text/email);
     no window selected = text the options. No hidden state, no checkbox,
     and no assume-first — no reply just surfaces on the dashboard. --}}
<div id="dp-modal" style="display:none;position:fixed;inset:0;z-index:220;align-items:center;justify-content:center;background:rgba(0,0,0,.55);backdrop-filter:blur(2px)">
  <div style="background:var(--ia-surface);border:0.5px solid var(--ia-border);border-radius:16px;padding:24px 26px;width:min(480px,calc(100vw - 32px));box-shadow:0 24px 60px rgba(0,0,0,.5)">
    <div style="font-size:17px;font-weight:700;margin-bottom:4px;text-transform:capitalize" id="dp-modal-title">Done &mdash; delivery?</div>{{-- MARKER-PATCH-535 — title uses tenant asset noun via payload --}}
    <div style="font-size:13px;color:var(--ia-text-muted);margin-bottom:16px" id="dp-modal-sub"></div>
    <div id="dp-modal-windows" style="display:flex;flex-direction:column;gap:8px;margin-bottom:4px"></div>
    {{-- MARKER-PATCH-535 — always visible, full modal width --}}
    <div id="dp-modal-notify" style="display:flex;align-items:center;gap:10px;margin:14px 0 2px;width:100%">
      <span style="font-size:12.5px;color:var(--ia-text-muted);flex:none" id="dp-notify-lbl">Notify by:</span>
      <button type="button" class="dp-pill" id="dp-pill-text" data-on="1" style="flex:1;justify-content:center"><span class="dp-tick">&#10003;</span>Text</button>
      <button type="button" class="dp-pill" id="dp-pill-email" data-on="1" style="flex:1;justify-content:center"><span class="dp-tick">&#10003;</span>Email</button>
    </div>
    <div style="font-size:12px;color:var(--ia-text-muted);margin:11px 0 16px;min-height:17px" id="dp-modal-hint"></div>
    {{-- MARKER-PATCH-536 — full-width action row: skip 1/3, primary 2/3 --}}
    <button type="button" id="dp-modal-clear" style="display:none;background:none;border:0;color:var(--ia-text-muted);font-size:12px;font-family:inherit;cursor:pointer;text-decoration:underline;text-underline-offset:3px;margin-bottom:10px">clear selection</button>
    <div style="display:flex;align-items:stretch;gap:12px;width:100%">
      <button type="button" class="ia-btn ia-btn--ghost" id="dp-modal-skip" style="flex:1">Not yet</button>
      <button type="button" class="ia-btn ia-btn--primary" id="dp-modal-go" style="flex:2">Text the options</button>
    </div>
    {{-- MARKER-DELIVERY-RESOLUTION — third outcome: fully done, no delivery.
         Styled apart from the action row so it reads as a resolution rather
         than a second way to dismiss the modal. --}}
    <button type="button" id="dp-modal-done"
      style="width:100%;margin-top:8px;background:none;border:1px solid rgba(127,217,143,.4);color:#7FD98F;border-radius:10px;padding:11px;font-family:inherit;font-size:13px;font-weight:700;cursor:pointer">
      &#10003; No delivery needed &mdash; they have it
    </button>
  </div>
</div>
<style>
  /* MARKER-PATCH-534 */
  .dp-pill{display:inline-flex;align-items:center;gap:6px;border:1px solid var(--ia-accent);background:var(--ia-accent-soft,rgba(212,255,63,.08));color:var(--ia-accent);border-radius:99px;padding:4px 12px;font-size:12px;font-weight:600;cursor:pointer;font-family:inherit;user-select:none}
  .dp-pill[data-on="0"]{border-color:var(--ia-border);background:none;color:var(--ia-text-muted)}
  .dp-pill[data-on="0"] .dp-tick{visibility:hidden}
  .dp-win{display:flex;align-items:center;gap:10px;border:0.5px solid var(--ia-border);border-radius:11px;padding:11px 13px;font-size:13px;background:none;color:var(--ia-text);font-family:inherit;cursor:pointer;width:100%;text-align:left}
  .dp-win:hover{border-color:var(--ia-text-muted)}
  .dp-win.sel{border-color:var(--ia-accent);background:var(--ia-accent-soft,rgba(212,255,63,.08))}
</style>
<script>
window.IntakeDeliveryPropose = (function () {
  var modal, updateUrl, csrf, selected, firstName;

  function esc(s) { var d = document.createElement('div'); d.textContent = s == null ? '' : String(s); return d.innerHTML; }
  function pillOn(id) { return document.getElementById(id).dataset.on === '1'; }

  function show(payload, opts) {
    modal     = document.getElementById('dp-modal');
    updateUrl = (opts && opts.updateUrl) || window.__dpUpdateUrl;
    csrf      = (opts && opts.csrf) || (document.querySelector('meta[name="csrf-token"]') || {}).content;
    if (!modal || !updateUrl || !payload) return false;

    selected  = null;
    firstName = (payload.customer_name || 'the customer').split(' ')[0];
    // MARKER-PATCH-535 — tenant asset noun, never hardcoded
    var noun = payload.asset_noun || 'work';
    // MARKER-DELIVERY-RESOLUTION — "delivery?" invited a yes/no, which is how
    // Skip became a dumping ground for three different situations.
    document.getElementById('dp-modal-title').textContent = 'How is this getting back to them?';
    document.getElementById('dp-modal-sub').innerHTML =
      'Pick a window for <b style="color:var(--ia-text)">' + esc(payload.customer_name) + '</b> (&hellip;' + esc(payload.phone_tail) + '), or text the options and let them choose.';
    document.getElementById('dp-notify-lbl').textContent = 'Notify ' + firstName + ' by:';

    var list = document.getElementById('dp-modal-windows');
    list.innerHTML = '';
    (payload.windows || []).forEach(function (w, i) {
      var row = document.createElement('button');
      row.type = 'button';
      row.className = 'dp-win';
      row.innerHTML = '<span style="font-weight:700">' + esc(w.day_label) + '</span><span>' + esc(w.label) + '</span>'
        + '<span style="margin-left:auto;font-size:11px;color:var(--ia-text-muted)">' + esc(w.remaining) + ' stop' + (w.remaining === 1 ? '' : 's') + ' left</span>';
      row.onclick = function () {
        if (selected === w) { selected = null; row.classList.remove('sel'); }
        else {
          selected = w;
          Array.prototype.forEach.call(list.children, function (c) { c.classList.remove('sel'); });
          row.classList.add('sel');
        }
        render();
      };
      list.appendChild(row);
    });

    document.getElementById('dp-pill-text').onclick  = function () { this.dataset.on = this.dataset.on === '1' ? '0' : '1'; render(); };
    document.getElementById('dp-pill-email').onclick = function () { this.dataset.on = this.dataset.on === '1' ? '0' : '1'; render(); };
    document.getElementById('dp-pill-text').dataset.on = '1';
    document.getElementById('dp-pill-email').dataset.on = '1';
    document.getElementById('dp-modal-go').disabled = false; // MARKER-PATCH-535
    document.getElementById('dp-modal-skip').onclick  = function () { close(true); };

    // MARKER-DELIVERY-RESOLUTION — the third outcome. "Not yet" leaves the job
    // on the Awaiting delivery queue (correctly — nobody has contacted them);
    // this records that no delivery is needed at all, so it never queues.
    var doneBtn = document.getElementById('dp-modal-done');
    if (doneBtn) doneBtn.onclick = function () {
      var original = doneBtn.textContent;
      doneBtn.disabled = true; doneBtn.textContent = 'Saving\u2026';
      var fd = new FormData();
      fd.append('_token', csrf);
      fd.append('_method', 'PATCH');
      fd.append('op', 'delivery_resolution');
      fd.append('resolution', 'customer_pickup');
      fetch(updateUrl, { method: 'POST', body: fd, headers: { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' } })
        .then(function (r) { return r.json(); })
        .then(function (j) {
          if (j && j.ok) {
            if (window.IntakeToast) IntakeToast.success('Marked as no delivery needed');
            close(true);
          } else {
            doneBtn.disabled = false; doneBtn.textContent = original;
            if (window.IntakeToast) IntakeToast.error((j && j.message) || 'Could not save.');
          }
        })
        .catch(function () {
          doneBtn.disabled = false; doneBtn.textContent = original;
          if (window.IntakeToast) IntakeToast.error('Network error.');
        });
    };
    document.getElementById('dp-modal-clear').onclick = function () {
      selected = null;
      Array.prototype.forEach.call(list.children, function (c) { c.classList.remove('sel'); });
      render();
    };
    document.getElementById('dp-modal-go').onclick = go;

    render();
    modal.style.display = 'flex';
    return true;
  }

  function render() {
    // MARKER-PATCH-535 — pills always visible; button label carries the consequence
    var goBtn = document.getElementById('dp-modal-go');
    var hint  = document.getElementById('dp-modal-hint');
    var clear = document.getElementById('dp-modal-clear');
    var t = pillOn('dp-pill-text'), e = pillOn('dp-pill-email');
    if (!selected) {
      clear.style.display = 'none';
      // MARKER-PATCH-536 — options mode honors the pills
      goBtn.textContent =
        t && e ? 'Text & email ' + firstName + ' the options' :
        t      ? 'Text ' + firstName + ' the options' :
        e      ? 'Email ' + firstName + ' the options' :
                 'Text ' + firstName + ' the options';
      goBtn.disabled = !t && !e;
      hint.textContent = (t || e)
        ? 'They pick from a link; if they don\u2019t reply, the appointment shows on your dashboard as awaiting delivery.'
        : 'Turn on Text or Email to send the options \u2014 or pick a window to schedule it yourself.';
      return;
    }
    clear.style.display = 'block';
    goBtn.disabled = false;
    goBtn.textContent =
      t && e ? 'Schedule + text & email' :
      t      ? 'Schedule + text' :
      e      ? 'Schedule + email' :
               'Schedule silently';
    hint.textContent = (t || e)
      ? 'Books ' + selected.day_label + ' ' + selected.label + ' and sends the confirmation.'
      : 'Books ' + selected.day_label + ' ' + selected.label + '. ' + firstName + ' gets nothing \u2014 you tell them yourself.';
  }

  function close(reload) {
    if (modal) modal.style.display = 'none';
    if (reload) window.location.reload();
  }

  function go() {
    var btn = document.getElementById('dp-modal-go');
    var original = btn.textContent;
    btn.disabled = true; btn.textContent = 'Working\u2026';
    var fd = new FormData();
    fd.append('_token', csrf);
    fd.append('_method', 'PATCH');
    if (selected) {
      fd.append('op', 'delivery_schedule_direct');
      fd.append('window_id', selected.window_id);
      fd.append('date', selected.date);
      fd.append('notify_sms',   pillOn('dp-pill-text')  ? '1' : '0');
      fd.append('notify_email', pillOn('dp-pill-email') ? '1' : '0');
    } else {
      fd.append('op', 'delivery_proposal_send');
      fd.append('notify_sms',   pillOn('dp-pill-text')  ? '1' : '0'); // MARKER-PATCH-536
      fd.append('notify_email', pillOn('dp-pill-email') ? '1' : '0');
    }
    fetch(updateUrl, { method: 'POST', body: fd, headers: { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' } })
      .then(function (r) { return r.json(); })
      .then(function (j) {
        if (j && j.ok) {
          if (window.IntakeToast) IntakeToast.success(selected ? original.replace('Schedule', 'Scheduled') : 'Options texted');
          close(true);
        } else {
          btn.disabled = false; btn.textContent = original;
          if (window.IntakeToast) IntakeToast.error((j && j.message) || 'Could not complete.');
        }
      })
      .catch(function () {
        btn.disabled = false; btn.textContent = original;
        if (window.IntakeToast) IntakeToast.error('Network error. Try again.');
      });
  }

  return { show: show };
})();
</script>
DELRES_4_EOF

cat > 'resources/views/tenant/appointments/_awaiting_delivery_panel.blade.php' <<'DELRES_5_EOF'
{{-- MARKER-DELIVERY-RESOLUTION — triage panel for the Awaiting delivery queue.
     Every row states WHY it is still here and carries the actions that
     resolve it, so jobs leave this list because someone decided something
     rather than because the 14-day window forgot them. --}}
<style>
  .adp{background:var(--ia-surface);border:0.5px solid var(--ia-border);border-radius:var(--ia-r-lg);overflow:hidden;margin-bottom:18px}
  .adp-hd{display:flex;align-items:baseline;gap:10px;padding:14px 16px;border-bottom:0.5px solid var(--ia-border);flex-wrap:wrap}
  .adp-hd .t{font-size:14px;font-weight:800;letter-spacing:-.01em}
  .adp-hd .s{font-size:12px;color:var(--ia-text-muted)}
  .adp-row{display:flex;align-items:center;gap:12px;padding:13px 16px;border-bottom:0.5px solid var(--ia-border);flex-wrap:wrap}
  .adp-row:last-child{border-bottom:none}
  .adp-row.gone{opacity:.4}
  .adp-id{font-size:11.5px;color:var(--ia-text-muted);width:78px;flex:none}
  .adp-ident{flex:1;min-width:160px}
  .adp-nm{font-weight:600;font-size:13.5px}
  .adp-meta{font-size:11.5px;color:var(--ia-text-muted);margin-top:2px}
  .adp-why{font-size:10px;font-weight:800;letter-spacing:.07em;text-transform:uppercase;border-radius:100px;padding:3px 9px;white-space:nowrap;flex:none}
  .adp-why.none{background:rgba(240,149,149,.1);color:#F09595;border:0.5px solid rgba(240,149,149,.3)}
  .adp-why.sent{background:rgba(232,163,61,.1);color:#E8A33D;border:0.5px solid rgba(232,163,61,.32)}
  .adp-why.replied{background:rgba(143,184,240,.1);color:#8FB8F0;border:0.5px solid rgba(143,184,240,.32)}
  .adp-age{font-size:11.5px;color:var(--ia-text-muted);white-space:nowrap;flex:none}
  .adp-age.old{color:#F09595;font-weight:700}
  .adp-acts{display:flex;gap:6px;flex-wrap:wrap;flex:none}
  .adp-btn{font-family:inherit;font-size:11.5px;font-weight:700;border-radius:8px;padding:7px 11px;cursor:pointer;border:0.5px solid var(--ia-border);background:transparent;color:var(--ia-text);white-space:nowrap;text-decoration:none;display:inline-block}
  .adp-btn:hover{border-color:var(--ia-accent)}
  .adp-btn.done{color:#7FD98F;border-color:rgba(127,217,143,.4)}
  .adp-btn.muted{color:var(--ia-text-muted)}
  .adp-ok{font-size:11.5px;color:#7FD98F;font-weight:600;white-space:nowrap}
</style>

<div class="adp" id="adp">
  <div class="adp-hd">
    <span class="t">Getting these back to customers</span>
    <span class="s">Resolve each one — scheduling a drop-off, or recording that the customer already has it.</span>
  </div>

  @foreach($appointments as $appt)
    @php
      $why = $deliveryWhy[$appt->id] ?? null;
      $whyKey = $why === null ? 'none' : ($why === 'no_reply' ? 'sent' : ($why === 'sent' ? 'sent' : 'replied'));
      $whyLabel = [
        'none'    => 'no contact yet',
        'sent'    => $why === 'no_reply' ? 'no reply yet' : 'options sent',
        'replied' => 'replied — needs scheduling',
      ][$whyKey];
      $days = $appt->completed_at ? (int) $appt->completed_at->diffInDays(now()) : 0;
    @endphp
    <div class="adp-row" data-ad-row data-id="{{ $appt->id }}">
      <span class="adp-id">{{ $appt->ra_number }}</span>
      <div class="adp-ident">
        <div class="adp-nm">{{ trim(($appt->customer->first_name ?? '') . ' ' . ($appt->customer->last_name ?? '')) ?: 'Customer' }}</div>
        <div class="adp-meta">
          Completed {{ $appt->completed_at ? $appt->completed_at->setTimezone(tenant()->timezone())->format('M j · g:ia') : '—' }}
        </div>
      </div>
      <span class="adp-why {{ $whyKey }}">{{ $whyLabel }}</span>
      <span class="adp-age {{ $days >= 10 ? 'old' : '' }}">{{ $days }}d waiting</span>
      <div class="adp-acts">
        <a class="adp-btn" href="{{ route('tenant.appointments.show', $appt->id) }}">Open</a>
        <button type="button" class="adp-btn done" data-ad-resolve="customer_pickup">Picked up</button>
        <button type="button" class="adp-btn done" data-ad-resolve="not_needed">No delivery needed</button>
        <button type="button" class="adp-btn muted" data-ad-snooze="3">Snooze 3d</button>
      </div>
    </div>
  @endforeach
</div>

<script>
(function () {
  var root = document.getElementById('adp');
  if (!root) return;
  var url  = @json(route('tenant.appointments.update', ['id' => '__ID__']));
  var csrf = (document.querySelector('meta[name="csrf-token"]') || {}).content;

  function patch(row, fields, okText) {
    var id = row.dataset.id;
    var fd = new FormData();
    fd.append('_token', csrf);
    fd.append('_method', 'PATCH');
    Object.keys(fields).forEach(function (k) { fd.append(k, fields[k]); });

    row.querySelectorAll('button').forEach(function (b) { b.disabled = true; });

    fetch(url.replace('__ID__', id), {
      method: 'POST', body: fd,
      headers: { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' }
    })
      .then(function (r) { return r.json(); })
      .then(function (j) {
        if (j && j.ok) {
          row.classList.add('gone');
          var acts = row.querySelector('.adp-acts');
          acts.innerHTML = '<span class="adp-ok">\u2713 ' + okText + '</span>' +
                           ' <button type="button" class="adp-btn muted" data-ad-undo>Undo</button>';
          acts.querySelector('[data-ad-undo]').addEventListener('click', function () {
            patchUndo(row);
          });
          if (window.IntakeToast) IntakeToast.success(okText);
        } else {
          row.querySelectorAll('button').forEach(function (b) { b.disabled = false; });
          if (window.IntakeToast) IntakeToast.error((j && j.message) || 'Could not save.');
        }
      })
      .catch(function () {
        row.querySelectorAll('button').forEach(function (b) { b.disabled = false; });
        if (window.IntakeToast) IntakeToast.error('Network error.');
      });
  }

  function patchUndo(row) {
    var fd = new FormData();
    fd.append('_token', csrf);
    fd.append('_method', 'PATCH');
    fd.append('op', 'delivery_resolution_clear');
    fetch(url.replace('__ID__', row.dataset.id), {
      method: 'POST', body: fd,
      headers: { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' }
    }).then(function () { window.location.reload(); });
  }

  root.addEventListener('click', function (e) {
    var row = e.target.closest('[data-ad-row]');
    if (!row) return;

    var res = e.target.getAttribute('data-ad-resolve');
    if (res) {
      patch(row, { op: 'delivery_resolution', resolution: res },
        res === 'customer_pickup' ? 'Picked up in person' : 'No delivery needed');
      return;
    }

    var snooze = e.target.getAttribute('data-ad-snooze');
    if (snooze) {
      patch(row, { op: 'delivery_snooze', days: snooze }, 'Snoozed ' + snooze + ' days');
    }
  });
})();
</script>
DELRES_5_EOF

cat > 'resources/views/tenant/appointments/index.blade.php' <<'DELRES_6_EOF'
@extends('layouts.tenant.app')
@php
  $pageTitle = 'Appointments';
  $statusLabels = \App\Support\AppointmentStatus::LABELS; // MARKER-PATCH-287 single source
  $paymentLabels = [
    'unpaid'   => 'Unpaid',
    'partial'  => 'Partial',
    'paid'     => 'Paid',
    'refunded' => 'Refunded',
  ];
  // Status transitions — must match AppointmentController::TRANSITIONS exactly.
  // Used to populate the inline-edit dropdown with only valid next states.
  $statusTransitions = \App\Support\AppointmentStatus::TRANSITIONS; // MARKER-PATCH-287 single source
  $sortLabels = [
    'date_desc'  => 'Newest first',
    'date_asc'   => 'Oldest first',
    'name_asc'   => 'Customer A–Z',
    'name_desc'  => 'Customer Z–A',
    'status'     => 'By status',
    'total_desc' => 'Total (high–low)',
    'total_asc'  => 'Total (low–high)',
  ];
@endphp

@push('styles')
<style>
/* Inline-edit pattern for the appointments table.
   Click a badge → it transforms into a select with valid options.
   Pick a different value → row goes "dirty," save/cancel buttons appear in the actions column.
   Save fires PATCH, applies in-place. Cancel reverts. */
.ia-inline-cell {
  position: relative;
}
.ia-inline-select {
  background: var(--ia-input-bg);
  border: 0.5px solid var(--ia-border);
  border-radius: var(--ia-r-md);
  color: var(--ia-text);
  font-size: 12px;
  padding: 4px 22px 4px 9px;
  appearance: none;
  cursor: pointer;
  font-family: inherit;
  outline: none;
  background-image: url("data:image/svg+xml;utf8,<svg xmlns='http://www.w3.org/2000/svg' width='10' height='10' viewBox='0 0 10 10' fill='none' stroke='rgba(255,255,255,.45)'><path d='M2 4l3 3 3-3' stroke-width='1.5' stroke-linecap='round' stroke-linejoin='round'/></svg>");
  background-repeat: no-repeat;
  background-position: right 7px center;
  transition: border-color var(--ia-t);
}
.ia-inline-select:focus {
  border-color: var(--ia-accent);
}
.ia-inline-select.is-dirty {
  border-color: var(--ia-accent);
  box-shadow: 0 0 0 1px var(--ia-accent);
}
.ia-inline-actions {
  display: none;
  gap: 4px;
  align-items: center;
  white-space: nowrap;
}
tr.is-dirty .ia-inline-actions {
  display: inline-flex;
}
.ia-inline-btn {
  width: 26px;
  height: 26px;
  padding: 0;
  border-radius: var(--ia-r-md);
  border: 0.5px solid var(--ia-border);
  background: var(--ia-surface);
  color: var(--ia-text-muted);
  cursor: pointer;
  display: inline-flex;
  align-items: center;
  justify-content: center;
  transition: all var(--ia-t);
  font-family: inherit;
}
.ia-inline-btn:hover { color: var(--ia-text); border-color: var(--ia-border-strong); }
.ia-inline-btn--save { color: var(--ia-accent); border-color: var(--ia-accent); }
.ia-inline-btn--save:hover { background: var(--ia-accent); color: var(--ia-bg); }
.ia-inline-btn--save:disabled { opacity: .5; cursor: wait; }
.ia-inline-btn--cancel:hover { color: #EF4444; border-color: #EF4444; }
.appt-resource-chip {
  display: inline-flex;
  align-items: center;
  gap: 8px;
  padding: 8px 14px;
  margin-bottom: 16px;
  background: var(--ia-surface-2, rgba(255,255,255,0.03));
  border: 0.5px solid var(--ia-border);
  border-radius: 999px;
  font-size: 13px;
  color: var(--ia-text-2);
}
.appt-resource-dot {
  width: 8px;
  height: 8px;
  border-radius: 50%;
  flex-shrink: 0;
}
.appt-resource-clear {
  margin-left: 6px;
  color: var(--ia-text-3);
  text-decoration: none;
  font-size: 11px;
}
.appt-resource-clear:hover {
  color: var(--ia-accent, #BEF264);
}
.ia-inline-resource {
  display: inline-flex;
  align-items: center;
  gap: 6px;
  font-size: 13px;
  color: var(--ia-text);
}
.ia-inline-resource-dot {
  width: 8px;
  height: 8px;
  border-radius: 50%;
  flex-shrink: 0;
}
.ia-inline-resource--unassigned {
  color: var(--ia-text-muted);
  font-style: italic;
}
.ia-inline-error {
  position: absolute;
  top: 100%;
  left: 0;
  margin-top: 4px;
  padding: 4px 8px;
  background: #EF4444;
  color: #fff;
  font-size: 11px;
  border-radius: var(--ia-r-md);
  white-space: nowrap;
  z-index: 5;
}
/* Cells with editors should not propagate clicks to the row's modal-opener. */
td.ia-inline-cell { cursor: default; }
</style>
@endpush

@section('content')

<div class="ia-page-head">
  <div class="ia-page-head-left">
    @if(!empty($filter) && !empty($filterLabels[$filter]))
      <h1 class="ia-page-title">{{ $filterLabels[$filter] }}</h1>
      <p class="ia-page-subtitle">
        {{ $total }} {{ Str::plural('appointment', $total) }} · 
        <a href="{{ route('tenant.appointments.index') }}" style="color: inherit; text-decoration: underline">Clear filter</a>
      </p>
    @else
      <h1 class="ia-page-title">Appointments</h1>
      <p class="ia-page-subtitle">Every booking, every status.</p>
    @endif
  </div>
  <div class="ia-page-actions">
    <button type="button" class="ia-btn ia-btn--primary" onclick="openApptModal()">
      + New appointment
    </button>
  </div>
</div>

@php
  // Inline-fetch the attention cards so the same row appears on this page.
  // Cheap — same query the dashboard runs.
  try {
    $svc = new \App\Services\Tenant\DashboardDataService(tenant());
    $attentionForBar = $svc->zoneAttention();
  } catch (\Throwable $e) {
    $attentionForBar = ['cards' => [], 'total_items' => 0];
  }
@endphp

{{-- MARKER-PATCH-113 - resource filter chip --}}
@if(!empty($resourceFilter))
  <div class="appt-resource-chip">
    <span class="appt-resource-dot" style="background: {{ $resourceFilter->color_hex }}"></span>
    Showing appointments for <strong>{{ $resourceFilter->name }}</strong>
    <a href="{{ route('tenant.appointments.index') }}" class="appt-resource-clear">clear ×</a>
  </div>
@endif

@if(!empty($attentionForBar['cards']))
  <div style="margin-bottom: 24px;">
    @include('tenant.dashboard._attention_cards', [
      'cards' => $attentionForBar['cards'],
      'activeFilter' => $filter ?? '',
    ])
  </div>
@endif

<form method="get" action="{{ route('tenant.appointments.index') }}" class="ia-toolbar appt-desktop-only" id="appt-desktop-form">
  <input type="search" name="s" class="ia-input" value="{{ $search }}"
    placeholder="Search ITO#, name, email…" style="max-width:260px">

  <select name="status" class="ia-input" style="width:auto">
    <option value="">All statuses</option>
    {{-- MARKER-PATCH-285 — only the selectable set; \$statusLabels still resolves legacy rows below --}}
    @foreach(\App\Support\AppointmentStatus::selectable() as $val => $label)
      <option value="{{ $val }}" @selected($status === $val)>{{ $label }}</option>
    @endforeach
  </select>

  <select name="payment" class="ia-input" style="width:auto">
    <option value="">All payments</option>
    <option value="unpaid"  @selected($payment === 'unpaid')>Unpaid</option>
    <option value="partial" @selected($payment === 'partial')>Partial</option>
    <option value="paid"    @selected($payment === 'paid')>Paid</option>
  </select>

  <x-tenant.date-range
    fromName="date_from"
    toName="date_to"
    :fromValue="$dateFrom"
    :toValue="$dateTo"
    placeholder="Date range" />

  <select name="sort" class="ia-input" style="width:auto">
    @foreach($sortLabels as $val => $label)
      <option value="{{ $val }}" @selected($sort === $val)>{{ $label }}</option>
    @endforeach
  </select>

  <button type="submit" class="ia-btn ia-btn--secondary">Filter</button>
  @if($search || $status || $payment || $dateFrom || $dateTo || $sort !== 'date_desc')
    <a href="{{ route('tenant.appointments.index') }}" class="ia-btn ia-btn--ghost">Reset</a>
  @endif
</form>

{{-- APPT-LIST-MOBILE v1 — mobile filter bar + sheet. --}}
@php
  $hasAnyFilter = $search || $status || $payment || $dateFrom || $dateTo || $sort !== 'date_desc';
  $currentSortLabel = $sortLabels[$sort] ?? 'Newest first';
  $currentStatusLabel = $status ? ($statusLabels[$status] ?? $status) : 'All statuses';
@endphp

<form method="get" action="{{ route('tenant.appointments.index') }}" class="appt-mobile-only appt-mfilter" id="appt-mobile-form">
  <div class="appt-mfilter-search-wrap">
    <svg class="appt-mfilter-search-icon" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
      <circle cx="11" cy="11" r="8"/><path d="M21 21l-4.35-4.35"/>
    </svg>
    <input type="search" name="s" class="appt-mfilter-search" value="{{ $search }}"
      placeholder="Search ITO#, name, email" autocomplete="off" id="appt-search-mobile">
  </div>
  {{-- Hidden fields preserve filter state when search submits --}}
  <input type="hidden" name="status"    id="appt-status-mobile"    value="{{ $status }}">
  <input type="hidden" name="payment"   id="appt-payment-mobile"   value="{{ $payment }}">
  <input type="hidden" name="date_from" id="appt-datefrom-mobile"  value="{{ $dateFrom }}">
  <input type="hidden" name="date_to"   id="appt-dateto-mobile"    value="{{ $dateTo }}">
  <input type="hidden" name="sort"      id="appt-sort-mobile"      value="{{ $sort }}">
  <button type="button" class="appt-mfilter-iconbtn {{ $hasAnyFilter ? 'is-active' : '' }}" onclick="ApptFilter.open()" aria-label="Filter and sort">
    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
      <polygon points="22 3 2 3 10 12.46 10 19 14 21 14 12.46 22 3"/>
    </svg>
    @if($hasAnyFilter)
      <span class="appt-mfilter-badge" aria-hidden="true"></span>
    @endif
  </button>
  <button type="button" class="appt-mfilter-iconbtn" onclick="openApptModal()" aria-label="New appointment">
    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
      <line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/>
    </svg>
  </button>
</form>

{{-- Filter bottom sheet --}}
<div class="appt-filter-backdrop" id="appt-filter-backdrop" onclick="ApptFilter.close()" aria-hidden="true"></div>
<div class="appt-filter-sheet" id="appt-filter-sheet" role="dialog" aria-modal="true" aria-label="Filter appointments" aria-hidden="true">
  <div class="appt-filter-handle" aria-hidden="true"></div>
  <div class="appt-filter-header">
    <span class="appt-filter-title">Filter &amp; sort</span>
    <button type="button" class="appt-filter-close" onclick="ApptFilter.close()" aria-label="Close">
      <svg width="18" height="18" viewBox="0 0 18 18" fill="none" aria-hidden="true">
        <path d="M4 4l10 10M14 4L4 14" stroke="currentColor" stroke-width="1.4" stroke-linecap="round"/>
      </svg>
    </button>
  </div>

  <div class="appt-filter-body">
    {{-- Status --}}
    <div class="appt-filter-group">
      <div class="appt-filter-grouplabel">Status</div>
      <div class="appt-filter-chips" data-field="status">
        <button type="button" class="appt-filter-chip {{ !$status ? 'is-active' : '' }}" data-value="">All statuses</button>
        @foreach($statusLabels as $val => $label)
          <button type="button" class="appt-filter-chip {{ $status === $val ? 'is-active' : '' }}" data-value="{{ $val }}">{{ $label }}</button>
        @endforeach
      </div>
    </div>

    {{-- Payment --}}
    <div class="appt-filter-group">
      <div class="appt-filter-grouplabel">Payment</div>
      <div class="appt-filter-chips" data-field="payment">
        <button type="button" class="appt-filter-chip {{ !$payment ? 'is-active' : '' }}" data-value="">All payments</button>
        <button type="button" class="appt-filter-chip {{ $payment === 'unpaid' ? 'is-active' : '' }}" data-value="unpaid">Unpaid</button>
        <button type="button" class="appt-filter-chip {{ $payment === 'partial' ? 'is-active' : '' }}" data-value="partial">Partial</button>
        <button type="button" class="appt-filter-chip {{ $payment === 'paid' ? 'is-active' : '' }}" data-value="paid">Paid</button>
      </div>
    </div>

    {{-- Date range --}}
    <div class="appt-filter-group">
      <div class="appt-filter-grouplabel">Date range</div>
      <div class="appt-filter-daterange">
        <input type="date" id="appt-filter-datefrom" class="appt-filter-dateinput" value="{{ $dateFrom }}" placeholder="From">
        <span class="appt-filter-dash">–</span>
        <input type="date" id="appt-filter-dateto" class="appt-filter-dateinput" value="{{ $dateTo }}" placeholder="To">
      </div>
    </div>

    {{-- Sort --}}
    <div class="appt-filter-group">
      <div class="appt-filter-grouplabel">Sort by</div>
      <div class="appt-filter-chips" data-field="sort">
        @foreach($sortLabels as $val => $label)
          <button type="button" class="appt-filter-chip {{ $sort === $val ? 'is-active' : '' }}" data-value="{{ $val }}">{{ $label }}</button>
        @endforeach
      </div>
    </div>
  </div>

  <div class="appt-filter-actions">
    <button type="button" class="appt-filter-btn-clear" onclick="ApptFilter.clear()">Clear all</button>
    <button type="button" class="appt-filter-btn-apply" onclick="ApptFilter.apply()">Apply</button>
  </div>
</div>

{{-- MARKER-PATCH-439 — section tabs sit below the controls, right above the list --}}
<x-tenant.schedule-tabs active="appointments" />

{{-- Mobile result header --}}
<div class="appt-mobile-only appt-list-header">
  <span>{{ number_format($total) }} {{ Str::plural('appointment', $total) }} · {{ $currentSortLabel }}</span>
  @if($hasAnyFilter)
    <a href="{{ route('tenant.appointments.index') }}" class="appt-list-clear">Clear</a>
  @endif
</div>

<p class="ia-result-count appt-desktop-only">
  <strong id="appt-result-count" data-count="{{ $total }}">{{ number_format($total) }}</strong> <span id="appt-result-noun">{{ Str::plural('appointment', $total) }}</span>
</p>

@if($appointments->isEmpty())
  <div class="ia-empty">
    <div class="ia-empty-icon">
      <svg width="20" height="20" viewBox="0 0 20 20" fill="none" style="opacity:.4">
        <rect x="2" y="4" width="16" height="13" rx="2" stroke="currentColor" stroke-width="1.4"/>
        <path d="M7 4V2M13 4V2M2 8h16" stroke="currentColor" stroke-width="1.4" stroke-linecap="round"/>
      </svg>
    </div>
    <div class="ia-empty-title">No appointments found</div>
    <div class="ia-empty-desc">
      @if($search || $status || $payment)
        Try adjusting your filters.
      @else
        When customers book, they'll appear here.
      @endif
    </div>
    @if(!$search && !$status && !$payment)
      <button type="button" class="ia-btn ia-btn--primary" onclick="openApptModal()">
        + New appointment
      </button>
    @endif
  </div>
@else
  {{-- MARKER-DELIVERY-RESOLUTION — the awaiting-delivery queue gets a triage
       panel with per-row reasons and resolution actions; the standard table
       is hidden for this filter so the same jobs are not listed twice. --}}
  @php $adTriage = (($filter ?? '') === 'awaiting_delivery'); @endphp
  @if($adTriage)
    @include('tenant.appointments._awaiting_delivery_panel')
  @endif
  <div class="ia-table-wrap appt-desktop-only" @if($adTriage) style="display:none" @endif>
    <table class="ia-table" id="ia-appts-table" data-update-url="{{ route('tenant.appointments.update', ['id' => '__ID__']) }}" data-active-filter="{{ $filter ?? '' }}" data-status-filter="{{ $status ?? '' }}">
      <thead>
        <tr>
          <th>ITO #</th>
          <th>Customer</th>
          <th>Date</th>
          <th>Resource</th>
          <th>Status</th>
          <th>Payment</th>
          <th class="ia-num">Total</th>
          <th></th>
        </tr>
      </thead>
      <tbody>
        @foreach($appointments as $appt)
          @php
            $allowedStatuses = $statusTransitions[$appt->status] ?? [];
            // Include current status in dropdown so it shows as the selected value
            $statusOptions = array_merge([$appt->status], $allowedStatuses);
            $statusOptions = array_values(array_unique($statusOptions));
          @endphp
          <tr data-appt-id="{{ $appt->id }}"
              data-orig-status="{{ $appt->status }}"
              data-orig-payment="{{ $appt->payment_status }}"
              data-orig-resource="{{ $appt->resource_id ?? '' }}">
            <td onclick="openDetailModal('appointment','{{ $appt->id }}')" style="cursor:pointer">
              <span style="font-weight:500">{{ $appt->ra_number }}</span>
            </td>
            <td onclick="openDetailModal('appointment','{{ $appt->id }}')" style="cursor:pointer">
              <div style="font-weight:500">{{ $appt->customerName() }}</div>
              <div class="ia-muted-cell" style="font-size:12px">{{ $appt->customer_email }}</div>
            </td>
            <td onclick="openDetailModal('appointment','{{ $appt->id }}')" class="ia-muted-cell" style="cursor:pointer">{{ $appt->appointment_date->format('M j, Y') }}</td>
            <td class="ia-inline-cell" data-field="resource">
              <select class="ia-inline-select" data-field="resource">
                <option value="">— Unassigned —</option>
                @foreach($resources as $r)
                  <option value="{{ $r->id }}" @selected($appt->resource_id === $r->id)>{{ $r->name }}</option>
                @endforeach
              </select>
            </td>
            <td class="ia-inline-cell" data-field="status">
              <select class="ia-inline-select" data-field="status">
                @foreach($statusOptions as $s)
                  <option value="{{ $s }}" @selected($appt->status === $s)>{{ $statusLabels[$s] ?? $s }}</option>
                @endforeach
              </select>
            </td>
            <td class="ia-inline-cell" data-field="payment">
              <select class="ia-inline-select" data-field="payment">
                @foreach($paymentLabels as $val => $label)
                  <option value="{{ $val }}" @selected($appt->payment_status === $val)>{{ $label }}</option>
                @endforeach
              </select>
            </td>
            <td onclick="openDetailModal('appointment','{{ $appt->id }}')" class="ia-num" style="cursor:pointer">{{ format_money($appt->total_cents) }}</td>
            <td>
              <span class="ia-inline-actions">
                <button type="button" class="ia-inline-btn ia-inline-btn--save" data-action="save" title="Save changes (Enter)">
                  <svg width="13" height="13" viewBox="0 0 13 13" fill="none">
                    <path d="M2.5 6.5l2.5 2.5L10.5 3.5" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/>
                  </svg>
                </button>
                <button type="button" class="ia-inline-btn ia-inline-btn--cancel" data-action="cancel" title="Discard (Esc)">
                  <svg width="13" height="13" viewBox="0 0 13 13" fill="none">
                    <path d="M3 3l7 7M10 3l-7 7" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"/>
                  </svg>
                </button>
              </span>
            </td>
          </tr>
        @endforeach
      </tbody>
    </table>
  </div>

  {{-- Mobile card list (parallel to desktop table) --}}
  <div class="appt-mobile-only appt-cards" @if($adTriage ?? false) style="display:none" @endif>
    @foreach($appointments as $appt)
      @php
        $statusKey = $appt->status;
        $statusToneMap = [
          'pending'     => 'pending',
          'confirmed'   => 'confirmed',
          'in_progress' => 'in-progress',
          'completed'   => 'completed',
          'shipped'     => 'completed',
          'closed'      => 'completed',
          'cancelled'   => 'cancelled',
          'refunded'    => 'cancelled',
        ];
        $statusTone = $statusToneMap[$statusKey] ?? 'completed';
        $paymentTone = match($appt->payment_status) {
          'paid'     => 'success',
          'partial'  => 'warning',
          'unpaid'   => 'danger',
          'refunded' => 'cancelled',
          default    => 'neutral',
        };
      @endphp
      <button type="button" class="appt-card" onclick="openDetailModal('appointment','{{ $appt->id }}')">
        <div class="appt-card-row1">
          <span class="appt-card-status is-{{ $statusTone }}"></span>
          <span class="appt-card-ito">{{ $appt->ra_number }}</span>
          <span class="appt-card-date">{{ $appt->appointment_date->format('M j') }}</span>
          <span class="appt-card-total">{{ format_money($appt->total_cents) }}</span>
        </div>
        <div class="appt-card-row2">
          <span class="appt-card-name">{{ $appt->customerName() }}</span>
        </div>
        <div class="appt-card-row3">
          <span class="appt-card-pill appt-card-pill--status appt-card-pill--{{ $statusTone }}">{{ $statusLabels[$statusKey] ?? $statusKey }}</span>
          <span class="appt-card-pill appt-card-pill--{{ $paymentTone }}">{{ $paymentLabels[$appt->payment_status] ?? $appt->payment_status }}</span>
        </div>
      </button>
    @endforeach
  </div>

  @if($totalPages > 1)
    <div class="ia-pagination">
      @for($p = 1; $p <= $totalPages; $p++)
        <a href="{{ route('tenant.appointments.index', array_merge(request()->query(), ['page' => $p])) }}"
           class="ia-page-btn {{ $p === $page ? 'active' : '' }}">{{ $p }}</a>
      @endfor
    </div>
  @endif
@endif

@include('tenant.appointments._create_modal')

@endsection

@push('scripts')
<script>
/**
 * Inline editing for the appointments list.
 * Each row carries data-orig-{field} attributes captured at render time.
 * When a select's value differs from its data-orig, the row is "dirty" and
 * Save/Cancel buttons appear. Save fires PATCH per changed field.
 *
 * Backend ops: status, payment, change_resource. All exist already on
 * AppointmentController::handleUpdate. Resource changes can return 409 on
 * conflict — we surface the message and let the user pick again or click
 * the conflicting appointment in the detail modal.
 */
(function() {
  const table = document.getElementById('ia-appts-table');
  if (!table) return;

  const updateUrlTpl = table.dataset.updateUrl;
  const csrfToken = document.querySelector('meta[name="csrf-token"]')?.content;
  if (!csrfToken) {
    console.warn('inline-edit: no CSRF token found, save will fail');
  }

  /**
   * Walk all selects in a row, returning {field: newValue} for those changed.
   * Empty string for resource means "Unassigned" — but the backend's
   * change_resource op rejects empty strings ("Resource is required"), so
   * we filter resource changes to non-empty before sending. Status and
   * payment selects always have non-empty values.
   */
  function getDirtyFields(tr) {
    const dirty = {};
    tr.querySelectorAll('.ia-inline-select').forEach(sel => {
      const field = sel.dataset.field;
      const orig = tr.dataset['orig' + field.charAt(0).toUpperCase() + field.slice(1)];
      if (sel.value !== orig) {
        dirty[field] = sel.value;
      }
    });
    return dirty;
  }

  function setDirtyState(tr, isDirty) {
    tr.classList.toggle('is-dirty', isDirty);
    tr.querySelectorAll('.ia-inline-select').forEach(sel => {
      const field = sel.dataset.field;
      const orig = tr.dataset['orig' + field.charAt(0).toUpperCase() + field.slice(1)];
      sel.classList.toggle('is-dirty', sel.value !== orig);
    });
  }

  function showError(tr, msg) {
    clearError(tr);
    const cell = tr.querySelector('.ia-inline-cell');
    if (!cell) return;
    const err = document.createElement('div');
    err.className = 'ia-inline-error';
    err.textContent = msg;
    cell.appendChild(err);
    setTimeout(() => clearError(tr), 4000);
  }
  function clearError(tr) {
    tr.querySelectorAll('.ia-inline-error').forEach(e => e.remove());
  }

  /**
   * Send a single field update. Resolves with {ok, status, body}. Caller
   * decides what to do based on those.
   */
  async function patchField(apptId, op, payload) {
    const url = updateUrlTpl.replace('__ID__', apptId);
    const body = new FormData();
    body.append('_token', csrfToken);
    body.append('op', op);
    Object.entries(payload).forEach(([k, v]) => body.append(k, v));
    const res = await fetch(url, {
      method: 'POST', // Laravel accepts POST + _method, but we use real PATCH
      headers: {
        'X-HTTP-Method-Override': 'PATCH',
        'X-CSRF-TOKEN': csrfToken,
        'Accept': 'application/json',
      },
      body,
      credentials: 'same-origin',
    });
    let parsed = null;
    try { parsed = await res.json(); } catch (e) { /* non-JSON */ }
    return { ok: res.ok, status: res.status, body: parsed };
  }

  async function saveRow(tr) {
    const apptId = tr.dataset.apptId;
    const dirty = getDirtyFields(tr);
    if (Object.keys(dirty).length === 0) {
      setDirtyState(tr, false);
      return;
    }
    clearError(tr);

    const saveBtn = tr.querySelector('[data-action="save"]');
    saveBtn.disabled = true;

    // Field → backend op + payload key
    const ops = [];
    if ('status' in dirty)   ops.push({ field: 'status',   op: 'status',          payload: { status: dirty.status } });
    if ('payment' in dirty)  ops.push({ field: 'payment',  op: 'payment',         payload: { payment_status: dirty.payment } });
    if ('resource' in dirty) {
      // Backend rejects empty resource_id. If the user selected "Unassigned",
      // we'd need a different op (not built). For now, just refuse it client-side.
      if (dirty.resource === '') {
        showError(tr, 'Cannot unassign resource via inline edit yet.');
        saveBtn.disabled = false;
        return;
      }
      ops.push({ field: 'resource', op: 'change_resource', payload: { resource_id: dirty.resource } });
    }

    try {
      for (const o of ops) {
        const res = await patchField(apptId, o.op, o.payload);

        if (res.ok && res.body?.ok) {
          // Update the orig dataset attr so the field is no longer "dirty"
          const newVal = dirty[o.field];
          tr.dataset['orig' + o.field.charAt(0).toUpperCase() + o.field.slice(1)] = newVal;
          continue;
        }

        // 409 on resource means conflict. Server returns details; we just
        // show the message and bail. User can re-select or click the
        // conflicting appointment.
        if (res.status === 409 && o.field === 'resource') {
          showError(tr, res.body?.message || 'Resource conflict.');
          saveBtn.disabled = false;
          return;
        }

        // 422 — backend rejected the value. Show its message and bail.
        showError(tr, res.body?.message || 'Save failed.');
        saveBtn.disabled = false;
        return;
      }
      // All ops succeeded
      setDirtyState(tr, false);
      // MARKER-PATCH-179 — if this row no longer matches the active filter
      // (e.g. confirming a booking on the "Unconfirmed bookings" list), fade
      // it out and remove it, and decrement the result count — so the list
      // stays accurate without a manual refresh.
      maybePruneRow(tr, dirty);
    } catch (e) {
      console.error('inline-edit save failed', e);
      showError(tr, 'Network error. Try again.');
    } finally {
      saveBtn.disabled = false;
    }
  }

  // MARKER-PATCH-179 — remove a row that no longer belongs in the current
  // filtered view after an inline status change.
  function maybePruneRow(tr, dirty) {
    if (!('status' in dirty)) return; // only status changes can drop a row
    const table = document.getElementById('ia-appts-table');
    if (!table) return;
    const activeFilter = (table.dataset.activeFilter || '').toLowerCase();
    const statusFilter = (table.dataset.statusFilter || '').toLowerCase();
    const newStatus = (dirty.status || '').toLowerCase();

    // Decide whether the row still belongs. Two filter sources:
    //  - the "Unconfirmed bookings" attention filter (pending only)
    //  - the explicit status dropdown filter (must match exactly)
    let belongs = true;
    // MARKER-PATCH-179B — the real attention-filter value is
    // 'unconfirmed_bookings'; treat any 'unconfirmed'/'pending' variant as the
    // pending-only scope.
    if (activeFilter.indexOf('unconfirmed') !== -1 || activeFilter === 'pending') {
      belongs = (newStatus === 'pending');
    } else if (statusFilter) {
      belongs = (newStatus === statusFilter);
    }
    if (belongs) return;

    // Fade + remove, then decrement the count(s).
    tr.style.transition = 'opacity .25s ease';
    tr.style.opacity = '0';
    setTimeout(() => {
      tr.remove();
      const strong = document.getElementById('appt-result-count');
      if (strong) {
        const n = Math.max(0, (parseInt(strong.dataset.count, 10) || 1) - 1);
        strong.dataset.count = n;
        strong.textContent = n.toLocaleString();
        const noun = document.getElementById('appt-result-noun');
        if (noun) noun.textContent = (n === 1 ? 'appointment' : 'appointments');
      }
    }, 260);
  }

  function cancelRow(tr) {
    clearError(tr);
    tr.querySelectorAll('.ia-inline-select').forEach(sel => {
      const field = sel.dataset.field;
      const orig = tr.dataset['orig' + field.charAt(0).toUpperCase() + field.slice(1)];
      sel.value = orig;
    });
    setDirtyState(tr, false);
  }

  // Wire up change detection on selects
  table.addEventListener('change', (e) => {
    const sel = e.target.closest('.ia-inline-select');
    if (!sel) return;
    const tr = sel.closest('tr[data-appt-id]');
    if (!tr) return;
    const dirty = getDirtyFields(tr);
    setDirtyState(tr, Object.keys(dirty).length > 0);
  });

  // Wire up save/cancel button clicks
  table.addEventListener('click', (e) => {
    const btn = e.target.closest('[data-action]');
    if (!btn) return;
    e.stopPropagation();
    const tr = btn.closest('tr[data-appt-id]');
    if (!tr) return;
    if (btn.dataset.action === 'save') saveRow(tr);
    else if (btn.dataset.action === 'cancel') cancelRow(tr);
  });

  // Stop select clicks from bubbling to row's modal-opener cells (only their
  // cells have onclick now, but defensive)
  table.addEventListener('click', (e) => {
    if (e.target.closest('.ia-inline-cell')) {
      e.stopPropagation();
    }
  }, true);

  // Keyboard: Esc cancels, Enter saves the focused row
  table.addEventListener('keydown', (e) => {
    const tr = e.target.closest('tr[data-appt-id].is-dirty');
    if (!tr) return;
    if (e.key === 'Escape') {
      e.preventDefault();
      cancelRow(tr);
    } else if (e.key === 'Enter') {
      e.preventDefault();
      saveRow(tr);
    }
  });
})();
</script>
@endpush


@push('styles')
<style>
/* APPT-LIST-MOBILE-CSS v1 */

.appt-mobile-only { display: none; }

@media (max-width: 600px) {
  .appt-desktop-only { display: none !important; }
  .appt-mobile-only { display: block; }

  /* ── Filter bar (same shape as customer list) ── */
  .appt-mfilter {
    display: grid !important;
    grid-template-columns: 1fr 40px 40px;
    gap: 6px;
    margin: 4px 0 14px;
  }
  .appt-mfilter-search-wrap {
    position: relative;
  }
  .appt-mfilter-search-icon {
    position: absolute;
    left: 12px; top: 50%; transform: translateY(-50%);
    color: var(--ia-text-muted);
    pointer-events: none;
  }
  .appt-mfilter-search {
    width: 100%;
    height: 40px;
    background: var(--ia-surface);
    border: 0.5px solid var(--ia-border);
    border-radius: 8px;
    padding: 0 12px 0 36px;
    color: var(--ia-text);
    font-size: 14px;
    font-family: inherit;
    -webkit-appearance: none;
    appearance: none;
  }
  .appt-mfilter-search:focus {
    outline: none;
    border-color: var(--ia-accent);
  }
  .appt-mfilter-iconbtn {
    background: var(--ia-surface);
    border: 0.5px solid var(--ia-border);
    border-radius: 8px;
    width: 40px; height: 40px;
    display: flex; align-items: center; justify-content: center;
    color: var(--ia-text-muted);
    cursor: pointer;
    position: relative;
    -webkit-tap-highlight-color: transparent;
  }
  .appt-mfilter-iconbtn:active { transform: scale(0.95); }
  .appt-mfilter-iconbtn.is-active {
    color: var(--ia-accent);
    border-color: rgba(190,242,100,.3);
    background: rgba(190,242,100,.08);
  }
  .appt-mfilter-badge {
    position: absolute;
    top: 4px; right: 4px;
    width: 8px; height: 8px;
    background: var(--ia-accent);
    border-radius: 50%;
    border: 2px solid var(--ia-bg, #0a0a0a);
  }

  /* ── List header ── */
  .appt-list-header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: 0 4px 10px;
    font-size: 11px;
    text-transform: uppercase;
    letter-spacing: .07em;
    color: var(--ia-text-muted);
    font-weight: 500;
  }
  .appt-list-clear {
    color: var(--ia-accent);
    text-decoration: none;
    font-size: 11px;
  }

  /* ── Cards ── */
  .appt-cards {
    display: flex;
    flex-direction: column;
    gap: 8px;
  }
  .appt-card {
    display: block;
    width: 100%;
    text-align: left;
    background: var(--ia-surface);
    border: 0.5px solid var(--ia-border);
    border-radius: 10px;
    padding: 12px 14px;
    cursor: pointer;
    font-family: inherit;
    color: inherit;
    -webkit-tap-highlight-color: transparent;
  }
  .appt-card:active { transform: scale(0.99); }
  .appt-card-row1 {
    display: flex;
    align-items: center;
    gap: 8px;
    margin-bottom: 5px;
  }
  .appt-card-status {
    width: 8px; height: 8px; border-radius: 50%;
    flex-shrink: 0;
    background: var(--ia-text-muted);
  }
  .appt-card-status.is-pending     { background: #F59E0B; }
  .appt-card-status.is-confirmed   { background: #34D399; }
  .appt-card-status.is-in-progress { background: var(--ia-accent); }
  .appt-card-status.is-completed   { background: #6b7280; }
  .appt-card-status.is-cancelled   { background: #EF4444; opacity: .6; }

  .appt-card-ito {
    font-size: 12px;
    color: var(--ia-text-muted);
    font-variant-numeric: tabular-nums;
    flex: 1;
    min-width: 0;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
  }
  .appt-card-date {
    font-size: 11px;
    color: var(--ia-text-dim, rgba(255,255,255,.4));
    text-transform: uppercase;
    letter-spacing: .04em;
    font-variant-numeric: tabular-nums;
  }
  .appt-card-total {
    font-size: 14px;
    font-weight: 500;
    color: var(--ia-text);
    font-variant-numeric: tabular-nums;
    margin-left: 4px;
  }
  .appt-card-row2 {
    margin-bottom: 8px;
  }
  .appt-card-name {
    font-size: 15px;
    font-weight: 500;
    color: var(--ia-text);
    letter-spacing: -.01em;
  }
  .appt-card-row3 {
    display: flex;
    gap: 6px;
    flex-wrap: wrap;
  }
  .appt-card-pill {
    font-size: 10px;
    padding: 3px 8px;
    border-radius: 99px;
    background: var(--ia-surface-2);
    color: var(--ia-text-muted);
    font-weight: 500;
    white-space: nowrap;
  }
  .appt-card-pill--pending     { background: rgba(245,158,11,.15); color: #F59E0B; }
  .appt-card-pill--confirmed   { background: rgba(52,211,153,.15); color: #34D399; }
  .appt-card-pill--in-progress { background: rgba(190,242,100,.15); color: var(--ia-accent); }
  .appt-card-pill--completed   { background: rgba(107,114,128,.15); color: #9ca3af; }
  .appt-card-pill--cancelled   { background: rgba(239,68,68,.15);  color: #EF4444; }
  .appt-card-pill--success     { background: rgba(190,242,100,.15); color: var(--ia-accent); }
  .appt-card-pill--warning     { background: rgba(245,158,11,.15); color: #F59E0B; }
  .appt-card-pill--danger      { background: rgba(239,68,68,.15);  color: #EF4444; }
  .appt-card-pill--neutral     { background: var(--ia-surface-2);  color: var(--ia-text-muted); }
}

/* ── Filter bottom sheet ── */
.appt-filter-backdrop {
  position: fixed; inset: 0;
  background: rgba(0,0,0,.5);
  z-index: 200;
  opacity: 0;
  pointer-events: none;
  transition: opacity 180ms ease;
}
.appt-filter-backdrop.is-open {
  opacity: 1;
  pointer-events: auto;
}
.appt-filter-sheet {
  position: fixed;
  left: 0; right: 0; bottom: 0;
  background: var(--ia-surface);
  border-radius: 18px 18px 0 0;
  z-index: 201;
  border: 0.5px solid var(--ia-border);
  border-bottom: 0;
  transform: translateY(100%);
  transition: transform 220ms cubic-bezier(.2, .8, .2, 1);
  max-height: 88vh;
  display: flex;
  flex-direction: column;
}
.appt-filter-sheet.is-open { transform: translateY(0); }

.appt-filter-handle {
  width: 36px; height: 4px;
  background: rgba(255,255,255,.18);
  border-radius: 2px;
  margin: 12px auto 8px;
  flex-shrink: 0;
}
body.ia-theme-b .appt-filter-handle { background: rgba(0,0,0,.18); }

.appt-filter-header {
  display: flex;
  align-items: center;
  justify-content: space-between;
  padding: 4px 20px 14px;
  border-bottom: 0.5px solid var(--ia-border);
  flex-shrink: 0;
}
.appt-filter-title {
  font-size: 16px;
  font-weight: 600;
  color: var(--ia-text);
}
.appt-filter-close {
  background: transparent;
  border: none;
  color: var(--ia-text-muted);
  width: 32px; height: 32px;
  display: flex; align-items: center; justify-content: center;
  cursor: pointer;
  -webkit-tap-highlight-color: transparent;
}

.appt-filter-body {
  padding: 16px 20px;
  overflow-y: auto;
  -webkit-overflow-scrolling: touch;
  flex: 1;
}
.appt-filter-group {
  margin-bottom: 20px;
}
.appt-filter-grouplabel {
  font-size: 11px;
  text-transform: uppercase;
  letter-spacing: .07em;
  color: var(--ia-text-muted);
  font-weight: 500;
  margin-bottom: 8px;
}
.appt-filter-chips {
  display: flex;
  flex-wrap: wrap;
  gap: 6px;
}
.appt-filter-chip {
  padding: 8px 14px;
  border-radius: 99px;
  background: var(--ia-surface-2);
  border: 0.5px solid var(--ia-border);
  color: var(--ia-text);
  font-size: 13px;
  font-weight: 500;
  font-family: inherit;
  cursor: pointer;
  -webkit-tap-highlight-color: transparent;
}
.appt-filter-chip:active { transform: scale(0.96); }
.appt-filter-chip.is-active {
  background: rgba(190,242,100,.12);
  color: var(--ia-accent);
  border-color: rgba(190,242,100,.35);
}

.appt-filter-daterange {
  display: flex;
  align-items: center;
  gap: 8px;
}
.appt-filter-dateinput {
  flex: 1;
  background: var(--ia-input-bg, var(--ia-surface-2));
  border: 0.5px solid var(--ia-border);
  border-radius: 8px;
  padding: 10px 12px;
  color: var(--ia-text);
  font-size: 14px;
  font-family: inherit;
  -webkit-appearance: none;
  appearance: none;
}
.appt-filter-dash {
  color: var(--ia-text-muted);
  font-size: 14px;
}

.appt-filter-actions {
  display: grid;
  grid-template-columns: 1fr 2fr;
  gap: 8px;
  padding: 14px 20px calc(20px + env(safe-area-inset-bottom, 0px));
  border-top: 0.5px solid var(--ia-border);
  flex-shrink: 0;
}
.appt-filter-btn-clear {
  background: transparent;
  border: 0.5px solid var(--ia-border);
  border-radius: 8px;
  padding: 12px;
  color: var(--ia-text);
  font-size: 14px;
  font-weight: 500;
  cursor: pointer;
  font-family: inherit;
  -webkit-tap-highlight-color: transparent;
}
.appt-filter-btn-apply {
  background: var(--ia-accent);
  color: var(--ia-bg, #0a0a0a);
  border: none;
  border-radius: 8px;
  padding: 12px;
  font-size: 14px;
  font-weight: 600;
  cursor: pointer;
  font-family: inherit;
  -webkit-tap-highlight-color: transparent;
}

/* Hide entirely on desktop */
@media (min-width: 601px) {
  .appt-filter-sheet,
  .appt-filter-backdrop { display: none !important; }
}
</style>
@endpush

@push('scripts')
<script>
// APPT-LIST-MOBILE-JS v1
(function () {
  // Track pending changes inside the sheet — apply commits, close discards
  var pending = {
    status:    document.getElementById('appt-status-mobile')?.value || '',
    payment:   document.getElementById('appt-payment-mobile')?.value || '',
    date_from: document.getElementById('appt-datefrom-mobile')?.value || '',
    date_to:   document.getElementById('appt-dateto-mobile')?.value || '',
    sort:      document.getElementById('appt-sort-mobile')?.value || 'date_desc',
  };

  window.ApptFilter = {
    open: function () {
      var b = document.getElementById('appt-filter-backdrop');
      var s = document.getElementById('appt-filter-sheet');
      if (!b || !s) return;
      // Reset pending to current applied state
      pending = {
        status:    document.getElementById('appt-status-mobile').value,
        payment:   document.getElementById('appt-payment-mobile').value,
        date_from: document.getElementById('appt-datefrom-mobile').value,
        date_to:   document.getElementById('appt-dateto-mobile').value,
        sort:      document.getElementById('appt-sort-mobile').value,
      };
      b.classList.add('is-open');
      s.classList.add('is-open');
      b.setAttribute('aria-hidden','false');
      s.setAttribute('aria-hidden','false');
      document.body.style.overflow = 'hidden';
    },
    close: function () {
      var b = document.getElementById('appt-filter-backdrop');
      var s = document.getElementById('appt-filter-sheet');
      if (!b || !s) return;
      b.classList.remove('is-open');
      s.classList.remove('is-open');
      b.setAttribute('aria-hidden','true');
      s.setAttribute('aria-hidden','true');
      document.body.style.overflow = '';
    },
    apply: function () {
      // Write pending → hidden fields, submit form
      document.getElementById('appt-status-mobile').value    = pending.status;
      document.getElementById('appt-payment-mobile').value   = pending.payment;
      document.getElementById('appt-datefrom-mobile').value  = pending.date_from;
      document.getElementById('appt-dateto-mobile').value    = pending.date_to;
      document.getElementById('appt-sort-mobile').value      = pending.sort;
      document.getElementById('appt-mobile-form').submit();
    },
    clear: function () {
      window.location = '{{ route("tenant.appointments.index") }}';
    },
  };

  // Chip toggles
  document.querySelectorAll('.appt-filter-chips').forEach(function (group) {
    var field = group.getAttribute('data-field');
    group.querySelectorAll('.appt-filter-chip').forEach(function (chip) {
      chip.addEventListener('click', function () {
        var val = chip.getAttribute('data-value');
        pending[field] = val;
        // Update active state visually
        group.querySelectorAll('.appt-filter-chip').forEach(function (c) {
          c.classList.toggle('is-active', c === chip);
        });
      });
    });
  });

  // Date inputs update pending live
  var df = document.getElementById('appt-filter-datefrom');
  var dt = document.getElementById('appt-filter-dateto');
  if (df) df.addEventListener('change', function () { pending.date_from = df.value; });
  if (dt) dt.addEventListener('change', function () { pending.date_to = dt.value; });

  // Esc closes
  document.addEventListener('keydown', function (e) {
    if (e.key === 'Escape') ApptFilter.close();
  });

  // Live search submit on the mobile bar
  var searchInput = document.getElementById('appt-search-mobile');
  var form = document.getElementById('appt-mobile-form');
  if (searchInput && form) {
    var t = null;
    searchInput.addEventListener('input', function () {
      clearTimeout(t);
      t = setTimeout(function () { form.submit(); }, 350);
    });
  }
})();
</script>
@endpush
DELRES_6_EOF

echo "delivery-resolution applied — server: git pull && php artisan migrate --force && php artisan view:clear"
