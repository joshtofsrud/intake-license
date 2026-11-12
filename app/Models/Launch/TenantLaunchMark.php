<?php

namespace App\Models\Launch;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/** MARKER-LAUNCH-CHECKLIST: a shop's tick or skip on a step, or Intake's waiver. One per step. */
class TenantLaunchMark extends Model
{
    use HasUuids;

    protected $table = 'tenant_launch_marks';
    protected $fillable = ['tenant_id', 'module', 'step_key', 'kind', 'reason', 'by_name'];
}
