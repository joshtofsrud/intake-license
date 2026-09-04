<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

// MARKER-TASK-HEALTH — one execution of one scheduled command.
class ScheduledTaskRun extends Model
{
    protected $table = 'scheduled_task_runs';

    protected $fillable = [
        'command', 'started_at', 'finished_at', 'duration_ms',
        'ok', 'failure', 'manual', 'run_by',
    ];

    protected $casts = [
        'started_at'  => 'datetime',
        'finished_at' => 'datetime',
        'ok'          => 'boolean',
        'manual'      => 'boolean',
    ];

    public function duration(): string
    {
        if ($this->duration_ms === null) return '—';
        if ($this->duration_ms < 1000)   return $this->duration_ms . 'ms';
        return round($this->duration_ms / 1000, 1) . 's';
    }
}
