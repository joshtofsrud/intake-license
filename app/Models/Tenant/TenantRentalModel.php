<?php
// MARKER-PATCH-226

namespace App\Models\Tenant;

use App\Models\Tenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A rental model — "Rossignol Experience 80". Carries the rate card,
 * deposit, and condition checklist. Its units are the serial-tracked
 * instances customers take out. For a simple shop with unique items, each
 * model has exactly one unit and the UI collapses the distinction.
 */
class TenantRentalModel extends Model
{
    use HasUuids;

    protected $table = 'tenant_rental_models';

    protected $fillable = [
        'tenant_id', 'category_id', 'name', 'subtitle', 'image_url', // MARKER-RENTAL-MODEL-PHOTOS
        'hourly_rate_cents', 'daily_rate_cents', 'weekend_rate_cents',
        'seasonal_rate_cents', 'deposit_cents', 'condition_template_id',
        'sort_order', 'archived_at',
        'identifiers', // MARKER-FLEET-IDENT
    ];

    protected $casts = [
        'hourly_rate_cents'   => 'integer',
        'daily_rate_cents'    => 'integer',
        'weekend_rate_cents'  => 'integer',
        'seasonal_rate_cents' => 'integer',
        'deposit_cents'       => 'integer',
        'sort_order'          => 'integer',
        'archived_at'         => 'datetime',
        'identifiers'         => 'array', // MARKER-FLEET-IDENT
    ];

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(TenantRentalCategory::class, 'category_id');
    }

    public function conditionTemplate(): BelongsTo
    {
        return $this->belongsTo(TenantRentalConditionTemplate::class, 'condition_template_id');
    }

    public function units(): HasMany
    {
        return $this->hasMany(TenantRentalUnit::class, 'model_id');
    }

    public function scopeActive($q)
    {
        return $q->whereNull('archived_at');
    }
}
