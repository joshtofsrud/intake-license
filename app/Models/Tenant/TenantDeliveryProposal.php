<?php
// MARKER-PATCH-527

namespace App\Models\Tenant;

use App\Models\Tenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TenantDeliveryProposal extends Model
{
    use HasUuids;

    protected $table = 'tenant_delivery_proposals';

    protected $fillable = [
        'tenant_id', 'appointment_id', 'customer_id', 'token',
        'windows', 'status', 'confirmed_window_id', 'confirmed_date',
        'delivery_id', 'expires_at', 'confirmed_at', 'sent_channels',
    ];

    protected $casts = [
        'windows'        => 'array',
        'confirmed_date' => 'date',
        'expires_at'     => 'datetime',
        'confirmed_at'   => 'datetime',
    ];

    public const STATUS_PENDING   = 'pending';
    public const STATUS_CONFIRMED = 'confirmed';
    public const STATUS_ASSUMED   = 'assumed';
    public const STATUS_EXPIRED   = 'expired';
    public const STATUS_CANCELLED = 'cancelled';

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(TenantCustomer::class, 'customer_id');
    }

    public function appointment(): BelongsTo
    {
        return $this->belongsTo(TenantAppointment::class, 'appointment_id');
    }

    public function delivery(): BelongsTo
    {
        return $this->belongsTo(TenantDelivery::class, 'delivery_id');
    }

    public function isPending(): bool
    {
        return $this->status === self::STATUS_PENDING;
    }
}
