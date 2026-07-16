#!/bin/bash
# pickup-outreach-alerts — server half of the booking-window skip.
#   · pickup_outreach_pending flag on tenant_appointments (migration)
#   · public booking accepts pickup_outreach; skipped bookings set the flag
#     and emit a "Pickup to arrange" staff alert (bell) linking to the
#     appointment
#   · dashboard Needs You Today gets an amber "Pickup to arrange" tile
#     linking to a new appointments filter
#   · scheduling any pickup delivery for the appointment auto-clears the flag
# NOTE: this script does NOT touch routes/web.php (no route changes needed) —
# safe against the restored 633-635 daily-ops routes.
set -e
cd "$(git rev-parse --show-toplevel)"
if grep -q "MARKER-PICKUP-OUTREACH" app/Services/BookingService.php; then
  echo "pickup-outreach-alerts already applied — aborting."; exit 1
fi
if ! grep -q "MARKER-WINDOW-MINISTEP" public/js/booking.js; then
  echo "booking-window-ministep not applied — aborting."; exit 1
fi

cat > 'database/migrations/2026_07_16_000001_add_pickup_outreach_to_tenant_appointments.php' <<'OUTREACH_0_EOF'
<?php

// MARKER-PICKUP-OUTREACH — bookings that skipped the pickup-window choice
// carry a pending flag until staff arrange pickup (assigning a route window
// or clearing it manually clears the flag).

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenant_appointments', function (Blueprint $t) {
            $t->boolean('pickup_outreach_pending')->default(false)->index();
        });
    }

    public function down(): void
    {
        Schema::table('tenant_appointments', function (Blueprint $t) {
            $t->dropColumn('pickup_outreach_pending');
        });
    }
};
OUTREACH_0_EOF

cat > 'app/Models/Tenant/TenantAppointment.php' <<'OUTREACH_1_EOF'
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
OUTREACH_1_EOF

cat > 'app/Models/Tenant/TenantDelivery.php' <<'OUTREACH_2_EOF'
<?php
// MARKER-PATCH-152A

namespace App\Models\Tenant;

use App\Models\Tenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TenantDelivery extends Model
{
    // MARKER-PICKUP-OUTREACH — scheduling a pickup for an appointment
    // resolves its "reach out about pickup" flag, whichever path created it.
    protected static function booted(): void
    {
        static::created(function (TenantDelivery $d) {
            if ($d->type === 'pickup' && $d->appointment_id) {
                \App\Models\Tenant\TenantAppointment::where('id', $d->appointment_id)
                    ->where('pickup_outreach_pending', true)
                    ->update(['pickup_outreach_pending' => false]);
            }
        });
    }

    use HasUuids;

    protected $table = 'tenant_deliveries';

    protected $fillable = [
        'tenant_id', 'type', 'status',
        'scheduled_at', 'window_minutes',
        'address',
        'customer_id', 'work_order_id', 'appointment_id', 'delivery_resource_id',
        'notes',
        'notified_at', 'notification_channels',
        'completed_at', 'cancelled_at',
        'reminded_at', // MARKER-PATCH-155
        'assets', // MARKER-PATCH-427 — snapshot of bikes on this run
    ];

    protected $casts = [
        'scheduled_at'   => 'datetime',
        'window_minutes' => 'integer',
        'notified_at'    => 'datetime',
        'completed_at'   => 'datetime',
        'cancelled_at'   => 'datetime',
        'reminded_at'    => 'datetime', // MARKER-PATCH-155
        'assets'         => 'array', // MARKER-PATCH-427
    ];

    public const TYPE_PICKUP  = 'pickup';
    public const TYPE_DROPOFF = 'dropoff';

    public const STATUS_SCHEDULED = 'scheduled';
    public const STATUS_COMPLETED = 'completed';
    public const STATUS_CANCELLED = 'cancelled';

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(TenantCustomer::class, 'customer_id');
    }

    public function deliveryResource(): BelongsTo
    {
        return $this->belongsTo(TenantDeliveryResource::class, 'delivery_resource_id');
    }

    public function isPickup(): bool  { return $this->type === self::TYPE_PICKUP; }
    public function isDropoff(): bool { return $this->type === self::TYPE_DROPOFF; }

    public function windowEndsAt()
    {
        return $this->scheduled_at?->copy()->addMinutes($this->window_minutes ?: 30);
    }
}
OUTREACH_2_EOF

cat > 'app/Http/Controllers/Tenant/BookingController.php' <<'OUTREACH_3_EOF'
<?php

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Models\Tenant\TenantAppointment;
use App\Exceptions\LockAcquisitionException;
use App\Services\BookingService;
use App\Services\PayPalService;
use Illuminate\Http\Request;
use RuntimeException;

class BookingController extends Controller
{
    public function index()
    {
        $tenant = tenant();

        // MARKER-PATCH-599 — prep extracted to BookingFormData so the
        // booking_embed page-builder section renders from the same source.
        extract(\App\Services\Tenant\BookingFormData::for($tenant));

        // MARKER-FLOW-3 — booking flow mode (advanced | simple | choice).
        // Simple and choice are lighter front-ends onto the same endpoints;
        // advanced is unchanged. A single-item Simple booking submits the exact
        // same payload as a one-item advanced booking, so no backend change.
        $flowSvc  = app(\App\Services\Tenant\BookingFlowService::class);
        $flowMode = $flowSvc->mode($tenant);
        $flow     = request()->query('flow');

        if ($flowSvc->showFork($flowMode, $flow)) {
            return view('public.booking-choice', compact('bk', 'flowMode', 'bookingSections')); // MARKER-PATCH-604
        }

        $simpleServices = $flowSvc->simpleServices($tenant);
        $view = $flowSvc->useSimpleView($flowMode, $flow) ? 'public.booking-simple' : 'public.booking';

        return view($view, compact(
            'catalog', 'formSections', 'receivingMethods',
            'stripeEnabled', 'paypalEnabled', 'stripePublishableKey', 'paypalClientId',
            'bookingMode', 'resources', 'bk', 'simpleServices', 'flowMode',
            'bookingSections' // MARKER-PATCH-604
        ));
    }

    public function availability(Request $request)
    {
        $request->validate([
            'year'       => ['required', 'integer', 'min:2024', 'max:2030'],
            'month'      => ['required', 'integer', 'min:1', 'max:12'],
            'service_id' => ['nullable', 'string', 'uuid'],
        ]);

        $tenant     = tenant();
        $mode       = $tenant->booking_mode ?? 'drop_off';
        $booking    = app(BookingService::class);
        $year       = (int) $request->input('year');
        $month      = (int) $request->input('month');
        $serviceId  = $request->input('service_id');

        $capacityMap = []; // MARKER-PATCH-517
        $dates = $booking->availableDates($tenant, $year, $month, $serviceId, $capacityMap);

        $available   = array_flip($dates);
        $unavailable = [];
        $windowDays     = $tenant->booking_window_days ?? 60;
        $minNoticeHours = $tenant->min_notice_hours    ?? 24;
        $earliestDay = now()->addHours($minNoticeHours)->startOfDay();
        $latestDay   = now()->addDays($windowDays)->endOfDay();
        $monthStart  = \Carbon\Carbon::create($year, $month, 1)->max($earliestDay);
        $monthEnd    = \Carbon\Carbon::create($year, $month, 1)->endOfMonth()->min($latestDay);
        if ($monthStart->lte($monthEnd)) {
            $cur = $monthStart->copy();
            while ($cur->lte($monthEnd)) {
                $ds = $cur->toDateString();
                if (!isset($available[$ds])) $unavailable[] = $ds;
                $cur->addDay();
            }
        }

        $earliest = null;
        if (!empty($dates)) {
            $earliest = ['date' => $dates[0], 'time' => null];
        }

        // MARKER-PATCH-511 — Pickup & delivery: per-date route windows with
        // remaining stop counts. Only present when the tenant runs routes;
        // the P2b frontend renders it, older frontends ignore the key.
        $pdWindows = [];
        if ($tenant->deliveries_enabled) {
            $windows = \App\Models\Tenant\TenantRouteWindow::where('tenant_id', $tenant->id)
                ->active()->get();
            if ($windows->isNotEmpty()) {
                foreach ($dates as $date) {
                    $day = \Carbon\Carbon::parse($date);
                    $dayWindows = [];
                    foreach ($windows as $w) {
                        if (! $w->runsOn($day)) continue;
                        $remaining = $w->remainingStops($day);
                        $dayWindows[] = [
                            'id'        => $w->id,
                            'label'     => $w->label,
                            'starts_at' => substr((string) $w->starts_at, 0, 5),
                            'ends_at'   => substr((string) $w->ends_at, 0, 5),
                            'remaining' => $remaining,
                            'full'      => $remaining === 0,
                        ];
                    }
                    if (! empty($dayWindows)) $pdWindows[$date] = $dayWindows;
                }
            }
        }

        $slots = [];
        $slotResources = [];

        if ($mode === 'time_slots') {
            // Load active resources once; reused for every slot's availability check.
            $resources = $tenant->resources()->where('is_active', true)->get(['id']);

            // Default slot duration falls back to the day's slot_interval inside
            // resourceIsFreeDuring; passing 0 here lets the service decide. We
            // instead pass a real duration (interval) per-day so prep/cleanup math
            // stays consistent with availableSlotsForDate.
            foreach ($dates as $date) {
                $daySlots = $booking->availableSlotsForDate($tenant, $date);
                $slots[$date] = $daySlots;

                if (empty($daySlots) || $resources->isEmpty()) {
                    continue;
                }

                // Pull the rule once per day for slot duration.
                $dow = \Carbon\Carbon::parse($date)->dayOfWeek;
                $rule = \App\Models\Tenant\TenantCapacityRule::where('tenant_id', $tenant->id)
                    ->where('rule_type', 'default')->where('day_of_week', $dow)->first();
                $interval = (int) ($rule->slot_interval_minutes ?? 60);

                $slotResources[$date] = [];
                foreach ($daySlots as $time) {
                    $freeIds = [];
                    foreach ($resources as $res) {
                        $conflict = $booking->resourceIsFreeDuring(
                            $tenant->id, $res->id, $date, $time . ':00', $interval
                        );
                        if ($conflict === null) {
                            $freeIds[] = $res->id;
                        }
                    }
                    $slotResources[$date][$time] = $freeIds;
                }
            }

            // Fill in earliest.time from the first available day's first slot.
            // We walk forward in case the first day happens to have no slots
            // (rare edge case — e.g. all slots were just booked but the day
            // hasn't fully tipped to 'unavailable' yet in the cap math).
            if ($earliest !== null) {
                foreach ($dates as $d) {
                    if (!empty($slots[$d])) {
                        $earliest['date'] = $d;
                        $earliest['time'] = $slots[$d][0];
                        break;
                    }
                }
            }
        }

        return response()->json([
            'dates'             => $dates,
            'unavailable_dates' => $unavailable,
            'earliest'          => $earliest,
            'slots'             => $slots,
            'pd_windows'        => $pdWindows, // MARKER-PATCH-511
            'capacity'          => $capacityMap, // MARKER-PATCH-517
            'pd_need_by'        => ! empty($pdWindows) && (bool) (((array) ($tenant->settings ?? []))['pd_need_by_enabled'] ?? true), // MARKER-PATCH-519
            'pd_lead_days'      => (int) (((array) ($tenant->settings ?? []))['pd_pickup_lead_days'] ?? 1), // MARKER-PATCH-520
            'pd_allow_day_of'   => (bool) (((array) ($tenant->settings ?? []))['pd_allow_day_of'] ?? false), // MARKER-PATCH-524
            'slot_resources'    => $slotResources,
            'mode'              => $mode,
        ]);
    }

