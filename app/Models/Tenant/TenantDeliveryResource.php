<?php
// MARKER-PATCH-152A

namespace App\Models\Tenant;

use App\Models\Tenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class TenantDeliveryResource extends Model
{
    use HasUuids;

    protected $table = 'tenant_delivery_resources';

    protected $fillable = [
        'tenant_id', 'name', 'subtitle', 'color_hex',
        'sort_order', 'is_active',
    ];

    protected $casts = [
        'is_active'  => 'boolean',
        'sort_order' => 'integer',
    ];

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function deliveries(): HasMany
    {
        return $this->hasMany(TenantDelivery::class, 'delivery_resource_id');
    }
}