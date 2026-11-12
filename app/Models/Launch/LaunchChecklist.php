<?php

namespace App\Models\Launch;

use App\Support\Launch\LaunchDefaults;
use Illuminate\Database\Eloquent\Model;

/** MARKER-LAUNCH-CHECKLIST: how one module's checklist behaves. Edited in master admin. */
class LaunchChecklist extends Model
{
    protected $table = 'launch_checklists';
    protected $primaryKey = 'module';
    public $incrementing = false;
    protected $keyType = 'string';
    protected $fillable = ['module', 'settings'];
    protected $casts = ['settings' => 'array'];

    /** The behaviour for a module, with defaults filled in for anything missing. */
    public static function behaviour(string $module): array
    {
        $defaults = LaunchDefaults::behaviours()[$module] ?? LaunchDefaults::behaviours()['ecom'];
        try {
            $row = static::find($module);
        } catch (\Throwable $e) {
            $row = null;
        }
        return array_merge($defaults, (array) ($row?->settings ?? []));
    }
}