    public function submit(Request $request)
    {
        $request->validate([
            'first_name'              => ['required', 'string', 'max:100'],
            'last_name'               => ['required', 'string', 'max:100'],
            'email'                   => ['required', 'email', 'max:191'],
            'phone'                   => ['nullable', 'string', 'max:32'],
            'date'                    => ['required', 'date', 'after_or_equal:today'],
            'route_window_id'         => ['nullable', 'uuid'], // MARKER-PATCH-512
            'pickup_outreach'         => ['nullable', 'boolean'], // MARKER-PICKUP-OUTREACH
            'pickup_date'             => ['nullable', 'date', 'after_or_equal:today', 'before_or_equal:date'], // MARKER-PATCH-520
            'need_by'                 => ['nullable', 'date', 'after_or_equal:date'], // MARKER-PATCH-512
            'appointment_time'        => ['nullable', 'string'],
            'resource_id'             => ['nullable', 'string', 'uuid'],
            'receiving_method'        => ['nullable', 'string'],
            'items'                   => ['required', 'array', 'min:1'],
            'items.*.service_item_id' => ['required', 'string'],
            'items.*.addon_ids'       => ['nullable', 'array'],
            'items.*.addon_ids.*'     => ['string'],
            'responses'               => ['nullable', 'array'],
            'response_labels'         => ['nullable', 'array'],
            'payment_method'          => ['required', 'in:stripe,paypal,none'],
            // MARKER-PATCH-216 — multi-asset booking persistence
            'customer_id'                => ['nullable', 'string', 'uuid'],
            'items.*.asset_client_key'   => ['nullable', 'string', 'max:64'],
            'assets'                     => ['nullable', 'array', 'max:25'],
            'assets.*.client_key'        => ['required_with:assets', 'string', 'max:64'],
            'assets.*.name_snapshot'     => ['nullable', 'string', 'max:200'],
            'assets.*.customer_asset_id' => ['nullable', 'string', 'uuid'],
        ]);

        $tenant = tenant();

        // MARKER-PATCH-385 — Card deposits use charge-then-create: reserve a slot
        // and a PaymentIntent now, and materialize the appointment only after the
        // card clears (see finalize()). Non-card paths fall through to the
        // original create-then-charge flow below.
        if ($request->input('payment_method') === 'stripe') {
            $direct = new \App\Services\Tenant\DirectPaymentsService($tenant);
            if (! $direct->isEnabled()) {
                return response()->json(['success' => false, 'message' => 'Card payments are not set up yet.'], 422);
            }

            try {
                $pending = app(BookingService::class)->reserve($request->all(), $tenant->id);
            } catch (LockAcquisitionException $e) {
                return response()->json(['success' => false, 'code' => 'lock_timeout', 'message' => 'We couldn\'t hold this slot in time. Please try again.'], 409);
            } catch (RuntimeException $e) {
                if (str_contains($e->getMessage(), 'just taken')) {
                    return response()->json(['success' => false, 'code' => 'slot_taken', 'message' => $e->getMessage()], 409);
                }
                return response()->json(['success' => false, 'message' => $e->getMessage()], 422);
            }

            // Zero-total booking that routed to card -> no charge, materialize now.
            if ((int) $pending->total_cents === 0) {
                $appt = app(BookingService::class)->materialize($pending);
                return response()->json(['success' => true, 'redirect' => url("/confirm?ra={$appt->ra_number}"), 'ra_number' => $appt->ra_number]);
            }

            try {
                $pi = $direct->createPaymentIntent(
                    (int) $pending->total_cents,
                    strtolower((string) ($tenant->currency ?? 'usd')),
                    ['pending_booking_id' => $pending->id, 'pending_token' => $pending->token]
                );
            } catch (\Throwable $e) {
                \Illuminate\Support\Facades\Log::error('booking.payment_intent_failed', ['tenant_id' => $tenant->id, 'pending_id' => $pending->id, 'error' => $e->getMessage()]);
                return response()->json(['success' => false, 'message' => 'Could not start the card payment. Please try again.'], 422);
            }

            $pending->update(['stripe_payment_intent_id' => $pi->id]);

            return response()->json([
                'success' => true, 'payment' => 'stripe',
                'client_secret' => $pi->client_secret,
                'pending_token' => $pending->token,
            ]);
        }

        // Concurrency-protected booking creation.
        // Lock timeout → slot likely contended right now; caller should retry.
        // Slot-just-taken → someone else grabbed this exact time between
        //   the customer loading the picker and submitting.
        // Both return 409 Conflict with specific messages so the frontend can
        //   either auto-retry (timeout) or refresh the picker (slot taken).
        try {
            $appointment = app(BookingService::class)->createAppointment($request->all(), $tenant->id);
        } catch (LockAcquisitionException $e) {
            return response()->json([
                'success' => false,
                'code'    => 'lock_timeout',
                'message' => 'We couldn\'t hold this slot in time. Please try again.',
            ], 409);
        } catch (RuntimeException $e) {
            // Distinguish "slot just taken" from other runtime errors by checking
            // the canonical message. Other RuntimeExceptions (invalid service id,
            // missing email, etc.) are client-input errors and surface as 422-ish.
            if (str_contains($e->getMessage(), 'just taken')) {
                return response()->json([
                    'success' => false,
                    'code'    => 'slot_taken',
                    'message' => $e->getMessage(),
                ], 409);
            }
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 422);
        }

        // patch-93 booking soft SO — detect a customer-typed part request and
        // create a status=needed SO. Triggers when a custom field with
        // field_key='so_request' (case-insensitive) has a non-empty response.
        // Tenants enable this by adding such a field to their booking form.
        try {
            $responses = (array) $request->input('responses', []);
            $soRequestText = null;

            foreach ($responses as $fieldKey => $value) {
                if (strtolower((string) $fieldKey) === 'so_request') {
                    $trimmed = trim((string) $value);
                    if ($trimmed !== '') {
                        $soRequestText = $trimmed;
                    }
                    break;
                }
            }

            if ($soRequestText !== null && $appointment) {
                $soSvc = app(\App\Services\Tenant\SpecialOrderService::class);
                $soSvc->create([
                    'tenant_id'           => $tenant->id,
                    'inventory_item_id'   => null,
                    'item_name_snapshot'  => mb_substr($soRequestText, 0, 255),
                    'quantity'            => 1,
                    'customer_id'         => $appointment->customer_id,
                    'appointment_id'      => $appointment->id,
                    'status'              => \App\Models\Tenant\TenantSpecialOrder::STATUS_NEEDED,
                    'created_from'        => 'booking',
                ]);
            }
        } catch (\Throwable $e) {
            // Soft SO creation is best-effort — never block booking completion.
            \Illuminate\Support\Facades\Log::warning('Booking soft SO creation failed', [
                'tenant_id' => $tenant->id,
                'appointment_id' => $appointment->id ?? null,
                'error' => $e->getMessage(),
            ]);
        }

        $paymentMethod = $request->input('payment_method');

        if ($paymentMethod === 'none' || $appointment->total_cents === 0) {
            return response()->json([
                'success' => true,
                'redirect' => url("/confirm?ra={$appointment->ra_number}"),
                'ra_number' => $appointment->ra_number,
            ]);
        }

        // MARKER-PATCH-385 — card path is handled at the top of submit()
        // (charge-then-create); the old StripeService branch is retired.

        if ($paymentMethod === 'paypal') {
            $paypal = new PayPalService($tenant);
            if (!$paypal->isConfigured()) {
                return response()->json(['success' => false, 'message' => 'PayPal is not configured.'], 422);
            }
            $order = $paypal->createOrder($appointment);
            return response()->json([
                'success' => true, 'payment' => 'paypal',
                'approve_url' => $order['approve_url'],
                'ra_number' => $appointment->ra_number,
            ]);
        }

        return response()->json(['success' => false, 'message' => 'Unknown payment method.'], 422);
    }

    /**
     * MARKER-PATCH-385 — Materialize a paid booking hold into an appointment.
     * Called by the browser after the card confirms. Verifies the PaymentIntent
     * actually succeeded against Stripe (never trusts the client), then writes
     * the appointment via BookingService::materialize (idempotent).
     */
    public function finalize(Request $request)
    {
        $tenant = tenant();
        $token  = (string) $request->input('pending_token');

        $pending = \App\Models\Tenant\TenantPendingBooking::where('tenant_id', $tenant->id)
            ->where('token', $token)
            ->first();
        if (! $pending) {
            return response()->json(['success' => false, 'message' => 'Booking session not found.'], 404);
        }

        // Idempotent: already materialized.
        if ($pending->status === 'materialized' && $pending->appointment_id) {
            $appt = \App\Models\Tenant\TenantAppointment::find($pending->appointment_id);
            if ($appt) {
                return response()->json(['success' => true, 'redirect' => url("/confirm?ra={$appt->ra_number}"), 'ra_number' => $appt->ra_number]);
            }
        }

        // Never trust the browser — confirm the charge with Stripe directly.
        $direct = new \App\Services\Tenant\DirectPaymentsService($tenant);
        try {
            $pi = $direct->retrievePaymentIntent((string) $pending->stripe_payment_intent_id);
        } catch (\Throwable $e) {
            return response()->json(['success' => false, 'message' => 'Could not verify payment.'], 422);
        }
        if (($pi->status ?? null) !== 'succeeded') {
            return response()->json(['success' => false, 'message' => 'Payment not completed.'], 402);
        }

        try {
            $appt = app(BookingService::class)->materialize($pending);
        } catch (RuntimeException $e) {
            \Illuminate\Support\Facades\Log::error('booking.materialize_failed', ['tenant_id' => $tenant->id, 'pending_id' => $pending->id, 'pi' => $pending->stripe_payment_intent_id, 'error' => $e->getMessage()]);
            return response()->json(['success' => false, 'message' => 'Your payment went through but that time was just taken. We will reach out to reschedule.'], 409);
        }

        return response()->json(['success' => true, 'redirect' => url("/confirm?ra={$appt->ra_number}"), 'ra_number' => $appt->ra_number]);
    }

    public function paypalReturn(Request $request)
    {
        $orderId = $request->query('token');
        if (!$orderId) return redirect('/book')->with('error', 'PayPal payment was cancelled.');

        try {
            $appointment = PayPalService::handleReturn(tenant(), $orderId);
            return redirect("/confirm?ra={$appointment->ra_number}");
        } catch (\Throwable $e) {
            logger()->error('PayPal return error: ' . $e->getMessage());
            return redirect('/book')->with('error', 'Payment could not be completed. Please try again.');
        }
    }

    public function paypalWebhook(Request $request)
    {
        logger()->info('PayPal webhook received', $request->all());
        return response('ok');
    }
}
OUTREACH_3_EOF

cat > 'app/Services/BookingService.php' <<'OUTREACH_4_EOF'
<?php

namespace App\Services;

use App\Models\Tenant;
use App\Models\Tenant\TenantAppointment;
use App\Models\Tenant\TenantAppointmentAddon;
use App\Models\Tenant\TenantAppointmentItem;
use App\Models\Tenant\TenantCalendarBreak;
use App\Models\Tenant\TenantCapacityRule;
use App\Models\Tenant\TenantCustomer;
use App\Models\Tenant\TenantServiceItem;
use App\Models\Tenant\TenantServiceAddon;
use App\Models\Tenant\TenantWalkinHold;
use App\Support\MySQLLock;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use App\Jobs\SendBookingConfirmationJob;
use RuntimeException;

