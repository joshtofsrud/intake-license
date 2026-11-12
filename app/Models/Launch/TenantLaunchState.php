<?php

namespace App\Models\Launch;

use App\Models\Tenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** MARKER-LAUNCH-CHECKLIST: where one shop is with one module: preview, live or paused. */
class TenantLaunchState extends Model
{
    use HasUuids;

    protected $table = 'tenant_launch_states';
    protected $fillable = ['tenant_id', 'module', 'state', 'live_since', 'grandfathered', 'forced_live', 'forced_reason',
        'paused_at', 'pause_step', 'pause_reason', 'last', 'passed_at', 'broken', 'checked_at', 'reminders', 'reminded_at'];
    protected $casts = [
        'live_since' => 'datetime', 'paused_at' => 'datetime', 'checked_at' => 'datetime', 'reminded_at' => 'datetime',
        'grandfathered' => 'boolean', 'forced_live' => 'boolean',
        'last' => 'array', 'passed_at' => 'array', 'broken' => 'array', 'reminders' => 'integer',
    ];

    public function tenant(): BelongsTo { return $this->belongsTo(Tenant::class); }
}
