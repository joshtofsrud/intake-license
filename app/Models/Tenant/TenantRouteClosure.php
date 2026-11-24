<?php
namespace App\Models\Tenant;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

// A closed date for route windows: the whole day (window_id null) or one window on that date.
class TenantRouteClosure extends Model
{
    use HasUuids;

    protected $table = 'tenant_route_closures';
    protected $fillable = ['tenant_id', 'date', 'window_id'];
}