class BookingService
{
    /**
     * Create a booking appointment with concurrency protection.
     *
     * Lock scopes (all go through MySQLLock::withLock):
     *   time-slot + resource:  intake:{tenant}:booking:{resource}:{date}-{time}
     *   time-slot + any:       intake:{tenant}:booking:anyresource:{date}-{time}
     *   drop-off:              intake:{tenant}:dropoff:{date}
     *
     * Drop-off still gets a lock — at 500+ tenants in peak season, two
     * simultaneous submits against a nearly-full capacity race the
     * slot_weight sum and cause subtle overbooking. Lock scope is
     * per-tenant-per-day, which is wider than the time-slot lock but
     * fires rarely enough that contention is a non-issue.
     */
    public function createAppointment(array $data, string $tenantId): TenantAppointment
    {
        if (empty($data['items']) || !is_array($data['items'])) {
            throw new RuntimeException('At least one service is required.');
        }

        $plan = $this->buildBookingPlan($data['items'], $tenantId);

        $totalCents          = 0;
        $totalDuration       = 0;
        $customerFacingDur   = 0;  // duration excluding prep/cleanup — what the customer "sees"
        $slotWeight          = 0;

        foreach ($plan as $row) {
            $service = $row['service'];
            $totalCents        += (int) ($row['effective_price_cents'] ?? $service->price_cents);
            $customerFacingDur += (int) $service->duration_minutes;
            $totalDuration     += (int) $service->prep_before_minutes
                                + (int) $service->duration_minutes
                                + (int) $service->cleanup_after_minutes;
            $slotWeight        += (int) ($service->slot_weight ?? 1);

            foreach ($row['addons'] as $addonRow) {
                $totalCents        += (int) $addonRow['effective_price_cents'];
                $totalDuration     += (int) $addonRow['effective_duration'];
                $customerFacingDur += (int) $addonRow['effective_duration'];
            }
        }

        $appointmentTime    = !empty($data['appointment_time']) ? $data['appointment_time'] : null;
        $resourceId         = !empty($data['resource_id'])      ? $data['resource_id']      : null;

        // Validate resource belongs to this tenant and is active. Prevents
        // cross-tenant id submission and bookings against archived resources.
        // The slot re-check inside the lock catches "just taken", but does not
        // catch "this resource was deactivated 30 seconds ago" — that case
        // would slip through silently otherwise.
        if ($resourceId !== null) {
            $resourceOk = \App\Models\Tenant\TenantResource::where('id', $resourceId)
                ->where('tenant_id', $tenantId)
                ->where('is_active', true)
                ->exists();
            if (!$resourceOk) {
                throw new RuntimeException('That selection is no longer available. Please pick another.');
            }
        }

        $appointmentEndTime = null;
        if ($appointmentTime && $totalDuration > 0) {
            $start = new \DateTimeImmutable($appointmentTime);
            $end = $start->modify("+{$totalDuration} minutes");
            $appointmentEndTime = $end->format('H:i:s');
        }

        $tenant   = Tenant::findOrFail($tenantId);
        $mode     = $tenant->booking_mode ?? 'drop_off';

        // ------------------------------------------------------------------
        // Drop-off mode: resolve resource via auto-fallback.
        //
        // Walks the eligible-resources list (per service) and picks the
        // first one with remaining daily capacity. Skips resources whose
        // per-resource cap is already met. If no resource has space, throws
        // a clean "shop full today" error.
        //
        // For multi-service bookings, each service has its own eligibility
        // list; the chosen resource must satisfy the intersection. We use
        // the FIRST service's eligibility as the base and intersect with
        // each subsequent service's list. Empty intersection = "no single
        // resource can do all selected services" — also a hard reject.
        //
        // If $resourceId was provided up-front (e.g. customer picked one
        // explicitly), we honor that pick if it's eligible AND has capacity.
        // Otherwise we fall through. This keeps the explicit-pick path
        // working without forcing every drop-off booking to auto-assign.
        // ------------------------------------------------------------------
        if ($mode === 'drop_off' && $resourceId === null) {
            // Eligibility intersection across all selected services.
            $candidateIds = null;
            foreach ($plan as $row) {
                $serviceId = $row['service']->id;
                $svcEligible = $this->eligibleResourcesForService($tenantId, $serviceId);
                if ($candidateIds === null) {
                    $candidateIds = $svcEligible;
                } else {
                    $candidateIds = array_values(array_intersect($candidateIds, $svcEligible));
                }
                if (empty($candidateIds)) break;
            }

            if (empty($candidateIds)) {
                throw new RuntimeException(
                    'No staff member can perform every selected service. '
                    . 'Please pick a different combination.'
                );
            }

            // Pull caps for candidates in one query, then walk them in
            // sort_order to pick the first with remaining quota.
            $candidates = \App\Models\Tenant\TenantResource::where('tenant_id', $tenantId)
                ->whereIn('id', $candidateIds)
                ->where('is_active', true)
                ->orderBy('sort_order')
                ->get(['id', 'max_appointments_per_day']);

            $picked = null;
            foreach ($candidates as $cand) {
                $cap = $cand->max_appointments_per_day;
                if ($cap === null) {
                    // Unbounded — always pick first unbounded resource.
                    $picked = $cand->id;
                    break;
                }
                $used = $this->resourceUsedSlotsForDate($tenantId, $cand->id, $data['date']);
                if (($used + $slotWeight) <= (int) $cap) {
                    $picked = $cand->id;
                    break;
                }
            }

            if ($picked === null) {
                throw new RuntimeException('All staff are fully booked on that date. Please pick another day.');
            }

            $resourceId = $picked;
        }
        $lockKey  = $this->computeLockKey($mode, $tenantId, $data['date'], $appointmentTime, $resourceId);
        $lock     = app(MySQLLock::class);

        return $lock->withLock($lockKey, function () use (
            $tenant, $mode, $data, $tenantId, $plan,
            $totalCents, $totalDuration, $customerFacingDur, $slotWeight,
            $appointmentTime, $appointmentEndTime, $resourceId
        ) {
            // Re-check availability inside the lock. This is the read-your-writes
            // step that makes the lock meaningful — without it, we'd just be
            // serializing inserts without actually preventing double-booking.
            if ($mode === 'time_slots' && $appointmentTime) {
                $openSlots = $this->availableSlotsForDate(
                    $tenant,
                    $data['date'],
                    $resourceId,
                    $customerFacingDur
                );
                // appointment_time may be HH:MM:SS; availableSlotsForDate returns HH:MM.
                $wanted = substr($appointmentTime, 0, 5);
                if (!in_array($wanted, $openSlots, true)) {
                    throw new RuntimeException('That time slot was just taken. Please pick another.');
                }
            }
            // Drop-off mode: the existing availableDates logic already consults
            // capacity, so we trust that. If drop-off capacity races become a real
            // problem we add a re-check here similar to the time-slot path.

            return DB::transaction(function () use (
                $data, $tenantId, $plan,
                $totalCents, $totalDuration, $slotWeight,
                $appointmentTime, $appointmentEndTime, $resourceId
            ) {
                $customer = $this->upsertCustomer($data, $tenantId);
                $raNumber = TenantAppointment::generateRaNumber($tenantId, $data['date'] ?? null);

                // Resolve location: caller-provided wins; otherwise tenant's default.
                $locationId = $data['location_id'] ?? null;
                if (! $locationId) {
                    $locationId = \App\Models\Tenant\TenantLocation::query()
                        ->where('tenant_id', $tenantId)
                        ->where('is_active', 1)
                        ->orderByDesc('is_default')
                        ->orderBy('created_at')
                        ->value('id');
                }

                $appointment = TenantAppointment::create([
                    'id'                       => (string) Str::uuid(),
                    'tenant_id'                => $tenantId,
                    'customer_id'              => $customer->id,
                    'resource_id'              => $resourceId,
                    'location_id'              => $locationId,
                    'ra_number'                => $raNumber,
                    'customer_first_name'      => $data['first_name'] ?? '',
                    'customer_last_name'       => $data['last_name']  ?? '',
                    'customer_email'           => strtolower(trim($data['email'] ?? '')),
                    'customer_phone'           => $data['phone']      ?? null,
                    'appointment_date'         => $data['date'],
                    'appointment_time'         => $appointmentTime,
                    'appointment_end_time'     => $appointmentEndTime,
                    'total_duration_minutes'   => $totalDuration,
                    'slot_weight'              => $slotWeight,
                    'slot_weight_auto'         => $slotWeight,
                    'slot_weight_overridden'   => false,
                    'receiving_method_snapshot'=> $data['receiving_method'] ?? null,
                    'status'                   => 'pending',
                    'payment_status'           => 'unpaid',
                    'payment_method'           => $data['payment_method']   ?? null,
                    'subtotal_cents'           => $totalCents,
                    'tax_cents'                => 0,
                    'total_cents'              => $totalCents,
                    'paid_cents'               => 0,
                ]);

                // MARKER-PATCH-216 — multi-asset persistence. Create the
                // appointment-asset rows first so each item/addon can be
                // tagged with appointment_asset_id at insert time. Empty map
                // in single-asset mode -> $rowAssetId stays null and the
                // write path is identical to pre-216.
                $assetMap     = $this->persistAppointmentAssets($appointment, $customer, $data, $tenantId);
                $firstAssetId = !empty($assetMap) ? array_values($assetMap)[0]->id : null;

                foreach ($plan as $row) {
                    $service = $row['service'];

                    $rowAssetId = null;
                    if (!empty($assetMap)) {
                        $key        = $row['asset_client_key'] ?? null;
                        $rowAssetId = ($key !== null && isset($assetMap[$key]))
                            ? $assetMap[$key]->id   // tagged + known key
                            : $firstAssetId;        // untagged / orphan key -> first asset (WP parity)
                    }

                    TenantAppointmentItem::create([
                        'appointment_asset_id'           => $rowAssetId, // MARKER-PATCH-216
                        'id'                             => (string) Str::uuid(),
                        'appointment_id'                 => $appointment->id,
                        'service_item_id'                => $service->id,
                        'item_name_snapshot'             => $service->name,
                        'price_cents'                    => $service->price_cents,
                        'price_cents_override'           => $row['price_override_cents'] ?? null,
                        'duration_minutes_snapshot'      => $service->duration_minutes,
                        'prep_before_minutes_snapshot'   => $service->prep_before_minutes ?? 0,
                        'cleanup_after_minutes_snapshot' => $service->cleanup_after_minutes ?? 0,
                    ]);
                    foreach ($row['addons'] as $addonRow) {
                        TenantAppointmentAddon::create([
                            'id'                        => (string) Str::uuid(),
                            'appointment_id'            => $appointment->id,
                            'appointment_asset_id'      => $rowAssetId, // MARKER-PATCH-216
                            'addon_id'                  => $addonRow['addon']->id,
                            'addon_name_snapshot'       => $addonRow['addon']->name,
                            'price_cents'               => $addonRow['effective_price_cents'],
                            'duration_minutes_snapshot' => $addonRow['effective_duration'],
                        ]);
                    }
                }

                // MARKER-PATCH-216 — denormalized per-asset rollups for the
                // admin right-rail. Small N (bikes on one booking), in-txn.
                foreach ($assetMap as $aa) {
                    $aa->refreshSubtotal();
                }

                $this->persistResponses($appointment, $data);

                // Dispatch the booking-confirmation notification.
                // afterCommit() ensures the job only fires if the DB transaction
                // actually commits — never send a confirmation for a phantom
                // appointment that got rolled back by a later error in the chain.
                SendBookingConfirmationJob::dispatch($appointment->id)->afterCommit();

                // MARKER-PATCH-225 — staff alert. emit() defers its own work
                // to afterCommit, so a rolled-back booking emits nothing.
                $tenantModel = \App\Models\Tenant::find($tenantId);
                if ($tenantModel) {
                    $custName = trim(($appointment->customer->first_name ?? '') . ' ' . ($appointment->customer->last_name ?? '')) ?: 'A customer';
                    $whenLabel = $appointment->appointment_date
                        ? \Illuminate\Support\Carbon::parse($appointment->appointment_date)->format('M j')
                            . ($appointment->appointment_time ? ' at ' . \Illuminate\Support\Carbon::parse($appointment->appointment_time)->format('g:i A') : '')
                        : 'soon';
                    app(\App\Services\Tenant\StaffAlertService::class)->emit($tenantModel, 'booking.created', [
                        'title' => 'New booking',
                        'body'  => $custName . ' booked ' . $whenLabel,
                        'link'  => route('tenant.appointments.show', $appointment->id),
                        'meta'  => ['appointment_id' => $appointment->id],
                    ]);
                }

                // MARKER-PATCH-512 — Pickup & delivery: the booked pickup stop.
                // Runs for both public paths (direct + pending->materialize) since
                // both funnel the raw payload through createAppointment.
                if (!empty($data['route_window_id'])) {
                    $this->createPickupStop($appointment, (string) $data['route_window_id'], (array) $data);
                } elseif (!empty($data['pickup_outreach'])) {
                    // MARKER-PICKUP-OUTREACH — customer skipped the window
                    // choice and asked to be contacted about pickup.
                    $appointment->forceFill(['pickup_outreach_pending' => true])->save();
                    $outreachTenant = \App\Models\Tenant::find($tenantId);
                    if ($outreachTenant) {
                        $outreachName = trim(($appointment->customer->first_name ?? '') . ' ' . ($appointment->customer->last_name ?? '')) ?: 'A customer';
                        app(\App\Services\Tenant\StaffAlertService::class)->emit($outreachTenant, 'booking.pickup_outreach', [
                            'title' => 'Pickup to arrange',
                            'body'  => $outreachName . ' asked you to reach out about pickup for their booking',
                            'link'  => route('tenant.appointments.show', $appointment->id),
                            'meta'  => ['appointment_id' => $appointment->id],
                        ]);
                    }
                }
                if (!empty($data['need_by']) && (bool) (((array) $tenant->settings)['pd_need_by_enabled'] ?? true)) {
                    $appointment->forceFill(['need_by' => $data['need_by']])->save();
                }

                return $appointment->fresh(['items', 'addons', 'customer', 'responses']);
            });
        });
    }

