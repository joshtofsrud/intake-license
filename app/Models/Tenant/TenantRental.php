<?php
// MARKER-PATCH-217

namespace App\Models\Tenant;

use App\Models\Tenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A rental booking: reserved -> out -> returned (or cancelled).
 *
 * Time semantics: starts_at / due_at / returned_at are UTC instants
 * (datetime casts) — display via tlocal(). "Overdue" is DERIVED:
 * status=out AND due_at < now(). Never persisted, never stale.
 *
 * Money (Rail 2): paid_cents is a denormalized sum of the
 * tenant_rental_payments ledger; the ledger is canon. Deposit
 * authorizations live in deposit_status/stripe_deposit_intent_id and are
 * NOT ledger rows — only captures are.
 *
 * History (Rail 3): customer_id is indexed; CustomerTimelineService gains a
 * 'rental' source in the bookings patch.
 */
class TenantRental extends Model
{
    use HasUuids;

    protected $table = 'tenant_rentals';

    protected $fillable = [
        'tenant_id', 'location_id', 'customer_id',
        'rental_number', 'status', 'source',
        'starts_at', 'due_at', 'original_due_at', 'returned_at',
        'subtotal_cents', 'tax_cents', 'total_cents', 'paid_cents',
        'deposit_hold_cents', 'deposit_status', 'stripe_deposit_intent_id',
        'agreement_template_version', 'agreement_signed_at',
        'agreement_method', 'agreement_pdf_path',
        'notes',
    ];

    protected $casts = [
        'starts_at'                  => 'datetime',
        'due_at'                     => 'datetime',
        'original_due_at'            => 'datetime',
        'returned_at'                => 'datetime',
        'checked_out_at'             => 'datetime', // MARKER-PATCH-234
        'cancelled_at'               => 'datetime', // MARKER-PATCH-234
        'agreement_signed_at'        => 'datetime',
        'subtotal_cents'             => 'integer',
        'tax_cents'                  => 'integer',
        'total_cents'                => 'integer',
        'paid_cents'                 => 'integer',
        'deposit_hold_cents'         => 'integer',
        'agreement_template_version' => 'integer',
    ];

    // ----------------------------------------------------------------
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(TenantCustomer::class, 'customer_id');
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(TenantLocation::class, 'location_id');
    }

    public function lines(): HasMany
    {
        return $this->hasMany(TenantRentalLine::class, 'rental_id')->orderBy('sort_order');
    }

    public function conditionChecks(): HasMany
    {
        return $this->hasMany(TenantRentalConditionCheck::class, 'rental_id');
    }

    /**
     * MARKER-PATCH-219B — sales-as-money: the rental's money is carried by
     * linked register sales. Exact mirror of TenantAppointment::payments().
     */
    public function sales(): HasMany
    {
        return $this->hasMany(TenantSale::class, 'rental_id');
    }

    public function payments(): \Illuminate\Database\Eloquent\Relations\HasManyThrough
    {
        return $this->hasManyThrough(
            TenantSalePayment::class,
            TenantSale::class,
            'rental_id', // FK on tenant_sales -> rentals
            'sale_id',   // FK on tenant_sale_payments -> sales
            'id',
            'id'
        );
    }

    // ----------------------------------------------------------------
    public function scopeActive($q)
    {
        return $q->whereIn('status', ['reserved', 'out']);
    }

    public function isOverdue(): bool
    {
        return $this->status === 'out'
            && $this->due_at !== null
            && $this->due_at->isPast();
    }

    /**
     * Recompute paid_cents from the ledger (tenant_sale_payments through
     * linked sales — the ledger is canon). MARKER-PATCH-219B.
     */
    public function refreshPaidCents(): void
    {
        $this->update([
            'paid_cents' => max(0, (int) $this->payments()->sum('tenant_sale_payments.amount_cents')),
        ]);
    }

    /**
     * R-{mdy}-{XXXX} — same collision-safe generator pattern as
     * TenantAppointment::generateRaNumber (no sequence table, no lock).
     */
    public static function generateRentalNumber(string $tenantId, ?string $forDate = null): string
    {
        $date = $forDate ? new \DateTimeImmutable($forDate) : new \DateTimeImmutable('today');
        $datePart = $date->format('mdy');
        $alphabet = 'ABCDEFGHJKMNPQRSTUVWXYZ23456789';

        for ($attempt = 0; $attempt < 6; $attempt++) {
            $random = '';
            for ($i = 0; $i < 4; $i++) {
                $random .= $alphabet[random_int(0, strlen($alphabet) - 1)];
            }
            $candidate = "R-{$datePart}-{$random}";
            $exists = static::where('tenant_id', $tenantId)->where('rental_number', $candidate)->exists();
            if (!$exists) return $candidate;
        }
        throw new \RuntimeException('Could not generate a unique rental number after 6 attempts.');
    }
}
