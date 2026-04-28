<?php
namespace App\Models\Tenant;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * Pivot rows for the service→resource eligibility table.
 * Empty for a service = ALL active resources eligible (the natural default).
 */
class TenantServiceResourceEligibility extends Model
{
    use HasUuids;

    protected $table = 'tenant_service_resource_eligibility';
    protected $fillable = ['tenant_id', 'service_item_id', 'resource_id'];
}