    /**
     * MARKER-PATCH-384 — Reserve a slot with a pending hold (charge-then-create
     * step). Mirrors createAppointment's prep exactly, via the same helpers, but
     * writes a TenantPendingBooking instead of an appointment. The appointment is
     * only materialized after payment succeeds.
     */
    public function reserve(array $data, string $tenantId): \App\Models\Tenant\TenantPendingBooking
    {
        if (empty($data['items']) || !is_array($data['items'])) {
            throw new RuntimeException('At least one service is required.');
        }

        $plan = $this->buildBookingPlan($data['items'], $tenantId);

        $totalCents        = 0;
        $totalDuration     = 0;
        $customerFacingDur = 0;
        $slotWeight        = 0;
        foreach ($plan as $row) {
            $service = $row['service'];
            $totalCents        += (int) ($row['effective_price_cents'] ?? $service->price_cents);
            $customerFacingDur += (int) $service->duration_minutes;
            $totalDuration     += (int) $service->prep_before_minutes
                                + (int) $service->duration_minutes
                                + (int) $service->cleanup_after_minutes;
            $slotWeight        += (int) ($service->slot_weight ?? 1);
            foreach ($row['addons'] as $addonRow) {
                $totalCents        += (int) $addonRow['effective_price_cents'];
                $totalDuration     += (int) $addonRow['effective_duration'];
                $customerFacingDur += (int) $addonRow['effective_duration'];
            }
        }

        $appointmentTime = !empty($data['appointment_time']) ? $data['appointment_time'] : null;
        $resourceId      = !empty($data['resource_id'])      ? $data['resource_id']      : null;

        if ($resourceId !== null) {
            $resourceOk = \App\Models\Tenant\TenantResource::where('id', $resourceId)
                ->where('tenant_id', $tenantId)
                ->where('is_active', true)
                ->exists();
            if (!$resourceOk) {
                throw new RuntimeException('That selection is no longer available. Please pick another.');
            }
        }

        $tenant = Tenant::findOrFail($tenantId);
        $mode   = $tenant->booking_mode ?? 'drop_off';

        if ($mode === 'drop_off' && $resourceId === null) {
            $candidateIds = null;
            foreach ($plan as $row) {
                $svcEligible  = $this->eligibleResourcesForService($tenantId, $row['service']->id);
                $candidateIds = $candidateIds === null
                    ? $svcEligible
                    : array_values(array_intersect($candidateIds, $svcEligible));
                if (empty($candidateIds)) break;
            }
            if (empty($candidateIds)) {
                throw new RuntimeException(
                    'No staff member can perform every selected service. '
                    . 'Please pick a different combination.'
                );
            }
            $candidates = \App\Models\Tenant\TenantResource::where('tenant_id', $tenantId)
                ->whereIn('id', $candidateIds)
                ->where('is_active', true)
                ->orderBy('sort_order')
                ->get(['id', 'max_appointments_per_day']);
            $picked = null;
            foreach ($candidates as $cand) {
                if ($cand->max_appointments_per_day === null) { $picked = $cand->id; break; }
                $used = $this->resourceUsedSlotsForDate($tenantId, $cand->id, $data['date']);
                if (($used + $slotWeight) <= (int) $cand->max_appointments_per_day) { $picked = $cand->id; break; }
            }
            if ($picked === null) {
                throw new RuntimeException('All staff are fully booked on that date. Please pick another day.');
            }
            $resourceId = $picked;
        }

        $lockKey = $this->computeLockKey($mode, $tenantId, $data['date'], $appointmentTime, $resourceId);
        $lock    = app(MySQLLock::class);

        return $lock->withLock($lockKey, function () use (
            $tenant, $mode, $data, $tenantId, $resourceId, $appointmentTime,
            $customerFacingDur, $slotWeight, $totalDuration, $totalCents
        ) {
            if ($mode === 'time_slots' && $appointmentTime) {
                $openSlots = $this->availableSlotsForDate($tenant, $data['date'], $resourceId, $customerFacingDur);
                $wanted = substr($appointmentTime, 0, 5);
                if (!in_array($wanted, $openSlots, true)) {
                    throw new RuntimeException('That time slot was just taken. Please pick another.');
                }
            }

            return \App\Models\Tenant\TenantPendingBooking::create([
                'id'                     => (string) Str::uuid(),
                'tenant_id'              => $tenantId,
                'token'                  => (string) Str::uuid(),
                'status'                 => 'pending',
                'resource_id'            => $resourceId,
                'booking_date'           => $data['date'],
                'appointment_time'       => $appointmentTime,
                'slot_weight'            => $slotWeight,
                'total_duration_minutes' => $totalDuration,
                'total_cents'            => $totalCents,
                'payload'                => $data,
                'contact_email'          => strtolower(trim((string) ($data['email'] ?? ''))),
                'contact_name'           => trim(((string) ($data['first_name'] ?? '')) . ' ' . ((string) ($data['last_name'] ?? ''))),
                'expires_at'             => now()->addMinutes(20),
            ]);
        });
    }

    /**
     * MARKER-PATCH-384 — Materialize a paid hold into a real appointment. Wraps
     * the unchanged createAppointment so the appointment write stays on the
     * battle-tested path. Idempotent: a hold already materialized returns its
     * appointment. The hold is flipped to 'materialized' before the write so the
     * slot re-check inside createAppointment doesn't see this booking's own hold
     * as a conflict; a failed write rolls the flip back.
     */
    /**
     * MARKER-PATCH-512 — create the pickup route stop for a public booking.
     * Capacity re-checked under an advisory lock keyed tenant+window+date;
     * a full window throws (surfaces as booking failed, same as slot_taken).
     */
    protected function createPickupStop(TenantAppointment $appointment, string $windowId, array $data): void
    {
        $window = \App\Models\Tenant\TenantRouteWindow::where('tenant_id', $appointment->tenant_id)
            ->where('is_active', true)
            ->find($windowId);
        if (! $window) {
            throw new RuntimeException('That pickup window is no longer available.');
        }

        // MARKER-PATCH-520 — the stop lands on pickup_date (a lead day),
        // falling back to the service date for older payloads.
        $date = \Carbon\Carbon::parse($data['pickup_date'] ?? $data['date']);
        if (! $window->runsOn($date)) {
            throw new RuntimeException('That pickup window does not run on the selected day.');
        }

        // MARKER-PATCH-524 — same-day pickups are opt-in; reject a pickup dated
        // on the service day itself when the tenant hasn't enabled them.
        $tenantSettings = (array) (\App\Models\Tenant::find($appointment->tenant_id)?->settings ?? []);
        $allowDayOf = (bool) ($tenantSettings['pd_allow_day_of'] ?? false);
        if (! $allowDayOf && isset($data['date']) && $date->toDateString() === \Carbon\Carbon::parse($data['date'])->toDateString()) {
            throw new RuntimeException('Same-day pickup is not available — please pick an earlier window.');
        }

        $lockKey = 'pdwin:' . $appointment->tenant_id . ':' . $window->id . ':' . $date->toDateString();
        app(MySQLLock::class)->withLock($lockKey, function () use ($window, $date, $appointment) {
            if ($window->remainingStops($date) < 1) {
                throw new RuntimeException('That pickup window just filled up — please pick another.');
            }

            \App\Models\Tenant\TenantDelivery::create([
                'tenant_id'      => $appointment->tenant_id,
                'type'           => 'pickup',
                'status'         => 'scheduled',
                // MARKER-PATCH-513 — scheduled_at is a UTC instant; build the
                // wall-clock moment in the tenant tz, then convert.
                'scheduled_at'   => \Carbon\Carbon::parse($date->toDateString() . ' ' . (string) $window->starts_at,
                                        \App\Models\Tenant::find($appointment->tenant_id)?->timezone() ?? config('app.timezone'))->utc(),
                'window_minutes' => max(15, \Carbon\Carbon::parse((string) $window->starts_at)
                                        ->diffInMinutes(\Carbon\Carbon::parse((string) $window->ends_at))),
                'customer_id'    => $appointment->customer_id,
                'appointment_id' => $appointment->id,
                'address'        => $appointment->customer?->address ?: null,
                'notes'          => 'Booked online — ' . $window->label,
            ]);
        });
    }

