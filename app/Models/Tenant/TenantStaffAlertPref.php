<?php
// MARKER-PATCH-225

namespace App\Models\Tenant;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class TenantStaffAlertPref extends Model
{
    use HasUuids;

    protected $table = 'tenant_staff_alert_prefs';

    protected $fillable = ['tenant_id', 'user_id', 'event', 'in_app', 'sms'];

    protected $casts = [
        'in_app' => 'boolean',
        'sms'    => 'boolean',
    ];
}
