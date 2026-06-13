<?php
// MARKER-PATCH-279

namespace App\Models\Tenant;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class TenantStaffAlertBroadcast extends Model
{
    use HasUuids;

    protected $table = 'tenant_staff_alert_broadcasts';

    protected $fillable = [
        'tenant_id', 'created_by', 'title', 'body', 'priority',
        'audience', 'show_banner', 'send_email', 'expires_at', 'is_active',
    ];

    protected $casts = [
        'audience'    => 'array',
        'show_banner' => 'boolean',
        'send_email'  => 'boolean',
        'is_active'   => 'boolean',
        'expires_at'  => 'datetime',
    ];

    public function dismissals(): HasMany
    {
        return $this->hasMany(TenantStaffBroadcastDismissal::class, 'broadcast_id');
    }
}
