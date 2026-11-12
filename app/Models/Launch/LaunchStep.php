<?php

namespace App\Models\Launch;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/** MARKER-LAUNCH-CHECKLIST: one step of a module's checklist. */
class LaunchStep extends Model
{
    use HasUuids;

    protected $table = 'launch_steps';
    protected $fillable = ['module', 'key', 'check', 'group_name', 'title', 'explain', 'how', 'level', 'pause_on_fail', 'help_key', 'min_tier', 'enabled', 'sort'];
    protected $casts = ['pause_on_fail' => 'boolean', 'enabled' => 'boolean', 'sort' => 'integer'];

    public const LEVELS = ['req' => 'Required', 'rec' => 'Recommended', 'opt' => 'Optional'];
    public const TIERS  = ['starter' => 'All plans', 'branded' => 'Branded and up', 'scale' => 'Scale only'];

    public function isConfirm(): bool { return $this->check === 'confirm'; }
}