    public function materialize(\App\Models\Tenant\TenantPendingBooking $pending): TenantAppointment
    {
        // Fast path: already materialized (no lock needed for the common case).
        if ($pending->status === 'materialized' && $pending->appointment_id) {
            return TenantAppointment::findOrFail($pending->appointment_id);
        }

        return DB::transaction(function () use ($pending) {
            // MARKER-PATCH-474 — lock the hold row so the browser finalize() and the
            // Stripe payment_intent.succeeded webhook can't both materialize the same
            // booking. Without this row lock, two concurrent calls each read status
            // 'pending' and each write an appointment (the double-booking bug).
            $locked = \App\Models\Tenant\TenantPendingBooking::where('id', $pending->id)
                ->lockForUpdate()
                ->first();

            if ($locked && $locked->status === 'materialized' && $locked->appointment_id) {
                return TenantAppointment::findOrFail($locked->appointment_id);
            }

            $locked->update(['status' => 'materialized']);
            $appointment = $this->createAppointment((array) $locked->payload, $locked->tenant_id);
            $locked->update(['appointment_id' => $appointment->id]);

            // MARKER-PATCH-474 — record the prepaid card charge through the same path a
            // backend appointment uses, so booking revenue reconciles into the one ledger.
            $this->recordPrepaidDeposit($locked, $appointment);

            return $appointment;
        });
    }

    /**
     * MARKER-PATCH-474 — Record a booking's prepaid card charge as a sale payment on
     * the appointment's balance sale, mirroring a backend-created appointment exactly:
     * AppointmentRegisterBridgeService builds the linked balance sale and
     * SalePaymentService writes the payment into tenant_sale_payments (the single
     * revenue ledger MoneyReportService reads). The appointment's paid state is then a
     * derived cache recomputed from that ledger, so it reads 'paid' the same way a
     * register/checkout payment would.
     *
     * Idempotent: keyed on the PaymentIntent id (reference_payment_id), so the browser
     * finalize() and the Stripe webhook can each reach this without double-posting.
     * No-ops for zero-total / pay-in-person holds (no PaymentIntent, nothing to record).
     */
    protected function recordPrepaidDeposit(
        \App\Models\Tenant\TenantPendingBooking $pending,
        TenantAppointment $appointment
    ): void {
        $piId   = (string) ($pending->stripe_payment_intent_id ?? '');
        $amount = (int) $pending->total_cents;

        if ($piId === '' || $amount <= 0) {
            return;
        }

        // Already on the ledger? Nothing to do (idempotent across finalize + webhook).
        if (\App\Models\Tenant\TenantSalePayment::where('tenant_id', $pending->tenant_id)
                ->where('reference_payment_id', $piId)
                ->exists()) {
            return;
        }

        // Build the balance-collection sale exactly as the backend does when an
        // appointment enters a committed status.
        $bridge = app(\App\Services\Tenant\AppointmentRegisterBridgeService::class);
        $result = $bridge->onAppointmentEnteringCommittedStatus($appointment);

        $sale = null;
        if (($result['action'] ?? null) === 'sale_created' && !empty($result['sale_id'])) {
            $sale = \App\Models\Tenant\TenantSale::find($result['sale_id']);
        }
        if (! $sale) {
            $sale = $appointment->sales()
                ->whereNotIn('status', ['cancelled', 'closed'])
                ->orderByDesc('created_at')
                ->first();
        }
        if (! $sale) {
            \Illuminate\Support\Facades\Log::error('booking.deposit_no_sale', [
                'tenant_id'      => $pending->tenant_id,
                'appointment_id' => $appointment->id,
                'pi'             => $piId,
            ]);
            return;
        }

        app(\App\Services\Tenant\SalePaymentService::class)->record(
            $sale,
            $amount,
            \App\Models\Tenant\TenantSalePayment::KIND_PAYMENT,
            \App\Models\Tenant\TenantSalePayment::SOURCE_BOOKING_FLOW,
            'stripe',
            $piId,
            null,
            'Prepaid at online booking',
        );
    }

    /**
     * Computes the advisory-lock key for this booking attempt.
     *
     * Key must be <= 64 chars; MySQLLock normalizes via sha1 if it overflows.
     * UUIDs in the key push us close to the limit, so we use a compact format.
     */
    protected function computeLockKey(
        string $mode,
        string $tenantId,
        string $date,
        ?string $appointmentTime,
        ?string $resourceId
    ): string {
        // Trim tenant UUID to 8 chars for readability — still unique enough
        // that lock key collision between tenants is vanishingly unlikely,
        // and MySQLLock normalizes via sha1 anyway if this ever overflows.
        $tenantShort = substr($tenantId, 0, 8);

        if ($mode === 'time_slots' && $appointmentTime) {
            $resource = $resourceId ? substr($resourceId, 0, 8) : 'any';
            $slotKey  = str_replace([':', '-', ' '], '', $date . substr($appointmentTime, 0, 5));
            return "intake:{$tenantShort}:book:{$resource}:{$slotKey}";
        }

        // Drop-off mode or any path without appointment_time: per-day lock.
        $dayKey = str_replace('-', '', $date);
        return "intake:{$tenantShort}:drop:{$dayKey}";
    }

    // MARKER-PATCH-517 — optional $capacity out-param: per-date ['left','max'] (null = unbounded)
    public function availableDates(Tenant $tenant, int $year, int $month, ?string $serviceId = null, ?array &$capacity = null): array
    {
        $windowDays     = $tenant->booking_window_days ?? 60;
        $minNoticeHours = $tenant->min_notice_hours    ?? 24;
        $mode           = $tenant->booking_mode        ?? 'drop_off';

        $earliest = now()->addHours($minNoticeHours)->startOfDay();
        $latest   = now()->addDays($windowDays)->endOfDay();
        $start = Carbon::create($year, $month, 1)->max($earliest);
        $end   = Carbon::create($year, $month, 1)->endOfMonth()->min($latest);
        if ($start->gt($end)) return [];

        $defaults  = TenantCapacityRule::where('tenant_id', $tenant->id)
            ->where('rule_type', 'default')->get()->keyBy('day_of_week');
        $overrides = TenantCapacityRule::where('tenant_id', $tenant->id)
            ->where('rule_type', 'override')
            ->whereBetween('specific_date', [$start->toDateString(), $end->toDateString()])
            ->get()->keyBy(fn($r) => $r->specific_date->toDateString());

        // Service-aware path: when a service is passed, the relevant capacity
        // is only what its eligible resources can absorb. We scope both the
        // resource_cap_sum AND the per-day used count by those resources.
        // When no service is passed (legacy callers), behavior is unchanged.
        $eligibleResourceIds = null;
        if ($serviceId) {
            $eligibleResourceIds = $this->eligibleResourcesForService($tenant->id, $serviceId);
            if (empty($eligibleResourceIds)) {
                // Service has no eligible (active) resources — nothing is bookable.
                return [];
            }
        }

        // Sum of per-resource daily caps. When service-aware, only sum the caps
        // of eligible resources for that service.
        $resourceCapQuery = \App\Models\Tenant\TenantResource::where('tenant_id', $tenant->id)
            ->where('is_active', true)
            ->whereNotNull('max_appointments_per_day');
        if ($eligibleResourceIds !== null) {
            $resourceCapQuery->whereIn('id', $eligibleResourceIds);
        }
        $resourceCapSum = (int) $resourceCapQuery->sum('max_appointments_per_day');

        $available = [];
        $cursor = $start->copy();
        while ($cursor->lte($end)) {
            $dateStr = $cursor->toDateString();
            $dow = $cursor->dayOfWeek;
            $rule = $overrides[$dateStr] ?? $defaults[$dow] ?? null;
            if (!$rule) { $cursor->addDay(); continue; }

            // is_closed short-circuits all other capacity logic — closed means closed.
            if (!empty($rule->is_closed)) { $cursor->addDay(); continue; }

            // max_appointments meaning depends on mode:
            //   - drop_off:    shop-wide cap override (NULL = use resource sum)
            //   - time_slots:  optional cap on grid-derived capacity (NULL = no override)
            $shopOverride = isset($rule->max_appointments) && $rule->max_appointments !== null
                ? (int) $rule->max_appointments
                : null;

            if ($mode === 'drop_off') {
                // Effective cap = min(shop_override, resource_cap_sum) when both set.
                // If neither is set, day is unbounded (treated as 'no cap' — still available).
                $effectiveCap = null;
                if ($shopOverride !== null && $resourceCapSum > 0) {
                    $effectiveCap = min($shopOverride, $resourceCapSum);
                } elseif ($shopOverride !== null) {
                    $effectiveCap = $shopOverride;
                } elseif ($resourceCapSum > 0) {
                    $effectiveCap = $resourceCapSum;
                }
                // Cap of 0 means closed for this day.
                if ($effectiveCap === 0) { $cursor->addDay(); continue; }

                $usedQuery = TenantAppointment::where('tenant_id', $tenant->id)
                    ->whereNotIn('status', ['cancelled', 'refunded'])
                    ->where('appointment_date', $dateStr);
                if ($eligibleResourceIds !== null) {
                    $usedQuery->whereIn('resource_id', $eligibleResourceIds);
                }
                $used = (int) $usedQuery->sum('slot_weight');
                // null effectiveCap = unbounded, which keeps the day available.
                if ($effectiveCap === null || $used < $effectiveCap) {
                    $available[] = $dateStr;
                    // MARKER-PATCH-517
                    if ($capacity !== null) {
                        $capacity[$dateStr] = [
                            'left' => $effectiveCap === null ? null : max(0, $effectiveCap - $used),
                            'max'  => $effectiveCap,
                        ];
                    }
                }
            } else {
                // time_slots: availableSlotsForDate already honors $rule.
                // The shop override (if any) is applied by capping how many
                // distinct slots remain bookable, but the grid math — not
                // a shop-wide weight sum — is the primary gating factor.
                $slots = $this->availableSlotsForDate($tenant, $dateStr, null, 0, $rule);
                if (!empty($slots)) {
                    $available[] = $dateStr;
                    // MARKER-PATCH-517 — for slot mode, "left" = open times that day
                    if ($capacity !== null) {
                        $capacity[$dateStr] = ['left' => count($slots), 'max' => null];
                    }
                }
            }
            $cursor->addDay();
        }
        return $available;
    }

    /**
     * Service→Resource eligibility filter.
     *
     * Returns the active resources that can perform the given service item,
     * scoped to the tenant. EMPTY eligibility table for a service means
     * "any active resource" — specialization is opt-in. This lets the
     * BookingService fall through to whichever resource is free at lock
     * time without forcing tenants to maintain a per-service mapping if
     * they don't need one.
     *
     * Returns an array of resource UUIDs (not models) to keep the result
     * cheaply cacheable per request.
     */
    public function eligibleResourcesForService(string $tenantId, string $serviceId): array
    {
        $rows = \Illuminate\Support\Facades\DB::table('tenant_service_resource_eligibility')
            ->where('tenant_id', $tenantId)
            ->where('service_item_id', $serviceId)
            ->pluck('resource_id')
            ->all();

        if (!empty($rows)) {
            // Filter to active only — a resource may have been deactivated
            // after the eligibility row was written, in which case we should
            // not propose it for new bookings.
            return \App\Models\Tenant\TenantResource::where('tenant_id', $tenantId)
                ->whereIn('id', $rows)
                ->where('is_active', true)
                ->pluck('id')
                ->all();
        }

        // Empty eligibility = all active resources are eligible.
        return \App\Models\Tenant\TenantResource::where('tenant_id', $tenantId)
            ->where('is_active', true)
            ->pluck('id')
            ->all();
    }

