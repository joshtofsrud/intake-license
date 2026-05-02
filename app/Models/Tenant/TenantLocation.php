<?php

namespace App\Models\Tenant;

use App\Models\Tenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A physical location for a tenant.
 *
 * Every tenant has at least one location (the default "Main" location),
 * even if they're single-location forever. Multi-location is a capability
 * flag — controls whether the UI lets them create more than one.
 *
 * Falls back to tenant-level values for booking_window_days, min_notice_hours,
 * and timezone when the location-level override is null.
 */
class TenantLocation extends Model
{
    use HasUuids, SoftDeletes;

    protected $table = 'tenant_locations';

    protected $fillable = [
        'tenant_id',
        'name',
        'slug',
        'is_default',
        'is_active',
        'sort_order',
        'address_line_1',
        'address_line_2',
        'city',
        'state',
        'postal_code',
        'country',
        'phone',
        'email',
        'timezone',
        'booking_window_days_override',
        'min_notice_hours_override',
        'settings',
    ];

    protected $casts = [
        'is_default' => 'boolean',
        'is_active' => 'boolean',
        'sort_order' => 'integer',
        'booking_window_days_override' => 'integer',
        'min_notice_hours_override' => 'integer',
        'settings' => 'array',
    ];

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function capacityRules(): HasMany
    {
        return $this->hasMany(TenantCapacityRule::class, 'location_id');
    }

    public function inventoryItemLocations(): HasMany
    {
        return $this->hasMany(TenantInventoryItemLocation::class, 'location_id');
    }

    public function inventoryMovements(): HasMany
    {
        return $this->hasMany(TenantInventoryMovement::class, 'location_id');
    }

    public function receiveShipments(): HasMany
    {
        return $this->hasMany(TenantInventoryReceiveShipment::class, 'location_id');
    }

    /**
     * Effective timezone — falls back to tenant timezone when null.
     */
    public function effectiveTimezone(): string
    {
        return $this->timezone ?: $this->tenant->timezone();
    }

    /**
     * Effective booking window — falls back to tenant value when override is null.
     */
    public function effectiveBookingWindowDays(): ?int
    {
        return $this->booking_window_days_override ?? $this->tenant->booking_window_days;
    }

    /**
     * Effective min notice — falls back to tenant value when override is null.
     */
    public function effectiveMinNoticeHours(): ?int
    {
        return $this->min_notice_hours_override ?? $this->tenant->min_notice_hours;
    }
}
