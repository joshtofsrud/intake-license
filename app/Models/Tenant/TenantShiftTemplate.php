<?php
// MARKER-PATCH-624 — reusable week pattern for the schedule builder.
// pattern = [{day_offset, start:"HH:MM", end:"HH:MM", user_id, label}, ...]
// (tenant-local wall times, resolved to UTC at apply time).

namespace App\Models\Tenant;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class TenantShiftTemplate extends Model
{
    use HasUuids;

    protected $table = 'tenant_shift_templates';
    protected $fillable = ['tenant_id', 'name', 'pattern', 'created_by'];
    protected $casts = ['pattern' => 'array'];
}

