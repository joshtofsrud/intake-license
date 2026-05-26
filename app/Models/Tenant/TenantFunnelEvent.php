<?php
// MARKER-PATCH-149

namespace App\Models\Tenant;

use App\Models\Tenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * TenantFunnelEvent — one row per tracked customer-facing event.
 *
 * Anonymous by design. We track sessions, not people. No IP storage,
 * no fingerprinting, no third-party trackers.
 *
 * Retention: 90 days, pruned daily (patch 151).
 */
class TenantFunnelEvent extends Model
{
    protected $table = 'tenant_funnel_events';

    public $timestamps = false;   // we only have created_at

    protected $fillable = [
        'tenant_id',
        'session_id',
        'event_type',
        'path',
        'referrer_domain',
        'referrer_url',
        'utm_source',
        'utm_medium',
        'utm_campaign',
        'device',
        'is_new_session',
        'created_at',
    ];

    protected $casts = [
        'created_at'     => 'datetime',
        'is_new_session' => 'boolean',
    ];

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public const TYPE_PAGE_VIEW           = 'page_view';
    public const TYPE_BOOKING_PAGE_VIEWED = 'booking_page_viewed';
    public const TYPE_BOOKING_STARTED     = 'booking_started';
    public const TYPE_BOOKING_COMPLETED   = 'booking_completed';

    public const VALID_TYPES = [
        self::TYPE_PAGE_VIEW,
        self::TYPE_BOOKING_PAGE_VIEWED,
        self::TYPE_BOOKING_STARTED,
        self::TYPE_BOOKING_COMPLETED,
    ];
}
