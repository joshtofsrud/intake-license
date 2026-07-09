<?php
// MARKER-PATCH-612 — time-off request. Day boundaries tenant-local, stored UTC.

namespace App\Models\Tenant;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TenantTimeOffRequest extends Model
{
    use HasUuids;

    protected $table = 'tenant_time_off_requests';

    protected $fillable = [
        'tenant_id', 'tenant_user_id', 'starts_at', 'ends_at', 'all_day',
        'type', 'reason', 'status', 'reviewed_by', 'reviewed_at', 'review_note',
    ];

    protected $casts = [
        'starts_at'   => 'datetime',
        'ends_at'     => 'datetime',
        'all_day'     => 'boolean',
        'reviewed_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(TenantUser::class, 'tenant_user_id');
    }

    public function isApproved(): bool
    {
        return $this->status === 'approved';
    }
}

