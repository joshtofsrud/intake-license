<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Addon - catalog entry for everything Intake sells or gates.
 *
 * Not tenant-scoped; this is the master list. Read mostly; written via seeder
 * or rare manual master-admin edits (future).
 */
class Addon extends Model
{
    protected $table = 'addons';

    protected $fillable = [
        'code',
        'name',
        'category',
        'description',
        'tooltip',
        'price_cents',
        'billing_cadence',
        'price_display_override',
        'included_in_plans',
        'stripe_product_id',
        'stripe_price_id_monthly',
        'stripe_price_id_annual',
        'stripe_price_id_onetime',
        'sort_order',
        'status',
        'is_self_serve',
        'is_new',
    ];

    protected $casts = [
        'included_in_plans' => 'array',
        'is_self_serve' => 'boolean',
        'is_new' => 'boolean',
        'price_cents' => 'integer',
        'sort_order' => 'integer',
    ];

    public function getDisplayPriceAttribute(): string
    {
        if ($this->price_display_override) {
            return $this->price_display_override;
        }

        if ($this->billing_cadence === 'one_time') {
            return '$' . number_format($this->price_cents / 100, 0) . ' once';
        }

        $dollars = number_format($this->price_cents / 100, 0);
        return "\${$dollars}/mo";
    }

    public function scopeSelfServe($query)
    {
        return $query->where('is_self_serve', true);
    }

    /**
     * MARKER-ADDON-CATALOG — the three states.
     *
     * ACTIVE   offered to everyone, billed as normal.
     * CLOSED   not offered to new shops; shops that already have it keep it and
     *          keep being billed. This is how something is retired without
     *          taking a feature away from someone paying for it.
     * RETIRED  off for everyone; existing activations stop.
     */
    public const ACTIVE  = 'active';
    public const CLOSED  = 'closed';
    public const RETIRED = 'retired';

    public const STATUSES = [
        self::ACTIVE  => 'Active — anyone can add it',
        self::CLOSED  => 'Closed — no new shops, existing keep it',
        self::RETIRED => 'Retired — off for everyone',
    ];

    /** MARKER-ADDON-CATALOG — today's price, which may be newer than the column. */
    public function currentPriceCents(): int
    {
        return \App\Support\AddonPricing::for($this->code);
    }

    /** Can a shop that does not have this add it? */
    public function isAvailableToNew(): bool
    {
        return $this->status === self::ACTIVE;
    }

    /** Does a shop that already has it keep working? */
    public function stillWorksForExisting(): bool
    {
        return $this->status !== self::RETIRED;
    }

    public function scopeActive($query)
    {
        return $query->where('status', 'active');
    }

    public function scopeOrdered($query)
    {
        return $query->orderBy('sort_order');
    }
}
