#!/usr/bin/env python3
"""Last-minute extension offers — Phase 1. Makes the sold-but-vapor
`rental_extensions` add-on real: offers table, eligibility + pricing
service, 15-min scan on the scheduler, SMS magic link (waitlist
pattern), public one-tap checkout via Direct Payments (reserve-flow
rails), rental-detail eligibility panel with manual Send offer now, and
the settings card (discount, min gap, send timing, until-time, quiet
hours). Extension payment lands on the sale/payment ledger; due_at
extends with original_due_at preserved.
Run from repo root: python3 apply-rental-extension-offers.py
"""
import os, sys

ROOT = os.getcwd()
def read(p):
    with open(os.path.join(ROOT, p)) as f: return f.read()
def write(p, s):
    os.makedirs(os.path.dirname(os.path.join(ROOT, p)), exist_ok=True)
    with open(os.path.join(ROOT, p), 'w') as f: f.write(s)
def sub(p, old, new, label):
    s = read(p)
    if new in s:
        print(f"SKIP (already applied): {label}"); return
    if old not in s:
        print(f"FAIL: anchor not found for {label} in {p}"); sys.exit(1)
    write(p, s.replace(old, new, 1))
    print(f"OK: {label}")
def newfile(p, content, label):
    if os.path.exists(os.path.join(ROOT, p)):
        print(f"SKIP (exists): {label}"); return
    write(p, content)
    print(f"OK: {label}")

# ============================================================
# 1) Migration
# ============================================================
newfile('database/migrations/2026_08_19_100000_create_tenant_rental_extension_offers.php', """<?php

use Illuminate\\Database\\Migrations\\Migration;
use Illuminate\\Database\\Schema\\Blueprint;
use Illuminate\\Support\\Facades\\Schema;

// MARKER-RENTAL-EXT — last-minute extension offers. One row per offer
// episode; the magic-link token is the customer's whole identity here.
return new class extends Migration {
    public function up(): void
    {
        Schema::create('tenant_rental_extension_offers', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id')->index();
            $table->uuid('rental_id');
            $table->string('token', 48)->unique();
            $table->string('status', 20)->default('sent'); // sent|paid|declined|expired|cancelled
            $table->string('channel', 20)->default('auto'); // auto|manual
            $table->dateTime('offer_from');
            $table->dateTime('extend_to');
            $table->unsignedInteger('discount_pct')->default(0);
            $table->integer('subtotal_cents')->default(0);
            $table->integer('tax_cents')->default(0);
            $table->integer('total_cents')->default(0);
            $table->dateTime('sent_at')->nullable();
            $table->dateTime('responded_at')->nullable();
            $table->dateTime('expires_at')->nullable();
            $table->string('stripe_payment_intent_id')->nullable();
            $table->uuid('sale_id')->nullable();
            $table->json('meta')->nullable();
            $table->timestamps();
            $table->index(['tenant_id', 'rental_id']);
        });
    }
    public function down(): void
    {
        Schema::dropIfExists('tenant_rental_extension_offers');
    }
};
""", "migration")

# ============================================================
# 2) Model
# ============================================================
newfile('app/Models/Tenant/TenantRentalExtensionOffer.php', """<?php

namespace App\\Models\\Tenant;

use Illuminate\\Database\\Eloquent\\Concerns\\HasUuids;
use Illuminate\\Database\\Eloquent\\Model;
use Illuminate\\Database\\Eloquent\\Relations\\BelongsTo;

// MARKER-RENTAL-EXT
class TenantRentalExtensionOffer extends Model
{
    use HasUuids;

    protected $table = 'tenant_rental_extension_offers';

    protected $fillable = [
        'tenant_id', 'rental_id', 'token', 'status', 'channel',
        'offer_from', 'extend_to', 'discount_pct',
        'subtotal_cents', 'tax_cents', 'total_cents',
        'sent_at', 'responded_at', 'expires_at',
        'stripe_payment_intent_id', 'sale_id', 'meta',
    ];

    protected $casts = [
        'offer_from'   => 'datetime',
        'extend_to'    => 'datetime',
        'sent_at'      => 'datetime',
        'responded_at' => 'datetime',
        'expires_at'   => 'datetime',
        'meta'         => 'array',
    ];

    public function rental(): BelongsTo
    {
        return $this->belongsTo(TenantRental::class, 'rental_id');
    }

    public function isOpen(): bool
    {
        return $this->status === 'sent'
            && (!$this->expires_at || $this->expires_at->isFuture());
    }
}
""", "model")

