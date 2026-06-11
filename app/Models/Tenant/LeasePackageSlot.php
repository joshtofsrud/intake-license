<?php
// MARKER-PATCH-229

namespace App\Models\Tenant;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A slot in a lease package: quantity x category, optionally filtered by
 * size. "1 x Skis, 100-140cm." Constrains what fills the slot at the
 * counter; the actual unit is pulled live from the fleet (patch 230).
 */
class LeasePackageSlot extends Model
{
    use HasUuids;

    protected $table = 'lease_package_slots';

    protected $fillable = [
        'tenant_id', 'package_id', 'category_id',
        'size_filter', 'quantity', 'sort_order',
    ];

    protected $casts = [
        'quantity'   => 'integer',
        'sort_order' => 'integer',
    ];

    public function package(): BelongsTo
    {
        return $this->belongsTo(LeasePackage::class, 'package_id');
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(TenantRentalCategory::class, 'category_id');
    }
}