    /**
     * Returns the count of active appointments using a given resource on a
     * given date (sum of slot_weight — services with weight > 1 cost more
     * against the resource's daily quota). Used by drop-off auto-fallback
     * to find a resource with remaining capacity.
     */
    public function resourceUsedSlotsForDate(string $tenantId, string $resourceId, string $date): int
    {
        // MARKER-PATCH-383 — count committed appointments plus active holds.
        $appt = (int) TenantAppointment::where('tenant_id', $tenantId)
            ->where('resource_id', $resourceId)
            ->where('appointment_date', $date)
            ->whereNotIn('status', ['cancelled', 'refunded'])
            ->sum('slot_weight');

        $held = (int) \App\Models\Tenant\TenantPendingBooking::where('tenant_id', $tenantId)
            ->where('resource_id', $resourceId)
            ->where('booking_date', $date)
            ->where('status', 'pending')
            ->where('expires_at', '>', now())
            ->sum('slot_weight');

        return $appt + $held;
    }

    /**
     * Returns an array of available start times for a given date.
     *
     * Scope:
     *  - If $resourceId is provided, returns slots where that specific resource is free.
     *  - If $resourceId is null, returns slots where ANY active resource is free.
     *    (single-resource shops and legacy callers get backward-compatible behavior)
     *  - $requiredMinutes ensures a slot can actually hold the full service duration,
     *    not just that the start time happens to be unoccupied.
     *
     * At 10K+ tenants this gets called hundreds of times per second on peak days.
     * Every query is tenant-scoped + date-scoped + (optionally) resource-scoped to
     * hit the composite index added in migration M2.
     */
    public function availableSlotsForDate(
        Tenant $tenant,
        string $date,
        ?string $resourceId = null,
        int $requiredMinutes = 0,
        $rule = null
    ): array {
        if (!$rule) {
            $dow = Carbon::parse($date)->dayOfWeek;
            $rule = TenantCapacityRule::where('tenant_id', $tenant->id)
                ->where('rule_type', 'default')->where('day_of_week', $dow)->first();
        }
        if (!$rule || !$rule->open_time || !$rule->close_time) return [];

        $interval = (int) ($rule->slot_interval_minutes ?? 60);
        // A slot can only "hold" a service whose total time fits before close.
        // If caller didn't supply a minimum, fall back to one slot width.
        $effectiveRequired = $requiredMinutes > 0 ? $requiredMinutes : $interval;

        $open  = Carbon::parse($date . ' ' . $rule->open_time);
        $close = Carbon::parse($date . ' ' . $rule->close_time);

        // Pull all appointments touching this date, optionally scoped to one resource.
        // Index hit: (tenant_id, resource_id, appointment_date) when $resourceId is set,
        //            (tenant_id, appointment_date) when it's not.
        $bookedQuery = TenantAppointment::where('tenant_id', $tenant->id)
            ->where('appointment_date', $date)
            ->whereNotIn('status', ['cancelled', 'refunded'])
            ->whereNotNull('appointment_time');

        if ($resourceId !== null) {
            $bookedQuery->where('resource_id', $resourceId);
        }

        $booked = $bookedQuery->get([
            'resource_id', 'appointment_time', 'appointment_end_time',
            'total_duration_minutes', 'prep_before_minutes_snapshot',
            'cleanup_after_minutes_snapshot',
        ]);

        // MARKER-PATCH-383 — active pending holds occupy their slot during the
        // card window. Shape them like booked rows (end derived from duration,
        // no prep/cleanup tail) and fold them into the overlap set.
        $holdQuery = \App\Models\Tenant\TenantPendingBooking::where('tenant_id', $tenant->id)
            ->where('booking_date', $date)
            ->where('status', 'pending')
            ->where('expires_at', '>', now())
            ->whereNotNull('appointment_time');
        if ($resourceId !== null) {
            $holdQuery->where('resource_id', $resourceId);
        }
        $holds = $holdQuery->get(['resource_id', 'appointment_time', 'total_duration_minutes'])
            ->map(function ($h) {
                return (object) [
                    'resource_id'                    => $h->resource_id,
                    'appointment_time'               => $h->appointment_time,
                    'appointment_end_time'           => null,
                    'total_duration_minutes'         => (int) $h->total_duration_minutes,
                    'prep_before_minutes_snapshot'   => 0,
                    'cleanup_after_minutes_snapshot' => 0,
                ];
            });
        $booked = $booked->concat($holds);

        // Gather breaks and walk-in holds that apply on this date.
        // Both return arrays of ['starts_at' => Carbon, 'ends_at' => Carbon, 'resource_id' => ?string].
        $breakWindows = $this->breaksForDate($tenant->id, $date, $resourceId);
        $holdWindows  = $this->holdsForDate($tenant->id, $date, $resourceId);

        // When caller did not specify a resource, the caller wants "any resource works".
        // Count active resources so we know how many concurrent bookings are tolerable
        // per slot before that slot is fully occupied.
        $activeResourceCount = 1;
        if ($resourceId === null) {
            $activeResourceCount = \App\Models\Tenant\TenantResource::query()
                ->where('tenant_id', $tenant->id)
                ->where('is_active', true)
                ->count();
            // Defensive: at least 1, so a freshly-installed tenant without resources
            // still gets computable slots instead of an empty list.
            $activeResourceCount = max($activeResourceCount, 1);
        }

        $slots = [];
        $cursor = $open->copy();
        while ($cursor->lt($close)) {
            $slotStart = $cursor->copy();
            // Check whether the FULL required duration fits before close.
            $slotEnd = $slotStart->copy()->addMinutes($effectiveRequired);
            if ($slotEnd->gt($close)) break;

            $overlapping = $booked->filter(function ($appt) use ($date, $slotStart, $slotEnd, $interval) {
                $apptStart = Carbon::parse($date . ' ' . $appt->appointment_time);

                // Bookend-aware end: an appointment "occupies" its core duration
                // PLUS its cleanup tail. The prep tail is before appt_time so it
                // doesn't extend the end, but prep would affect the start of the
                // next appointment — which is handled by its own $apptStart shift below.
                $apptEnd = $appt->appointment_end_time
                    ? Carbon::parse($date . ' ' . $appt->appointment_end_time)
                    : $apptStart->copy()->addMinutes($appt->total_duration_minutes ?: $interval);
                $apptEnd = $apptEnd->copy()->addMinutes((int) ($appt->cleanup_after_minutes_snapshot ?? 0));

                // Shift apptStart back by any prep bookend — the resource is effectively
                // unavailable during prep, so the "occupied window" is wider than
                // [appt_time, appt_end].
                $apptStart = $apptStart->copy()->subMinutes((int) ($appt->prep_before_minutes_snapshot ?? 0));

                return $slotStart->lt($apptEnd) && $slotEnd->gt($apptStart);
            });

            // When resource-scoped: any overlap = slot blocked.
            // When any-resource: slot is blocked only when ALL resources are busy.
            if ($resourceId !== null) {
                $blocked = $overlapping->isNotEmpty();
            } else {
                $busyResourceIds = $overlapping->pluck('resource_id')->filter()->unique();
                $blocked = $busyResourceIds->count() >= $activeResourceCount;
            }

            // Breaks: if ANY break window for this resource (or shop-wide) overlaps
            // the slot, the slot is blocked regardless of appointment count.
            // A shop-wide break (resource_id = null) blocks every resource.
            if (!$blocked) {
                foreach ($breakWindows as $bw) {
                    if ($resourceId !== null
                        && $bw['resource_id'] !== null
                        && $bw['resource_id'] !== $resourceId) {
                        continue;  // this break is for a different specific resource
                    }
                    if ($slotStart->lt($bw['ends_at']) && $slotEnd->gt($bw['starts_at'])) {
                        $blocked = true;
                        break;
                    }
                }
            }

            // Walk-in holds: reserve capacity for walk-in customers. Online
            // bookings cannot claim a hold window until the hold is released
            // (auto_release_at in the past) or converted.
            if (!$blocked) {
                foreach ($holdWindows as $hw) {
                    if ($resourceId !== null && $hw['resource_id'] !== $resourceId) {
                        continue;
                    }
                    if ($slotStart->lt($hw['ends_at']) && $slotEnd->gt($hw['starts_at'])) {
                        $blocked = true;
                        break;
                    }
                }
            }

            if (!$blocked) $slots[] = $slotStart->format('H:i');
            $cursor->addMinutes($interval);
        }

        return $slots;
    }

    /**
     * Walk forward day-by-day to find the earliest available slot for a service
     * of the given duration. Optionally scoped to a specific resource. Cached
     * in Redis (60s TTL) keyed by tenant + duration + resource.
     *
     * Returns: ['date' => 'Y-m-d', 'time' => 'H:i', 'resource_id' => ?string]
     *          or null if nothing fits within $maxDaysAhead.
     */
    public function nextAvailableSlot(
        Tenant $tenant,
        int $requiredMinutes,
        ?string $resourceId = null,
        ?int $maxDaysAhead = null
    ): ?array {
        $maxDaysAhead = $maxDaysAhead ?? ($tenant->booking_window_days ?? 60);

        $cacheKey = sprintf(
            'avail:next:%s:%d:%s',
            $tenant->id,
            $requiredMinutes,
            $resourceId ?? 'any'
        );

        $cached = \Illuminate\Support\Facades\Cache::get($cacheKey);
        if ($cached !== null) {
            return $cached === 'NULL_SENTINEL' ? null : $cached;
        }

        $minNoticeHours = (int) ($tenant->min_notice_hours ?? 0);
        $earliest = now()->addHours($minNoticeHours);

        $cursor = $earliest->copy()->startOfDay();
        $stopAt = $earliest->copy()->addDays($maxDaysAhead);

        while ($cursor->lte($stopAt)) {
            $date = $cursor->toDateString();
            $slots = $this->availableSlotsForDate(
                $tenant,
                $date,
                $resourceId,
                $requiredMinutes
            );

            if ($cursor->isSameDay($earliest)) {
                $earliestTime = $earliest->format('H:i');
                $slots = array_values(array_filter($slots, fn($t) => $t >= $earliestTime));
            }

            if (!empty($slots)) {
                $result = [
                    'date'        => $date,
                    'time'        => $slots[0],
                    'resource_id' => $resourceId,
                ];
                \Illuminate\Support\Facades\Cache::put($cacheKey, $result, 60);
                return $result;
            }
            $cursor->addDay();
        }

        \Illuminate\Support\Facades\Cache::put($cacheKey, 'NULL_SENTINEL', 60);
        return null;
    }

    /**
     * Like nextAvailableSlot, but returns one entry per active resource.
     * Sorted by earliest-available first.
     */
    public function nextAvailablePerResource(
        Tenant $tenant,
        int $requiredMinutes,
        ?int $maxDaysAhead = null
    ): array {
        $resources = \App\Models\Tenant\TenantResource::where('tenant_id', $tenant->id)
            ->where('is_active', true)
            ->orderBy('sort_order')->orderBy('name')
            ->get(['id', 'name']);

        $out = [];
        foreach ($resources as $r) {
            $slot = $this->nextAvailableSlot($tenant, $requiredMinutes, $r->id, $maxDaysAhead);
            if ($slot) {
                $out[] = [
                    'resource_id' => $r->id,
                    'name'        => $r->name,
                    'date'        => $slot['date'],
                    'time'        => $slot['time'],
                ];
            }
        }

        usort($out, function ($a, $b) {
            if ($a['date'] !== $b['date']) return strcmp($a['date'], $b['date']);
            return strcmp($a['time'], $b['time']);
        });

        return $out;
    }