# ============================================================
# 3) Service — eligibility, pricing, create+send
# ============================================================
newfile('app/Services/RentalExtensionOfferService.php', """<?php

namespace App\\Services;

use App\\Models\\Tenant;
use App\\Models\\Tenant\\TenantRental;
use App\\Models\\Tenant\\TenantRentalExtensionOffer;
use App\\Services\\Sms\\SmsService;
use Carbon\\Carbon;
use Illuminate\\Support\\Facades\\Log;
use Illuminate\\Support\\Str;

/**
 * MARKER-RENTAL-EXT — the whole brain for last-minute extension offers.
 * Eligibility and pricing live here so the scheduled scan, the manual
 * "Send offer now" button, and the rental-detail panel all agree.
 */
class RentalExtensionOfferService
{
    public function __construct(private RentalAvailabilityService $availability) {}

    public function settings(Tenant $tenant): array
    {
        $s = $tenant->settings ?? [];
        return [
            'enabled'      => (bool) ($s['rental_ext_enabled'] ?? false),
            'discount_pct' => max(0, min(90, (int) ($s['rental_ext_discount_pct'] ?? 50))),
            'min_gap'      => max(30, (int) ($s['rental_ext_min_gap_minutes'] ?? 120)),
            'send_before'  => max(15, (int) ($s['rental_ext_send_before_minutes'] ?? 90)),
            'until'        => (string) ($s['rental_ext_until'] ?? '20:00'),
            'quiet_start'  => (string) ($s['rental_ext_quiet_start'] ?? ''),
            'quiet_end'    => (string) ($s['rental_ext_quiet_end'] ?? ''),
        ];
    }

    public function isFeatureOn(Tenant $tenant): bool
    {
        return $tenant->rental_extensions_enabled && $this->settings($tenant)['enabled'];
    }

    /**
     * Can this rental be offered an extension right now?
     * Returns the priced offer shape, or null with $reason set.
     */
    public function eligibility(Tenant $tenant, TenantRental $rental, ?string &$reason = null): ?array
    {
        $cfg = $this->settings($tenant);

        if ($rental->status !== 'out' || $rental->returned_at) { $reason = 'Rental is not out.'; return null; }
        if (!$rental->due_at || $rental->due_at->isPast())     { $reason = 'Rental is already past due.'; return null; }

        $line = $rental->lines()->where('kind', 'unit')->with('unit')->first();
        $unit = $line?->unit;
        if (!$unit)                          { $reason = 'No unit on this rental.'; return null; }
        if ($unit->status !== 'available')   { $reason = 'Unit is flagged for ' . $unit->status . '.'; return null; }

        // Existing open/paid offer for this episode blocks a duplicate.
        $existing = TenantRentalExtensionOffer::where('rental_id', $rental->id)
            ->whereIn('status', ['sent', 'paid'])
            ->first();
        if ($existing) { $reason = 'An offer already exists for this rental.'; return null; }

        // Candidate window: due -> today's until-time (tenant clock).
        $tz  = $tenant->timezone();
        $due = $rental->due_at->copy();
        [$uh, $um] = array_pad(array_map('intval', explode(':', $cfg['until'] ?: '20:00')), 2, 0);
        $extendTo = $due->copy()->setTimezone($tz)->setTime($uh, $um)->setTimezone('UTC');
        if ($extendTo->lessThanOrEqualTo($due)) { $reason = 'Return is already at or past the daily cutoff.'; return null; }

        // Shrink to the next booking on this unit (minus buffer), if any.
        if ($this->availability->hasConflict($unit, $due, $extendTo, $rental->id)) {
            $next = TenantRental::query()
                ->whereIn('status', ['reserved', 'out'])
                ->where('id', '!=', $rental->id)
                ->where('starts_at', '>=', $due)
                ->whereHas('lines', fn ($q) => $q->where('unit_id', $unit->id))
                ->orderBy('starts_at')
                ->value('starts_at');
            if ($next) {
                $extendTo = Carbon::parse($next)->subMinutes((int) $unit->buffer_minutes);
            }
            if ($extendTo->lessThanOrEqualTo($due)
                || $this->availability->hasConflict($unit, $due, $extendTo, $rental->id)) {
                $reason = 'The unit is booked right after this return.'; return null;
            }
        }

        $gapMinutes = $due->diffInMinutes($extendTo);
        if ($gapMinutes < $cfg['min_gap']) { $reason = 'Gap after return is under the minimum (' . $cfg['min_gap'] . ' min).'; return null; }

        // Price: the rental's own snapshot rate, discounted.
        $hours = $gapMinutes / 60;
        $rate  = (int) ($line->rate_cents_snapshot ?? 0);
        $mode  = $line->rate_mode_snapshot ?? 'daily';
        $base  = $mode === 'hourly'
            ? (int) ceil($hours) * $rate
            : (int) round($rate * min(1, $hours / 8)); // daily prorated per shop-hour, capped at a day
        if ($base <= 0) { $reason = 'No rate on this rental to price an extension.'; return null; }

        $subtotal = (int) round($base * (100 - $cfg['discount_pct']) / 100);
        $taxRate  = (float) ($tenant->default_tax_rate ?? 0);
        $tax      = (int) round($subtotal * $taxRate / 100);

        return [
            'unit'          => $unit,
            'offer_from'    => $due,
            'extend_to'     => $extendTo,
            'gap_minutes'   => $gapMinutes,
            'discount_pct'  => $cfg['discount_pct'],
            'base_cents'    => $base,
            'subtotal_cents'=> $subtotal,
            'tax_cents'     => $tax,
            'total_cents'   => $subtotal + $tax,
        ];
    }

    /** Inside the configured quiet window right now? */
    public function inQuietHours(Tenant $tenant): bool
    {
        $cfg = $this->settings($tenant);
        if (!$cfg['quiet_start'] || !$cfg['quiet_end']) return false;
        $now = Carbon::now($tenant->timezone())->format('H:i');
        [$qs, $qe] = [$cfg['quiet_start'], $cfg['quiet_end']];
        return $qs <= $qe ? ($now >= $qs && $now < $qe) : ($now >= $qs || $now < $qe);
    }

    /** Create the offer row and send the SMS. Returns the offer. */
    public function createAndSend(Tenant $tenant, TenantRental $rental, array $e, string $channel = 'auto'): TenantRentalExtensionOffer
    {
        $offer = TenantRentalExtensionOffer::create([
            'tenant_id'      => $tenant->id,
            'rental_id'      => $rental->id,
            'token'          => Str::random(32),
            'status'         => 'sent',
            'channel'        => $channel,
            'offer_from'     => $e['offer_from'],
            'extend_to'      => $e['extend_to'],
            'discount_pct'   => $e['discount_pct'],
            'subtotal_cents' => $e['subtotal_cents'],
            'tax_cents'      => $e['tax_cents'],
            'total_cents'    => $e['total_cents'],
            'sent_at'        => now(),
            'expires_at'     => $e['offer_from'], // offer dies at the scheduled return
        ]);

        $customer = $rental->customer;
        $url = rtrim($tenant->publicUrl(), '/') . '/x/' . $offer->token;
        $body = sprintf(
            "%s: want to keep your %s longer? Nobody has it booked next — extend to %s for %d%%%% off (%s). Tap to confirm & pay: %s",
            $tenant->name,
            $e['unit']->name,
            tlocal_datetime($e['extend_to'], 'g:i A'),
            $e['discount_pct'],
            format_money($e['total_cents']),
            $url,
        );
        // %% above guards sprintf; collapse for the actual message
        $body = str_replace('%%', '%', $body);

        if ($customer?->phone) {
            try {
                SmsService::send($tenant, $customer->phone, $body);
            } catch (\\Throwable $ex) {
                Log::warning('rental_ext.sms_failed', ['offer' => $offer->id, 'error' => $ex->getMessage()]);
            }
        }

        return $offer;
    }

    /** Housekeeping: mark stale sent offers expired. */
    public function expireStale(): int
    {
        return TenantRentalExtensionOffer::where('status', 'sent')
            ->whereNotNull('expires_at')
            ->where('expires_at', '<', now())
            ->update(['status' => 'expired']);
    }
}
""", "service")

