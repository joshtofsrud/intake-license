<?php
// MARKER-DISCOUNTS

namespace App\Models\Tenant;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class TenantDiscount extends Model
{
    use HasUuids;

    protected $table = 'tenant_discounts';

    protected $fillable = [
        'tenant_id', 'code', 'label', 'type', 'value',
        'min_subtotal_cents', 'max_discount_cents',
        'starts_at', 'ends_at',
        'max_redemptions', 'max_per_customer', 'redemption_count',
        'is_active', 'campaign_id', 'created_by',
    ];

    protected $casts = [
        'starts_at'  => 'datetime',
        'ends_at'    => 'datetime',
        'is_active'  => 'boolean',
    ];

    public const TYPE_PERCENT = 'percent';
    public const TYPE_FIXED   = 'fixed';

    public function redemptions()
    {
        return $this->hasMany(TenantDiscountRedemption::class, 'discount_id');
    }

    /** Human summary — "20% off" / "$15 off", plus any cap. */
    public function summary(): string
    {
        $base = $this->type === self::TYPE_PERCENT
            ? $this->value . '% off'
            : '$' . number_format($this->value / 100, 2) . ' off';

        if ($this->type === self::TYPE_PERCENT && $this->max_discount_cents > 0) {
            $base .= ', max $' . number_format($this->max_discount_cents / 100, 2);
        }
        if ($this->min_subtotal_cents > 0) {
            $base .= ' on $' . number_format($this->min_subtotal_cents / 100, 2) . '+';
        }

        return $base;
    }

    /** Why this code isn't usable right now, or null when it is. */
    public function inactiveReason(): ?string
    {
        if (! $this->is_active) return 'Turned off';
        if ($this->starts_at && $this->starts_at->isFuture()) return 'Starts ' . $this->starts_at->format('M j');
        if ($this->ends_at && $this->ends_at->isPast()) return 'Expired ' . $this->ends_at->format('M j');
        if ($this->max_redemptions > 0 && $this->redemption_count >= $this->max_redemptions) return 'Fully used';
        return null;
    }
}
