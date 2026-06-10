<?php
// MARKER-PATCH-217

namespace App\Models\Tenant;

use App\Models\Tenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Condition checklist template run at check-out and check-in.
 * items JSON: [{key, label}]. Differences between the two phases create a
 * damage note on the rental and authorize a deposit capture.
 */
class TenantRentalConditionTemplate extends Model
{
    use HasUuids;

    protected $table = 'tenant_rental_condition_templates';

    protected $fillable = ['tenant_id', 'name', 'items'];

    protected $casts = ['items' => 'array'];

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }
}