# ============================================================
# 4) Scan command + schedule
# ============================================================
newfile('app/Console/Commands/RentalExtensionOfferScan.php', """<?php

namespace App\\Console\\Commands;

use App\\Models\\Tenant;
use App\\Models\\Tenant\\TenantRental;
use App\\Services\\RentalExtensionOfferService;
use Illuminate\\Console\\Command;

/**
 * MARKER-RENTAL-EXT — rentals:extension-offer-scan. Every 15 minutes:
 * for tenants with the add-on active AND the setting on, find out
 * rentals due within the send window whose unit sits empty afterward,
 * and fire the SMS magic-link offer. Quiet hours skip the tenant for
 * this pass. Idempotent: one open offer per rental episode.
 */
class RentalExtensionOfferScan extends Command
{
    protected $signature = 'rentals:extension-offer-scan';
    protected $description = 'Send last-minute extension offers for eligible rentals.';

    public function handle(RentalExtensionOfferService $svc): int
    {
        $expired = $svc->expireStale();
        $sent = 0;

        $candidates = TenantRental::query()
            ->where('status', 'out')
            ->whereNull('returned_at')
            ->where('due_at', '>', now())
            ->where('due_at', '<=', now()->addHours(6)) // coarse pre-filter; per-tenant window below
            ->get();

        $tenants = [];
        foreach ($candidates as $rental) {
            $tenants[$rental->tenant_id] ??= Tenant::find($rental->tenant_id);
            $tenant = $tenants[$rental->tenant_id];
            if (!$tenant || !$svc->isFeatureOn($tenant)) continue;
            if ($svc->inQuietHours($tenant)) continue;

            $cfg = $svc->settings($tenant);
            if ($rental->due_at->gt(now()->addMinutes($cfg['send_before']))) continue;

            $reason = null;
            $e = $svc->eligibility($tenant, $rental, $reason);
            if (!$e) continue;

            $svc->createAndSend($tenant, $rental, $e, 'auto');
            $sent++;
        }

        $this->info("Extension scan: {$sent} offers sent, {$expired} expired.");
        return self::SUCCESS;
    }
}
""", "scan command")

sub('routes/console.php',
    """Schedule::command('rentals:overdue-sweep')""",
    """Schedule::command('rentals:extension-offer-scan')
    ->everyFifteenMinutes(); // MARKER-RENTAL-EXT

Schedule::command('rentals:overdue-sweep')""",
    "schedule scan")

# ============================================================
# 5) Settings — save() + settings blade card
# ============================================================
sub('app/Http/Controllers/Tenant/RentalSettingsController.php',
    """        $settings['rental_late_fee_cap']           = $request->input('late_fee_cap', 'day_rate');""",
    """        $settings['rental_late_fee_cap']           = $request->input('late_fee_cap', 'day_rate');
        // MARKER-RENTAL-EXT — last-minute extension offers.
        if ($tenant->rental_extensions_enabled) {
            $settings['rental_ext_enabled']             = (bool) $request->input('ext_enabled');
            $settings['rental_ext_discount_pct']        = max(0, min(90, (int) $request->input('ext_discount_pct', 50)));
            $settings['rental_ext_min_gap_minutes']     = max(30, (int) $request->input('ext_min_gap_minutes', 120));
            $settings['rental_ext_send_before_minutes'] = max(15, (int) $request->input('ext_send_before_minutes', 90));
            $settings['rental_ext_until']               = preg_match('/^\\\\d{2}:\\\\d{2}$/', (string) $request->input('ext_until')) ? $request->input('ext_until') : '20:00';
            $settings['rental_ext_quiet_start']         = preg_match('/^\\\\d{2}:\\\\d{2}$/', (string) $request->input('ext_quiet_start')) ? $request->input('ext_quiet_start') : '';
            $settings['rental_ext_quiet_end']           = preg_match('/^\\\\d{2}:\\\\d{2}$/', (string) $request->input('ext_quiet_end')) ? $request->input('ext_quiet_end') : '';
        }""",
    "settings save")

