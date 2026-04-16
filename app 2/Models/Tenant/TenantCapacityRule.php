<?php
namespace App\Models\Tenant;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class TenantCapacityRule extends Model
{
    use HasUuids;

    protected $table = 'tenant_capacity_rules';

    /**
     * Schema lives in two migrations:
     *   - 2024_01_02_000005_create_tenant_capacity_tables.php
     *   - 2024_01_03_000001_add_time_architecture.php  (adds open_time, close_time, slot_interval_minutes)
     *
     * rule_type values:
     *   'default'  — recurring weekly rule (day_of_week set, specific_date null)
     *   'override' — one-off date rule    (specific_date set, day_of_week null)
     */
    protected $fillable = [
        'tenant_id',
        'rule_type',
        'day_of_week',
        'specific_date',
        'max_appointments',
        'note',
        'open_time',
        'close_time',
        'slot_interval_minutes',
    ];

    protected $casts = [
        'specific_date'         => 'date',
        'max_appointments'      => 'integer',
        'slot_interval_minutes' => 'integer',
    ];
}
