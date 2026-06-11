<?php
// MARKER-PATCH-230

namespace App\Models\Tenant;

use App\Models\Tenant;
use App\Models\TenantCustomer;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A lease — a season-long transaction off a package. Owns assignments (the
 * specific units pulled from the fleet for the season). Out/overdue is
 * derived from season_end vs now; status persists active|returned|cancelled.
 */
class Lease extends Model
{
    use HasUuids;

    protected $table = 'leases';

    protected $fillable = [
        'tenant_id', 'location_id', 'customer_id', 'package_id',
        'lease_number', 'package_name_snapshot',
        'season_start', 'season_end', 'returned_at', 'status',
        'subtotal_cents', 'tax_cents', 'total_cents', 'paid_cents',
        'deposit_hold_cents', 'deposit_status', 'stripe_deposit_intent_id',
        'notes',
    ];

    protected $casts = [
        'season_start'       => 'datetime',
        'season_end'         => 'datetime',
        'returned_at'        => 'datetime',
        'subtotal_cents'     => 'integer',
        'tax_cents'          => 'integer',
        'total_cents'        => 'integer',
        'paid_cents'         => 'integer',
        'deposit_hold_cents' => 'integer',
    ];

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(TenantCustomer::class, 'customer_id');
    }

    public function package(): BelongsTo
    {
        return $this->belongsTo(LeasePackage::class, 'package_id');
    }

    public function assignments(): HasMany
    {
        return $this->hasMany(LeaseAssignment::class, 'lease_id');
    }

    public function isActive(): bool
    {
        return $this->status === 'active';
    }

    public function isOverdue(): bool
    {
        return $this->status === 'active'
            && $this->returned_at === null
            && $this->season_end !== null
            && $this->season_end->isPast();
    }

    public function balanceDueCents(): int
    {
        return max(0, (int) $this->total_cents - (int) $this->paid_cents);
    }
}
