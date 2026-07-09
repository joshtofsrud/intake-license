<?php
// MARKER-PATCH-612 — recurring day-of-week availability band.

namespace App\Models\Tenant;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class TenantAvailability extends Model
{
    use HasUuids;

    protected $table = 'tenant_availability';

    protected $fillable = [
        'tenant_id', 'tenant_user_id', 'day_of_week', 'band', 'preference',
    ];
}

