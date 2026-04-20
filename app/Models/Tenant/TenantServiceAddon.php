<?php

namespace App\Models\Tenant;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\Pivot;

class TenantServiceAddon extends Pivot
{
    use HasUuids;

    public $incrementing = false;
    protected $keyType   = 'string';

    protected $table = 'tenant_service_addons';

    protected $fillable = [
        'service_item_id',
        'addon_id',
        'override_duration_minutes',
        'override_price_cents',
        'sort_order',
    ];

    protected $casts = [
        'override_duration_minutes' => 'integer',
        'override_price_cents'      => 'integer',
        'sort_order'                => 'integer',
    ];

    // ----------------------------------------------------------------
    // Relationships
    // ----------------------------------------------------------------
    public function addon(): BelongsTo
    {
        return $this->belongsTo(TenantAddon::class, 'addon_id');
    }

    public function serviceItem(): BelongsTo
    {
        return $this->belongsTo(TenantServiceItem::class, 'service_item_id');
    }

    // ----------------------------------------------------------------
    // Effective value helpers
    // Returns override if set, otherwise the library default.
    // ----------------------------------------------------------------
    public function effectiveDuration(): int
    {
        if ($this->override_duration_minutes !== null) {
            return (int) $this->override_duration_minutes;
        }
        return (int) ($this->addon->default_duration_minutes ?? 0);
    }

    public function effectivePriceCents(): int
    {
        if ($this->override_price_cents !== null) {
            return (int) $this->override_price_cents;
        }
        return (int) ($this->addon->price_cents ?? 0);
    }

    public function hasDurationOverride(): bool
    {
        return $this->override_duration_minutes !== null;
    }

    public function hasPriceOverride(): bool
    {
        return $this->override_price_cents !== null;
    }

    public function hasAnyOverride(): bool
    {
        return $this->hasDurationOverride() || $this->hasPriceOverride();
    }
}
