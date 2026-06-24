<?php
// MARKER-PATCH-152A

namespace App\Models\Tenant;

use App\Models\Tenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TenantDelivery extends Model
{
    use HasUuids;

    protected $table = 'tenant_deliveries';

    protected $fillable = [
        'tenant_id', 'type', 'status',
        'scheduled_at', 'window_minutes',
        'address',
        'customer_id', 'work_order_id', 'appointment_id', 'delivery_resource_id',
        'notes',
        'notified_at', 'notification_channels',
        'completed_at', 'cancelled_at',
        'reminded_at', // MARKER-PATCH-155
        'assets', // MARKER-PATCH-427 — snapshot of bikes on this run
    ];

    protected $casts = [
        'scheduled_at'   => 'datetime',
        'window_minutes' => 'integer',
        'notified_at'    => 'datetime',
        'completed_at'   => 'datetime',
        'cancelled_at'   => 'datetime',
        'reminded_at'    => 'datetime', // MARKER-PATCH-155
        'assets'         => 'array', // MARKER-PATCH-427
    ];

    public const TYPE_PICKUP  = 'pickup';
    public const TYPE_DROPOFF = 'dropoff';

    public const STATUS_SCHEDULED = 'scheduled';
    public const STATUS_COMPLETED = 'completed';
    public const STATUS_CANCELLED = 'cancelled';

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(TenantCustomer::class, 'customer_id');
    }

    public function deliveryResource(): BelongsTo
    {
        return $this->belongsTo(TenantDeliveryResource::class, 'delivery_resource_id');
    }

    public function isPickup(): bool  { return $this->type === self::TYPE_PICKUP; }
    public function isDropoff(): bool { return $this->type === self::TYPE_DROPOFF; }

    public function windowEndsAt()
    {
        return $this->scheduled_at?->copy()->addMinutes($this->window_minutes ?: 30);
    }
}