sub('resources/views/tenant/rentals/settings.blade.php',
    """        <input type="number" name="late_grace_minutes" value="{{ $lateGraceMinutes }}" min="0" max="1440" class="ia-input" style="width:120px">""",
    """        <input type="number" name="late_grace_minutes" value="{{ $lateGraceMinutes }}" min="0" max="1440" class="ia-input" style="width:120px">

        {{-- MARKER-RENTAL-EXT — last-minute extension offers --}}
        @php $extS = app(\\App\\Services\\RentalExtensionOfferService::class)->settings(tenant()); @endphp
        @if(tenant()->rental_extensions_enabled)
          <div style="border-top:.5px solid var(--ia-border);margin-top:18px;padding-top:16px">
            <div class="ia-label" style="margin-bottom:4px">Last-minute extension offers</div>
            <p style="font-size:12px;opacity:.5;margin:0 0 12px;line-height:1.5">When a rental is coming back and nobody has the unit booked next, Intake texts the renter a discounted extension with a one-tap pay link.</p>
            <label style="display:flex;align-items:center;gap:9px;font-size:13px;margin-bottom:12px;cursor:pointer">
              <input type="checkbox" name="ext_enabled" value="1" {{ $extS['enabled'] ? 'checked' : '' }}> Send automatic offers
            </label>
            <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(170px,1fr));gap:12px">
              <div>
                <label class="ia-label" style="display:block;margin-bottom:5px">Discount %</label>
                <input type="number" name="ext_discount_pct" value="{{ $extS['discount_pct'] }}" min="0" max="90" class="ia-input" style="width:100%">
              </div>
              <div>
                <label class="ia-label" style="display:block;margin-bottom:5px">Minimum gap (min)</label>
                <input type="number" name="ext_min_gap_minutes" value="{{ $extS['min_gap'] }}" min="30" max="1440" class="ia-input" style="width:100%">
              </div>
              <div>
                <label class="ia-label" style="display:block;margin-bottom:5px">Send before return (min)</label>
                <input type="number" name="ext_send_before_minutes" value="{{ $extS['send_before'] }}" min="15" max="480" class="ia-input" style="width:100%">
              </div>
              <div>
                <label class="ia-label" style="display:block;margin-bottom:5px">Extend up to (daily cutoff)</label>
                <input type="time" name="ext_until" value="{{ $extS['until'] }}" class="ia-input" style="width:100%">
              </div>
              <div>
                <label class="ia-label" style="display:block;margin-bottom:5px">Quiet hours start</label>
                <input type="time" name="ext_quiet_start" value="{{ $extS['quiet_start'] }}" class="ia-input" style="width:100%">
              </div>
              <div>
                <label class="ia-label" style="display:block;margin-bottom:5px">Quiet hours end</label>
                <input type="time" name="ext_quiet_end" value="{{ $extS['quiet_end'] }}" class="ia-input" style="width:100%">
              </div>
            </div>
          </div>
        @endif""",
    "settings blade card")