    /**
     * For a window of N days starting from $startDate, return the count of
     * available slots per day plus a status (open/closed/past/full/beyond_window).
     */
    public function dayCounts(
        Tenant $tenant,
        int $requiredMinutes,
        string $startDate,
        int $days = 7
    ): array {
        $cacheKey = sprintf(
            'avail:counts:%s:%d:%s:%d',
            $tenant->id,
            $requiredMinutes,
            $startDate,
            $days
        );

        $cached = \Illuminate\Support\Facades\Cache::get($cacheKey);
        if ($cached !== null) {
            return $cached;
        }

        $minNoticeHours = (int) ($tenant->min_notice_hours ?? 0);
        $minNoticeAt = now()->addHours($minNoticeHours);
        $windowDays = (int) ($tenant->booking_window_days ?? 60);
        $windowEnd = now()->addDays($windowDays);

        $cursor = \Carbon\Carbon::parse($startDate)->startOfDay();
        $out = [];

        for ($i = 0; $i < $days; $i++) {
            $date = $cursor->toDateString();
            $entry = ['date' => $date, 'count' => 0, 'status' => 'open'];

            if ($cursor->isPast() && !$cursor->isToday()) {
                $entry['status'] = 'past';
            }
            elseif ($cursor->gt($windowEnd)) {
                $entry['status'] = 'beyond_window';
            }
            else {
                $slots = $this->availableSlotsForDate($tenant, $date, null, $requiredMinutes);

                if ($cursor->isToday()) {
                    $earliestTime = $minNoticeAt->format('H:i');
                    $slots = array_values(array_filter($slots, fn($t) => $t >= $earliestTime));
                }

                $count = count($slots);
                $entry['count'] = $count;

                if ($count === 0) {
                    $entry['status'] = $cursor->isToday() ? 'full' : 'closed';
                }
            }

            $out[] = $entry;
            $cursor->addDay();
        }

        \Illuminate\Support\Facades\Cache::put($cacheKey, $out, 60);
        return $out;
    }

    /**
     * Find the first active resource that's free for the given window.
     * Used by the day-strip auto-assign flow.
     */
    public function resolveResourceForSlot(
        Tenant $tenant,
        string $date,
        string $time,
        int $requiredMinutes
    ): ?string {
        $resources = \App\Models\Tenant\TenantResource::where('tenant_id', $tenant->id)
            ->where('is_active', true)
            ->orderBy('sort_order')->orderBy('name')
            ->get(['id']);

        foreach ($resources as $r) {
            $slots = $this->availableSlotsForDate($tenant, $date, $r->id, $requiredMinutes);
            if (in_array($time, $slots, true)) {
                return $r->id;
            }
        }

        return null;
    }

    /**
     * Expand break records into concrete time windows for a given date.
     * Handles one-offs and recurring (daily/weekly/monthly) records.
     *
     * Returns: array of ['starts_at' => Carbon, 'ends_at' => Carbon, 'resource_id' => ?string]
     *
     * At scale: queries are indexed by (tenant_id, resource_id, starts_at).
     * The recurrence expansion is O(breaks) per call — fine for the <50 breaks
     * any single tenant will have. If a tenant ever has 500+ breaks we revisit.
     */
    /**
     * Focused availability check: is $resourceId free for the given window
     * on the given date? Used by AppointmentController::change_resource to
     * detect conflicts when reassigning an appointment to a different
     * resource without loading the full slot list.
     *
     * Window is [startTime, startTime + durationMinutes), expressed as
     * an H:i:s string + integer minutes. Excludes $excludeAppointmentId
     * so the appointment doesn't conflict with itself.
     *
     * Returns null if the slot is free, or the conflicting appointment
     * (lightweight payload) if not. Caller decides how to surface that.
     *
     * Conflicts checked against:
     *   - Other active appointments on this resource overlapping the window
     *   - Breaks scoped to this resource (or shop-wide breaks)
     *   - Walk-in holds on this resource
     *
     * NOT checked: business hours, slot interval alignment. Caller already
     * has those validated by virtue of the appointment existing — moving
     * resources doesn't change the time, so hours/intervals don't need
     * re-validation.
     */
    public function resourceIsFreeDuring(
        string $tenantId,
        string $resourceId,
        string $date,
        string $startTime,
        int $durationMinutes,
        ?string $excludeAppointmentId = null
    ): ?array {
        $windowStart = Carbon::parse($date . ' ' . $startTime);
        $windowEnd   = $windowStart->copy()->addMinutes(max(1, $durationMinutes));

        // Active appointments on this resource for this date
        $query = TenantAppointment::where('tenant_id', $tenantId)
            ->where('resource_id', $resourceId)
            ->where('appointment_date', $date)
            ->whereNotIn('status', ['cancelled', 'refunded'])
            ->whereNotNull('appointment_time');

        if ($excludeAppointmentId) {
            $query->where('id', '!=', $excludeAppointmentId);
        }

        $candidates = $query->get([
            'id', 'ra_number', 'customer_first_name', 'customer_last_name',
            'appointment_time', 'appointment_end_time', 'total_duration_minutes',
            'cleanup_after_minutes_snapshot',
        ]);

        foreach ($candidates as $appt) {
            $apptStart = Carbon::parse($date . ' ' . $appt->appointment_time);

            // End = end_time if set; otherwise compute from start + duration + cleanup tail.
            // Mirrors the bookend-aware overlap logic in availableSlotsForDate.
            if ($appt->appointment_end_time) {
                $apptEnd = Carbon::parse($date . ' ' . $appt->appointment_end_time);
            } else {
                $totalMin = (int) $appt->total_duration_minutes
                          + (int) ($appt->cleanup_after_minutes_snapshot ?? 0);
                $apptEnd = $apptStart->copy()->addMinutes(max(1, $totalMin));
            }

            // Overlap = (windowStart < apptEnd) AND (windowEnd > apptStart)
            if ($windowStart->lt($apptEnd) && $windowEnd->gt($apptStart)) {
                return [
                    'kind'              => 'appointment',
                    'id'                => $appt->id,
                    'ra_number'         => $appt->ra_number,
                    'customer_name'     => trim(($appt->customer_first_name ?? '') . ' ' . ($appt->customer_last_name ?? '')),
                    'starts_at'         => $apptStart->format('g:i a'),
                    'ends_at'           => $apptEnd->format('g:i a'),
                ];
            }
        }

        // Breaks — pull just the ones for this resource (or shop-wide) on this date
        $breaks = $this->breaksForDate($tenantId, $date, $resourceId);
        foreach ($breaks as $br) {
            $brStart = Carbon::parse($br['starts_at']);
            $brEnd   = Carbon::parse($br['ends_at']);
            if ($windowStart->lt($brEnd) && $windowEnd->gt($brStart)) {
                return [
                    'kind'      => 'break',
                    'label'     => $br['label'] ?? 'Break',
                    'starts_at' => $brStart->format('g:i a'),
                    'ends_at'   => $brEnd->format('g:i a'),
                ];
            }
        }

        // Walk-in holds — same shape as breaks
        $holds = $this->holdsForDate($tenantId, $date, $resourceId);
        foreach ($holds as $h) {
            $hStart = Carbon::parse($h['starts_at']);
            $hEnd   = Carbon::parse($h['ends_at']);
            if ($windowStart->lt($hEnd) && $windowEnd->gt($hStart)) {
                return [
                    'kind'      => 'hold',
                    'label'     => $h['label'] ?? 'Walk-in hold',
                    'starts_at' => $hStart->format('g:i a'),
                    'ends_at'   => $hEnd->format('g:i a'),
                ];
            }
        }

        return null;
    }

    protected function breaksForDate(string $tenantId, string $date, ?string $resourceId): array
    {
        $target = Carbon::parse($date);

        // Fetch all potentially-applicable breaks: one-offs on this date,
        // plus any recurring break still active (recurrence_until >= date or null).
        // We filter by recurrence matching in PHP — doing it in SQL would require
        // JSON operators that vary by MySQL version and kill portability.
        $query = TenantCalendarBreak::where('tenant_id', $tenantId)
            ->where(function ($q) use ($target) {
                $q->where(function ($q2) use ($target) {
                    // One-off on this specific date
                    $q2->where('is_recurring', false)
                       ->whereDate('starts_at', $target->toDateString());
                })->orWhere(function ($q2) use ($target) {
                    // Recurring, still within its active window
                    $q2->where('is_recurring', true)
                       ->where('starts_at', '<=', $target->copy()->endOfDay())
                       ->where(function ($q3) use ($target) {
                           $q3->whereNull('recurrence_until')
                              ->orWhere('recurrence_until', '>=', $target->toDateString());
                       });
                });
            });

        // Narrow to resource-specific + shop-wide breaks.
        // Shop-wide (resource_id IS NULL) always apply.
        if ($resourceId !== null) {
            $query->where(function ($q) use ($resourceId) {
                $q->whereNull('resource_id')->orWhere('resource_id', $resourceId);
            });
        }

        $records = $query->get([
            'resource_id', 'starts_at', 'ends_at',
            'is_recurring', 'recurrence_type', 'recurrence_config',
        ]);

        $windows = [];
        foreach ($records as $br) {
            if (!$br->is_recurring) {
                // One-off: use the stored datetimes directly.
                $windows[] = [
                    'starts_at'   => $br->starts_at,
                    'ends_at'     => $br->ends_at,
                    'resource_id' => $br->resource_id,
                ];
                continue;
            }

            if (!$this->recurrenceAppliesOnDate($br->recurrence_type, $br->recurrence_config, $target)) {
                continue;
            }

            // Shift the stored time-of-day onto the target date.
            $origStart = Carbon::parse($br->starts_at);
            $origEnd   = Carbon::parse($br->ends_at);
            $windows[] = [
                'starts_at'   => $target->copy()->setTimeFromTimeString($origStart->format('H:i:s')),
                'ends_at'     => $target->copy()->setTimeFromTimeString($origEnd->format('H:i:s')),
                'resource_id' => $br->resource_id,
            ];
        }

        return $windows;
    }

    /**
     * Walk-in holds for a date, excluding converted ones and released ones.
     * Same recurrence logic as breaks; resource_id is never null for holds
     * (holds are always tied to a specific resource).
     */
    protected function holdsForDate(string $tenantId, string $date, ?string $resourceId): array
    {
        $target = Carbon::parse($date);
        $now    = now();

        $query = TenantWalkinHold::where('tenant_id', $tenantId)
            ->whereNull('converted_at')  // converted holds don't block — they became appointments
            ->where(function ($q) use ($now) {
                // Not auto-released yet (or no auto-release set)
                $q->whereNull('auto_release_at')->orWhere('auto_release_at', '>', $now);
            })
            ->where(function ($q) use ($target) {
                $q->where(function ($q2) use ($target) {
                    $q2->where('is_recurring', false)
                       ->whereDate('starts_at', $target->toDateString());
                })->orWhere(function ($q2) use ($target) {
                    $q2->where('is_recurring', true)
                       ->where('starts_at', '<=', $target->copy()->endOfDay())
                       ->where(function ($q3) use ($target) {
                           $q3->whereNull('recurrence_until')
                              ->orWhere('recurrence_until', '>=', $target->toDateString());
                       });
                });
            });

        if ($resourceId !== null) {
            $query->where('resource_id', $resourceId);
        }

        $records = $query->get([
            'resource_id', 'starts_at', 'ends_at',
            'is_recurring', 'recurrence_type', 'recurrence_config',
        ]);

        $windows = [];
        foreach ($records as $hw) {
            if (!$hw->is_recurring) {
                $windows[] = [
                    'starts_at'   => $hw->starts_at,
                    'ends_at'     => $hw->ends_at,
                    'resource_id' => $hw->resource_id,
                ];
                continue;
            }

            if (!$this->recurrenceAppliesOnDate($hw->recurrence_type, $hw->recurrence_config, $target)) {
                continue;
            }

            $origStart = Carbon::parse($hw->starts_at);
            $origEnd   = Carbon::parse($hw->ends_at);
            $windows[] = [
                'starts_at'   => $target->copy()->setTimeFromTimeString($origStart->format('H:i:s')),
                'ends_at'     => $target->copy()->setTimeFromTimeString($origEnd->format('H:i:s')),
                'resource_id' => $hw->resource_id,
            ];
        }

        return $windows;
    }

