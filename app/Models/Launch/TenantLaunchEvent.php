<?php

namespace App\Models\Launch;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/** MARKER-LAUNCH-CHECKLIST: one line of a shop's checklist history. */
class TenantLaunchEvent extends Model
{
    use HasUuids;

    public const UPDATED_AT = null;
    protected $table = 'tenant_launch_events';
    protected $fillable = ['tenant_id', 'module', 'message', 'created_at'];
    protected $casts = ['created_at' => 'datetime'];
}