# ============================================================
# 6) Public controller + routes + pages
# ============================================================
newfile('app/Http/Controllers/Tenant/RentalExtensionOfferController.php', """<?php

namespace App\\Http\\Controllers\\Tenant;

use App\\Http\\Controllers\\Controller;
use App\\Models\\Tenant\\TenantRental;
use App\\Models\\Tenant\\TenantRentalExtensionOffer;
use App\\Models\\Tenant\\TenantSale;
use App\\Models\\Tenant\\TenantSaleItem;
use App\\Models\\Tenant\\TenantSalePayment;
use App\\Services\\DirectPaymentsService;
use App\\Services\\Sms\\SmsService;
use App\\Services\\Tenant\\SalePaymentService;
use Illuminate\\Http\\Request;
use Illuminate\\Support\\Facades\\DB;
use Illuminate\\Support\\Facades\\Log;
use Illuminate\\Support\\Str;

/**
 * MARKER-RENTAL-EXT — the magic-link surface. The token IS the auth:
 * no login, one screen, one tap. Payment rides the exact reserve-flow
 * rails (PI -> draft sale -> confirm records ledger payment), and the
 * rental's due_at moves only after Stripe says succeeded.
 */
class RentalExtensionOfferController extends Controller
{
    private function offerOr404(string $token): TenantRentalExtensionOffer
    {
        $tenant = tenant();
        abort_unless($tenant, 404);
        $offer = TenantRentalExtensionOffer::where('tenant_id', $tenant->id)
            ->where('token', $token)->first();
        abort_unless($offer, 404);
        return $offer;
    }

    public function show(string $token)
    {
        $tenant = tenant();
        $offer  = $this->offerOr404($token);
        $rental = TenantRental::with(['customer', 'lines.unit'])->find($offer->rental_id);
        abort_unless($rental, 404);

        // A paid offer shows its confirmation; anything else dead shows why.
        if ($offer->status === 'sent' && $offer->expires_at && $offer->expires_at->isPast()) {
            $offer->update(['status' => 'expired']);
        }

        return view('public.rental-extension', [
            'tenant' => $tenant,
            'offer'  => $offer,
            'rental' => $rental,
            'unit'   => $rental->lines->firstWhere('kind', 'unit')?->unit,
        ]);
    }

    public function decline(string $token)
    {
        $offer = $this->offerOr404($token);
        if ($offer->status === 'sent') {
            $offer->update(['status' => 'declined', 'responded_at' => now()]);
        }
        return redirect()->route('tenant.rentals.extension.show', $token);
    }

    /** Create the PI + draft sale; returns Stripe Elements bootstrap. */
    public function pay(string $token)
    {
        $tenant = tenant();
        $offer  = $this->offerOr404($token);
        if (!$offer->isOpen()) {
            return response()->json(['ok' => false, 'error' => 'This offer is no longer available.'], 410);
        }
        $rental = TenantRental::with('customer')->find($offer->rental_id);
        if (!$rental || $rental->status !== 'out' || $rental->returned_at) {
            return response()->json(['ok' => false, 'error' => 'This rental has already been returned.'], 410);
        }

        $payments = new DirectPaymentsService($tenant);
        if (!$payments->isEnabled()) {
            return response()->json(['ok' => false, 'error' => 'Online payment is not available — call the shop to extend.'], 422);
        }

        try {
            [$sale, $pi] = DB::transaction(function () use ($tenant, $offer, $rental, $payments) {
                // Reuse an existing draft if the customer double-taps.
                if ($offer->sale_id && $offer->stripe_payment_intent_id) {
                    $sale = TenantSale::find($offer->sale_id);
                    $pi   = $payments->retrievePaymentIntent($offer->stripe_payment_intent_id);
                    if ($sale && $pi && in_array($pi->status, ['requires_payment_method', 'requires_confirmation', 'requires_action'], true)) {
                        return [$sale, $pi];
                    }
                }

                $pi = $payments->createPaymentIntent($offer->total_cents, 'usd', [
                    'tenant_id' => $tenant->id,
                    'rental_id' => $rental->id,
                    'offer_id'  => $offer->id,
                    'purpose'   => 'rental_extension',
                ]);

                $sale = TenantSale::create([
                    'id'                       => (string) Str::uuid(),
                    'tenant_id'                => $tenant->id,
                    'sale_number'              => 'EXT-' . strtoupper(Str::random(6)),
                    'sale_date'                => now()->toDateString(),
                    'status'                   => 'pending',
                    'payment_status'           => 'draft',
                    'customer_id'              => $rental->customer_id,
                    'rental_id'                => $rental->id,
                    'stripe_payment_intent_id' => $pi->id,
                    'subtotal_cents'           => $offer->subtotal_cents,
                    'tax_cents'                => $offer->tax_cents,
                    'total_cents'              => $offer->total_cents,
                    'notes'                    => 'Last-minute extension for rental ' . $rental->rental_number,
                ]);
                TenantSaleItem::create([
                    'id'               => (string) Str::uuid(),
                    'tenant_id'        => $tenant->id,
                    'sale_id'          => $sale->id,
                    'type'             => 'open_item',
                    'name_snapshot'    => 'Rental extension — ' . $rental->rental_number . ' to ' . tlocal_datetime($offer->extend_to, 'g:i A'),
                    'quantity'         => 1,
                    'unit_price_cents' => $offer->subtotal_cents,
                    'line_total_cents' => $offer->subtotal_cents,
                    'is_taxable'       => $offer->tax_cents > 0,
                    'position'         => 0,
                ]);

                $offer->update(['sale_id' => $sale->id, 'stripe_payment_intent_id' => $pi->id]);
                return [$sale, $pi];
            });
        } catch (\\Throwable $e) {
            Log::error('rental_ext.pay_bootstrap_failed', ['offer' => $offer->id, 'error' => $e->getMessage()]);
            return response()->json(['ok' => false, 'error' => 'Could not start the payment — try again.'], 500);
        }

        return response()->json([
            'ok'              => true,
            'client_secret'   => $pi->client_secret,
            'payment_intent'  => $pi->id,
            'publishable_key' => $payments->publishableKey(),
        ]);
    }

    /** Stripe said succeeded (client-side) — verify, extend, receipt. */
    public function confirm(string $token, Request $request)
    {
        $tenant = tenant();
        $offer  = $this->offerOr404($token);
        $request->validate(['payment_intent' => ['required', 'string', 'max:120']]);
        $piId = $request->input('payment_intent');

        if ($offer->stripe_payment_intent_id !== $piId || !$offer->sale_id) {
            return response()->json(['ok' => false, 'error' => 'Payment reference mismatch.'], 422);
        }
        $sale   = TenantSale::find($offer->sale_id);
        $rental = TenantRental::find($offer->rental_id);
        if (!$sale || !$rental) {
            return response()->json(['ok' => false, 'error' => 'Offer records are missing — contact the shop.'], 404);
        }

        // Idempotent: double-click or webhook may have beaten us.
        $already = TenantSalePayment::where('sale_id', $sale->id)
            ->where('external_reference', $piId)->exists();
        if (!$already) {
            try {
                $payments = new DirectPaymentsService($tenant);
                $pi = $payments->retrievePaymentIntent($piId);
            } catch (\\Throwable $e) {
                Log::error('rental_ext.confirm_retrieve_failed', ['pi' => $piId, 'error' => $e->getMessage()]);
                return response()->json(['ok' => false, 'error' => 'Could not verify the payment.'], 502);
            }
            if ($pi->status !== 'succeeded') {
                return response()->json(['ok' => false, 'error' => 'Payment has not completed.'], 422);
            }
            if ((int) $pi->amount !== (int) $sale->total_cents) {
                Log::error('rental_ext.amount_mismatch', ['pi' => $piId, 'sale' => $sale->id]);
                return response()->json(['ok' => false, 'error' => 'Payment amount mismatch — contact the shop.'], 422);
            }

            app(SalePaymentService::class)->record(
                $sale,
                (int) $pi->amount,
                TenantSalePayment::KIND_PAYMENT,
                TenantSalePayment::SOURCE_BOOKING_FLOW,
                'card',
                null,
                $piId,
                'Last-minute rental extension payment.',
            );
        }

        if ($offer->status !== 'paid') {
            DB::transaction(function () use ($offer, $rental) {
                $rental->update([
                    'original_due_at' => $rental->original_due_at ?? $rental->due_at,
                    'due_at'          => $offer->extend_to,
                ]);
                $offer->update(['status' => 'paid', 'responded_at' => now()]);
            });

            $customer = $rental->customer;
            if ($customer?->phone) {
                try {
                    SmsService::send(tenant(), $customer->phone,
                        tenant()->name . ': you\\'re extended! ' . 'New return time: '
                        . tlocal_datetime($offer->extend_to, 'g:i A') . '. Receipt: '
                        . rtrim(tenant()->publicUrl(), '/') . '/x/' . $offer->token);
                } catch (\\Throwable $e) {
                    Log::warning('rental_ext.confirm_sms_failed', ['offer' => $offer->id]);
                }
            }
        }

        return response()->json(['ok' => true, 'next' => route('tenant.rentals.extension.show', $offer->token)]);
    }
}
""", "public controller")