    /**
     * Does a recurrence record apply on the given target date?
     * Supports: daily, weekly (days of week), monthly (day of month).
     *
     * recurrence_config shapes:
     *   daily:   null  (or {}; we treat as "every day")
     *   weekly:  {"days": ["mon", "tue", "thu"]}
     *   monthly: {"day_of_month": 15}
     */
    protected function recurrenceAppliesOnDate(?string $type, $config, Carbon $target): bool
    {
        if ($type === 'daily') {
            return true;
        }

        if ($type === 'weekly') {
            $days = is_array($config) ? ($config['days'] ?? []) : [];
            if (!is_array($days) || empty($days)) return false;
            $targetDow = strtolower($target->format('D'));  // 'mon','tue','wed',...
            $targetDow = substr($targetDow, 0, 3);
            $normalized = array_map(fn($d) => strtolower(substr((string) $d, 0, 3)), $days);
            return in_array($targetDow, $normalized, true);
        }

        if ($type === 'monthly') {
            $dayOfMonth = is_array($config) ? (int) ($config['day_of_month'] ?? 0) : 0;
            if ($dayOfMonth < 1 || $dayOfMonth > 31) return false;
            return (int) $target->format('j') === $dayOfMonth;
        }

        return false;
    }

    protected function buildBookingPlan(array $items, string $tenantId): array
    {
        $plan = [];
        foreach ($items as $idx => $sel) {
            if (empty($sel['service_item_id'])) {
                throw new RuntimeException("Item #{$idx} missing service_item_id.");
            }
            $service = TenantServiceItem::where('id', $sel['service_item_id'])
                ->where('tenant_id', $tenantId)->where('is_active', true)
                ->with(['serviceAddons.addon'])->first();
            if (!$service) {
                throw new RuntimeException("Service not found or inactive: {$sel['service_item_id']}");
            }

            $addonIds = isset($sel['addon_ids']) && is_array($sel['addon_ids'])
                ? array_values(array_unique($sel['addon_ids'])) : [];
            $addonRows = [];

            if ($addonIds) {
                $pivotsByAddonId = $service->serviceAddons->keyBy('addon_id');
                foreach ($addonIds as $addonId) {
                    $pivot = $pivotsByAddonId->get($addonId);
                    if (!$pivot || !$pivot->addon) {
                        throw new RuntimeException("Add-on {$addonId} is not available for service {$service->name}.");
                    }
                    if (!$pivot->addon->is_active) {
                        throw new RuntimeException("Add-on {$pivot->addon->name} is not active.");
                    }
                    $addonRows[] = [
                        'addon'                 => $pivot->addon,
                        'pivot'                 => $pivot,
                        'effective_price_cents' => (int) $pivot->effectivePriceCents(),
                        'effective_duration'    => (int) $pivot->effectiveDuration(),
                    ];
                }
            }
            // Optional per-item price override (admin/staff-create flow).
            // Null means use service catalog price. Negative or > 9999999 rejected.
            $override = $sel['price_override_cents'] ?? null;
            if ($override !== null) {
                $override = (int) $override;
                if ($override < 0 || $override > 9999999) {
                    throw new RuntimeException("Item #{$idx} price override out of range.");
                }
            }
            $effectivePrice = $override ?? (int) $service->price_cents;

            $plan[] = [
                // MARKER-PATCH-216 — which bike/asset this item belongs to.
                'asset_client_key'       => isset($sel['asset_client_key']) && $sel['asset_client_key'] !== ''
                                                ? (string) $sel['asset_client_key'] : null,
                'service'                => $service,
                'addons'                 => $addonRows,
                'price_override_cents'   => $override,
                'effective_price_cents'  => $effectivePrice,
            ];
        }
        return $plan;
    }

    /**
     * MARKER-PATCH-216 — persist the multi-asset payload for a public booking.
     *
     * For each entry in assets[]:
     *   - customer_asset_id present -> verify ownership (this tenant + this
     *     customer + not archived). Failed verification is treated as a NEW
     *     asset rather than an error (mirrors the WP REST handler).
     *   - otherwise -> create a persistent TenantCustomerAsset.
     *
     * Creates one TenantAppointmentAsset per entry (name/identifier snapshot,
     * sort_order 10/20/30...) and returns clientKey -> model so the caller can
     * tag items/addons. Entries without a client_key are skipped — nothing in
     * items[] could reference them. Returns [] in single-asset mode.
     */
    protected function persistAppointmentAssets(
        TenantAppointment $appointment,
        TenantCustomer $customer,
        array $data,
        string $tenantId
    ): array {
        $assets = $data['assets'] ?? null;
        if (!is_array($assets) || empty($assets)) {
            return [];
        }

        $map  = [];
        $sort = 10;

        foreach ($assets as $entry) {
            if (!is_array($entry)) {
                continue;
            }

            $clientKey = isset($entry['client_key']) ? trim((string) $entry['client_key']) : '';
            if ($clientKey === '' || isset($map[$clientKey])) {
                continue;
            }

            $name = trim((string) ($entry['name_snapshot'] ?? ''));

            $customerAsset = null;
            $claimed = $entry['customer_asset_id'] ?? null;
            if (!empty($claimed)) {
                $customerAsset = \App\Models\Tenant\TenantCustomerAsset::where('tenant_id', $tenantId)
                    ->where('customer_id', $customer->id)
                    ->where('id', $claimed)
                    ->whereNull('archived_at')
                    ->first();
            }

            if ($customerAsset) {
                // Existing asset: the account name is canon (the booking UI
                // renders it read-only). Keep last-seen fresh.
                $customerAsset->update([
                    'last_seen_at'        => now(),
                    'last_appointment_id' => $appointment->id,
                ]);
                $snapshotName = $customerAsset->name;
            } else {
                if ($name === '') {
                    $name = 'Item ' . (count($map) + 1);
                }
                $customerAsset = \App\Models\Tenant\TenantCustomerAsset::create([
                    'tenant_id'           => $tenantId,
                    'customer_id'         => $customer->id,
                    'name'                => $name,
                    'last_seen_at'        => now(),
                    'last_appointment_id' => $appointment->id,
                ]);
                $snapshotName = $name;
            }

            $map[$clientKey] = \App\Models\Tenant\TenantAppointmentAsset::create([
                'tenant_id'           => $tenantId,
                'appointment_id'      => $appointment->id,
                'customer_asset_id'   => $customerAsset->id,
                'asset_name_snapshot' => $snapshotName,
                'identifier_snapshot' => $customerAsset->identifier,
                'sort_order'          => $sort,
                'subtotal_cents'      => 0,
            ]);
            $sort += 10;
        }

        return $map;
    }

    protected function upsertCustomer(array $data, string $tenantId): TenantCustomer
    {
        // MARKER-PATCH-392 — standardize the phone once so every write below stores E.164.
        $data['phone'] = \App\Support\PhoneNumber::normalize($data['phone'] ?? null);

        $email = strtolower(trim($data['email'] ?? ''));
        if ($email === '') throw new RuntimeException('Customer email is required.');

        // MARKER-PATCH-216 — returning-customer path. The pre-flow lookup
        // hands the client a customer_id; verify it belongs to this tenant
        // before trusting it. Failed verification falls through to the email
        // canon below — the claimed id alone proves nothing.
        $claimedId = $data['customer_id'] ?? null;
        if (!empty($claimedId)) {
            $verified = TenantCustomer::where('tenant_id', $tenantId)
                ->where('id', $claimedId)
                ->first();
            if ($verified) {
                // Details fields are locked client-side for returning
                // customers, so name/email arrive unchanged. Phone stays
                // editable when the account has none — capture it.
                if (empty($verified->phone) && !empty($data['phone'])) {
                    $verified->phone = $data['phone'];
                    $verified->save();
                }
                return $verified;
            }
        }

        $customer = TenantCustomer::where('tenant_id', $tenantId)->where('email', $email)->first();
        if ($customer) {
            $customer->fill([
                'first_name' => $data['first_name'] ?? $customer->first_name,
                'last_name'  => $data['last_name']  ?? $customer->last_name,
                'phone'      => $data['phone']      ?? $customer->phone,
            ])->save();
            return $customer;
        }
        return TenantCustomer::create([
            'id'         => (string) Str::uuid(),
            'tenant_id'  => $tenantId,
            'first_name' => $data['first_name'] ?? '',
            'last_name'  => $data['last_name']  ?? '',
            'email'      => $email,
            'phone'      => $data['phone']      ?? null,
        ]);
    }

    protected function persistResponses(TenantAppointment $appointment, array $data): void
    {
        $responses = $data['responses'] ?? null;
        if (!is_array($responses) || empty($responses)) return;
        $labels = $data['response_labels'] ?? [];

        foreach ($responses as $questionKey => $value) {
            if ($value === null || $value === '' || $value === []) continue;
            \App\Models\Tenant\TenantAppointmentResponse::create([
                'id'                   => (string) Str::uuid(),
                'appointment_id'       => $appointment->id,
                'field_key_snapshot'   => (string) $questionKey,
                'field_label_snapshot' => $labels[$questionKey] ?? (string) $questionKey,
                'response_value'       => is_scalar($value) ? (string) $value : json_encode($value),
            ]);
        }
    }
}
OUTREACH_4_EOF

cat > 'app/Services/Tenant/DashboardDataService.php' <<'OUTREACH_5_EOF'
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
        $todaySalesTotal = (int) DB::table('tenant_sales')
            ->where('tenant_id', $tenantId)
            ->whereDate('paid_at', $todayStr)
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
        $offS = Carbon::now($tzS)->utcOffset() * 60;
        $rows = \App\Models\Tenant\TenantSalePayment::where('tenant_id', $tenantId)
            ->whereBetween('recorded_at', [
                $from->copy()->setTimezone($tzS)->startOfDay()->utc(),
                $to->copy()->setTimezone($tzS)->endOfDay()->utc(),
            ])
            ->selectRaw('DATE(DATE_ADD(recorded_at, INTERVAL ? SECOND)) as d, SUM(amount_cents) as cents', [$offS])
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
OUTREACH_5_EOF

cat > 'app/Http/Controllers/Tenant/AppointmentController.php' <<'OUTREACH_6_EOF'
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
            'filter', 'filterLabels', 'resources', 'resourceFilter'
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
            $part->delete();

            TenantAppointmentNote::create([
                'appointment_id'      => $appointment->id,
                'user_id'             => Auth::guard('tenant')->id(),
                'note_type'           => 'system',
                'is_customer_visible' => false,
                'note_content'        => sprintf('%s removed', $name),
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
OUTREACH_6_EOF

echo "pickup-outreach-alerts applied — server needs: migrate --force + view:clear"
