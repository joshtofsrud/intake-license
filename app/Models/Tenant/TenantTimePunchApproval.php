<?php
// MARKER-PATCH-616 — per-person per-period approval (sign-off).

namespace App\Models\Tenant;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TenantTimePunchApproval extends Model
{
    use HasUuids;

    protected $table = 'tenant_time_punch_approvals';

    protected $fillable = [
        'tenant_id', 'pay_period_id', 'tenant_user_id',
        'approved_by', 'approved_at', 'minutes_at_approval',
    ];

    protected $casts = ['approved_at' => 'datetime'];

    public function approver(): BelongsTo
    {
        return $this->belongsTo(TenantUser::class, 'approved_by');
    }
}