sub('routes/web.php',
    """    Route::get('/waitlist/offer/{token}',      [TenantControllers\\WaitlistOfferController::class, 'show'])->name('tenant.waitlist.offer.show');""",
    """    // MARKER-RENTAL-EXT — magic-link extension offer (token is the auth)
    Route::get( '/x/{token}',          [TenantControllers\\RentalExtensionOfferController::class, 'show'])->name('tenant.rentals.extension.show');
    Route::post('/x/{token}/decline',  [TenantControllers\\RentalExtensionOfferController::class, 'decline'])->name('tenant.rentals.extension.decline');
    Route::post('/x/{token}/pay',      [TenantControllers\\RentalExtensionOfferController::class, 'pay'])->name('tenant.rentals.extension.pay');
    Route::post('/x/{token}/confirm',  [TenantControllers\\RentalExtensionOfferController::class, 'confirm'])->name('tenant.rentals.extension.confirm');

    Route::get('/waitlist/offer/{token}',      [TenantControllers\\WaitlistOfferController::class, 'show'])->name('tenant.waitlist.offer.show');""",
    "public routes")

# ============================================================
# 7) Public offer page (view for show/paid/dead states)
# ============================================================
newfile('resources/views/public/rental-extension.blade.php', """{{-- MARKER-RENTAL-EXT — one screen, one tap. States: open / paid / dead. --}}
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Extend your rental — {{ $tenant->name }}</title>
<style>
  body { font-family:-apple-system,'Inter',sans-serif; background:#f5f5f4; color:#141414; margin:0; -webkit-font-smoothing:antialiased; }
  .wrap { max-width:420px; margin:0 auto; padding:28px 18px 60px; }
  .shop { font-size:13px; font-weight:700; letter-spacing:.02em; margin-bottom:22px; opacity:.7 }
  .card { background:#fff; border:1px solid rgba(0,0,0,.09); border-radius:16px; padding:22px 20px; box-shadow:0 1px 4px rgba(0,0,0,.04); }
  h1 { font-size:21px; letter-spacing:-.01em; margin:0 0 6px; line-height:1.25 }
  .sub { font-size:14px; opacity:.6; line-height:1.55; margin:0 0 18px }
  .row { display:flex; justify-content:space-between; font-size:13.5px; padding:9px 0; border-bottom:1px dashed rgba(0,0,0,.09) }
  .row:last-of-type { border-bottom:none }
  .row b { font-variant-numeric:tabular-nums }
  .strike { text-decoration:line-through; opacity:.4; margin-right:6px }
  .btn { display:block; width:100%; border:none; border-radius:11px; padding:14px; font-size:15px; font-weight:700; cursor:pointer; text-align:center; box-sizing:border-box }
  .btn-pay { background:#141414; color:#fff; margin-top:16px }
  .btn-no { background:none; color:rgba(0,0,0,.45); font-weight:500; font-size:13px; margin-top:6px }
  .badge { display:inline-block; font-size:11px; font-weight:700; padding:4px 10px; border-radius:99px; margin-bottom:12px }
  .badge.ok { background:rgba(34,160,84,.12); color:#1c7a43 }
  .badge.dead { background:rgba(0,0,0,.07); color:rgba(0,0,0,.5) }
  .err { display:none; background:rgba(220,60,60,.08); color:#b23434; border-radius:9px; padding:10px 12px; font-size:13px; margin-top:12px }
  #payment-element { margin-top:16px }
  #pay-wrap { display:none }
</style>
</head>
<body>
<div class="wrap">
  <div class="shop">{{ $tenant->name }}</div>

  @if($offer->status === 'paid')
    <div class="card">
      <span class="badge ok">Extended</span>
      <h1>You're all set.</h1>
      <p class="sub">Your rental is extended — enjoy the extra time.</p>
      <div class="row"><span>Return by</span><b>{{ tlocal_datetime($offer->extend_to, 'g:i A, D M j') }}</b></div>
      @if($unit)<div class="row"><span>Unit</span><b>{{ $unit->name }}@if($unit->identifier) · {{ $unit->identifier }}@endif</b></div>@endif
      <div class="row"><span>Paid</span><b>{{ format_money($offer->total_cents) }}</b></div>
      <p class="sub" style="margin-top:16px;margin-bottom:0">Any deposit hold stays in place until you return the unit — no new charge unless something's damaged or lost.</p>
    </div>

  @elseif(!$offer->isOpen() || $rental->status !== 'out' || $rental->returned_at)
    <div class="card">
      <span class="badge dead">{{ $offer->status === 'declined' ? 'Declined' : 'Offer expired' }}</span>
      <h1>This offer isn't available anymore.</h1>
      <p class="sub" style="margin-bottom:0">
        @if($offer->status === 'declined') No worries — see you at {{ tlocal_datetime($rental->due_at, 'g:i A') }}.
        @else The extension window has passed. If you'd like more time, give the shop a call.
        @endif
      </p>
    </div>

  @else
    <div class="card">
      <h1>Keep it longer?</h1>
      <p class="sub">Nobody has {{ $unit?->name ?? 'your rental' }} booked after you — extend to <b>{{ tlocal_datetime($offer->extend_to, 'g:i A') }}</b> for {{ $offer->discount_pct }}% off.</p>
      <div class="row"><span>Current return</span><b>{{ tlocal_datetime($offer->offer_from, 'g:i A') }}</b></div>
      <div class="row"><span>Extended return</span><b>{{ tlocal_datetime($offer->extend_to, 'g:i A') }}</b></div>
      <div class="row"><span>Price</span><b><span class="strike">{{ format_money((int) round($offer->subtotal_cents * 100 / max(1, 100 - $offer->discount_pct))) }}</span>{{ format_money($offer->subtotal_cents) }}</b></div>
      @if($offer->tax_cents > 0)<div class="row"><span>Tax</span><b>{{ format_money($offer->tax_cents) }}</b></div>@endif
      <div class="row"><span>Total now</span><b>{{ format_money($offer->total_cents) }}</b></div>

      <button class="btn btn-pay" id="ext-pay-start">Extend & pay {{ format_money($offer->total_cents) }}</button>
      <div id="pay-wrap">
        <div id="payment-element"></div>
        <button class="btn btn-pay" id="ext-pay-confirm">Pay {{ format_money($offer->total_cents) }}</button>
      </div>
      <div class="err" id="ext-err"></div>
      <form method="POST" action="{{ route('tenant.rentals.extension.decline', $offer->token) }}">
        @csrf
        <button class="btn btn-no">No thanks — I'll return it on time</button>
      </form>
    </div>

    <script src="https://js.stripe.com/v3/"></script>
    <script>
    (function () {
      var csrf = '{{ csrf_token() }}';
      var errEl = document.getElementById('ext-err');
      var start = document.getElementById('ext-pay-start');
      var confirmBtn = document.getElementById('ext-pay-confirm');
      var stripe = null, elements = null, piId = null;
      function showErr(msg) { errEl.textContent = msg; errEl.style.display = 'block'; }

      start.addEventListener('click', function () {
        start.disabled = true; errEl.style.display = 'none';
        fetch('{{ route('tenant.rentals.extension.pay', $offer->token) }}', {
          method: 'POST',
          headers: { 'Accept': 'application/json', 'X-CSRF-TOKEN': csrf }
        }).then(function (r) { return r.json().then(function (j) { return { ok: r.ok, json: j }; }); })
          .then(function (res) {
            if (!res.ok || !res.json.ok) { showErr((res.json && res.json.error) || 'Could not start the payment.'); start.disabled = false; return; }
            piId = res.json.payment_intent;
            stripe = Stripe(res.json.publishable_key);
            elements = stripe.elements({ clientSecret: res.json.client_secret });
            elements.create('payment').mount('#payment-element');
            start.style.display = 'none';
            document.getElementById('pay-wrap').style.display = 'block';
          })
          .catch(function () { showErr('Could not start the payment.'); start.disabled = false; });
      });

      confirmBtn.addEventListener('click', function () {
        confirmBtn.disabled = true; errEl.style.display = 'none';
        stripe.confirmPayment({ elements: elements, redirect: 'if_required' }).then(function (result) {
          if (result.error) { showErr(result.error.message || 'Card was not accepted.'); confirmBtn.disabled = false; return; }
          fetch('{{ route('tenant.rentals.extension.confirm', $offer->token) }}', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': csrf },
            body: JSON.stringify({ payment_intent: piId })
          }).then(function (r) { return r.json().then(function (j) { return { ok: r.ok, json: j }; }); })
            .then(function (res) {
              if (res.ok && res.json.ok) { window.location = res.json.next; }
              else { showErr((res.json && res.json.error) || 'Payment went through but confirmation hiccuped — the shop will sort it.'); confirmBtn.disabled = false; }
            });
        });
      });
    })();
    </script>
  @endif
</div>
</body>
</html>
""", "public offer page")

