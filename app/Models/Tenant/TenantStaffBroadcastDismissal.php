<?php
// MARKER-PATCH-279

namespace App\Models\Tenant;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class TenantStaffBroadcastDismissal extends Model
{
    use HasUuids;

    protected $table = 'tenant_staff_broadcast_dismissals';
    public $timestamps = false;

    protected $fillable = ['tenant_id', 'broadcast_id', 'user_id', 'dismissed_at'];

    protected $casts = ['dismissed_at' => 'datetime'];
}
