<?php

namespace App\Models\Tenant;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/** A repeating class: one class type, one time, on the chosen weekdays. MARKER-CLASSES-P7 */
class TenantClassSchedule extends Model
{
    use HasUuids;

    protected $table = 'tenant_class_schedules';

    protected $fillable = [
        'tenant_id', 'class_template_id', 'days', 'start_time', 'capacity',
        'instructor_resource_id', 'notes', 'starts_on', 'ends_on', 'skip_dates',
    ];

    protected $casts = [
        'days'       => 'array',
        'skip_dates' => 'array',
        'capacity'   => 'integer',
        'starts_on'  => 'date:Y-m-d',
        'ends_on'    => 'date:Y-m-d',
    ];

    public function template()
    {
        return $this->belongsTo(TenantClassTemplate::class, 'class_template_id');
    }

    public function sessions()
    {
        return $this->hasMany(TenantClassSession::class, 'schedule_id');
    }

    /** "Mon, Wed, Fri" — or "Every day". */
    public function daysLabel(): string
    {
        $d = collect($this->days ?? [])->map(fn ($x) => (int) $x)->sort()->values();
        if ($d->count() === 7) {
            return 'Every day';
        }
        $n = ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'];
        return $d->map(fn ($x) => $n[$x])->implode(', ');
    }

    public function timeLabel(): string
    {
        return \Carbon\Carbon::createFromFormat('H:i', $this->start_time)->format('g:i A');
    }
}