# ============================================================
# 8) Admin: manual send + rental-detail panel
# ============================================================
sub('routes/web.php',
    """                Route::get('/rentals/bookings/{id}',             [TenantControllers\\RentalBookingController::class, 'show'])->name('rentals.bookings.show');""",
    """                Route::get('/rentals/bookings/{id}',             [TenantControllers\\RentalBookingController::class, 'show'])->name('rentals.bookings.show');
                Route::post('/rentals/bookings/{id}/extension-offer', [TenantControllers\\RentalBookingController::class, 'sendExtensionOffer'])->name('rentals.bookings.extension.send'); // MARKER-RENTAL-EXT""",
    "admin route")

sub('app/Http/Controllers/Tenant/RentalBookingController.php',
    """class RentalBookingController extends Controller
{""",
    """class RentalBookingController extends Controller
{
    // MARKER-RENTAL-EXT — manual "Send offer now" from the rental detail.
    public function sendExtensionOffer(string $id)
    {
        $tenant = tenant();
        $rental = \\App\\Models\\Tenant\\TenantRental::where('tenant_id', $tenant->id)->findOrFail($id);
        $svc = app(\\App\\Services\\RentalExtensionOfferService::class);

        if (!$tenant->rental_extensions_enabled) {
            return back()->with('error', 'The Last-minute extension offers add-on is not active.');
        }
        $reason = null;
        $e = $svc->eligibility($tenant, $rental, $reason);
        if (!$e) {
            return back()->with('error', 'Not eligible: ' . $reason);
        }
        $svc->createAndSend($tenant, $rental, $e, 'manual');
        return back()->with('success', 'Extension offer sent by text.');
    }
""",
    "admin controller op")

