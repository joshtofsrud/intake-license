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
        'visibility', // MARKER-ADDON-VISIBILITY
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
    // MARKER-ADDON-TENANT-LINK — 'deprecated' is the closed-to-new state,
    // because FeatureAccessService already treats it as one. Inventing a
    // synonym ('closed') meant an add-on matched neither branch of that query
    // and vanished for shops already paying for it.
    public const ACTIVE     = 'active';
    public const DEPRECATED = 'deprecated';
    public const RETIRED    = 'retired';

    /**
     * MARKER-ADDON-VISIBILITY — what a shop sees.
     *
     * SELF_SERVE  visible, and they can switch it on.
     * ASK         visible, but the button starts a conversation instead. For
     *             anything that needs setup, a contract, or a chat about price.
     * HIDDEN      not shown. You enable it for them.
     */
    public const VIS_SELF_SERVE = 'self_serve';
    public const VIS_ASK        = 'ask';
    public const VIS_HIDDEN     = 'hidden';

    public const VISIBILITIES = [
        self::VIS_SELF_SERVE => 'Shops can add it themselves',
        self::VIS_ASK        => 'Shops can see it and ask about it',
        self::VIS_HIDDEN     => 'Shops cannot see it — you enable it',
    ];

    public function isVisibleToShops(): bool
    {
        return ($this->visibility ?? self::VIS_SELF_SERVE) !== self::VIS_HIDDEN;
    }

    public const STATUSES = [
        self::ACTIVE     => 'Active — anyone can add it',
        self::DEPRECATED => 'Closed — no new shops, existing keep it and are still billed',
        self::RETIRED    => 'Retired — off for everyone, existing activations stop',
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

    /** Closed to new shops, but still live and billed for those who have it. */
    public function isClosedToNew(): bool
    {
        return $this->status === self::DEPRECATED;
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
