<?php

namespace App\Services;

use App\Models\Tenant;
use App\Models\Tenant\TenantRental;
use App\Models\Tenant\TenantRentalExtensionOffer;
use App\Services\Sms\SmsService;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

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
            } catch (\Throwable $ex) {
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
