<?php
namespace App\Models\Tenant;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Relations\Pivot;

/**
 * Pivot rows for the service→resource eligibility table.
 * Empty for a service = ALL active resources eligible (the natural default).
 *
 * Extends Pivot so it can be used via belongsToMany()->using() — that path
 * is what makes HasUuids fire on sync()/attach() inserts. As a plain Model
 * the pivot inserts go through Laravel\'s internal pivot machinery and skip
 * model events entirely.
 */
class TenantServiceResourceEligibility extends Pivot
{
    use HasUuids;

    protected $table = 'tenant_service_resource_eligibility';
    public $incrementing = false;
    protected $keyType = 'string';
    protected $fillable = ['tenant_id', 'service_item_id', 'resource_id'];
}
