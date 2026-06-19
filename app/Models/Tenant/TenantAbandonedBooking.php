<?php

namespace App\Models\Tenant;

use App\Models\Tenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * TenantAbandonedBooking — a follow-up worklist row for someone who started
 * booking, entered contact info, but didn't finish. One live row per session
 * (upserted as they progress). Cleared when the booking completes.
 */
class TenantAbandonedBooking extends Model
{
    use HasUuids;

    protected $table = 'tenant_abandoned_bookings';

    protected $fillable = [
        'tenant_id', 'session_id', 'name', 'email', 'phone',
        'step_reached', 'partial', 'status', 'notes', 'contacted_at',
    ];

    protected $casts = [
        'partial'      => 'array',
        'contacted_at' => 'datetime',
    ];

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }
}
