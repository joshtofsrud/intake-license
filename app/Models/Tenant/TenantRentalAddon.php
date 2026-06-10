<?php
// MARKER-PATCH-217

namespace App\Models\Tenant;

use App\Models\Tenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Per-rental add-on (helmet, lock, rack) — flat or per-day priced.
 * NOT to be confused with TenantAppointmentAddon (service add-ons).
 */
class TenantRentalAddon extends Model
{
    use HasUuids;

    protected $table = 'tenant_rental_addons';

    protected $fillable = [
        'tenant_id', 'name', 'pricing_mode', 'price_cents', 'sort_order', 'archived_at',
    ];

    protected $casts = [
        'price_cents' => 'integer',
        'sort_order'  => 'integer',
        'archived_at' => 'datetime',
    ];

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function scopeActive($q)
    {
        return $q->whereNull('archived_at');
    }
}