sub('resources/views/tenant/rentals/bookings/show.blade.php',
    """<div class="ia-card" style="margin-bottom:16px;padding:14px 18px;display:flex;align-items:center;flex-wrap:wrap">""",
    """{{-- MARKER-RENTAL-EXT — eligibility panel: shows the live offer state or
     why this rental can't get one, with a manual send. --}}
@php
  $extPanel = null;
  if (tenant()->rental_extensions_enabled && $rental->status === 'out' && !$rental->returned_at) {
      $extSvc = app(\\App\\Services\\RentalExtensionOfferService::class);
      $extOffer = \\App\\Models\\Tenant\\TenantRentalExtensionOffer::where('rental_id', $rental->id)
          ->orderByDesc('created_at')->first();
      $extReason = null;
      $extElig = ($extOffer && in_array($extOffer->status, ['sent', 'paid'], true)) ? null : $extSvc->eligibility(tenant(), $rental, $extReason);
      $extPanel = ['offer' => $extOffer, 'elig' => $extElig, 'reason' => $extReason];
  }
@endphp
@if($extPanel)
  <div class="ia-card" style="margin-bottom:16px;padding:14px 18px">
    <div style="display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap">
      <div>
        <div style="font-size:12.5px;font-weight:700;margin-bottom:2px">Last-minute extension</div>
        @if($extPanel['offer'] && $extPanel['offer']->status === 'paid')
          <div style="font-size:12px;opacity:.6">Accepted &amp; paid {{ format_money($extPanel['offer']->total_cents) }} — extended to {{ tlocal_datetime($extPanel['offer']->extend_to, 'g:i A') }}.</div>
        @elseif($extPanel['offer'] && $extPanel['offer']->status === 'sent')
          <div style="font-size:12px;opacity:.6">Offer sent {{ tlocal_datetime($extPanel['offer']->sent_at, 'g:i A') }} — awaiting response. Extends to {{ tlocal_datetime($extPanel['offer']->extend_to, 'g:i A') }} for {{ format_money($extPanel['offer']->total_cents) }}.</div>
        @elseif($extPanel['elig'])
          <div style="font-size:12px;opacity:.6">Eligible — unit is free until {{ tlocal_datetime($extPanel['elig']['extend_to'], 'g:i A') }}. Offer price {{ format_money($extPanel['elig']['total_cents']) }} ({{ $extPanel['elig']['discount_pct'] }}% off).</div>
        @else
          <div style="font-size:12px;opacity:.6">Not eligible: {{ $extPanel['reason'] ?? ($extPanel['offer'] ? ucfirst($extPanel['offer']->status) . ' offer on file.' : 'n/a') }}</div>
        @endif
      </div>
      @if($extPanel['elig'])
        <form method="POST" action="{{ route('tenant.rentals.bookings.extension.send', $rental->id) }}">
          @csrf
          <button class="ia-btn ia-btn--sm">Send offer now</button>
        </form>
      @endif
    </div>
  </div>
@endif

<div class="ia-card" style="margin-bottom:16px;padding:14px 18px;display:flex;align-items:center;flex-wrap:wrap">""",
    "rental detail panel")

print("\\nDone. Post-deploy: php artisan migrate --force && php artisan view:clear")
