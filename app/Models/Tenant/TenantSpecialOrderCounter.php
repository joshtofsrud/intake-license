<?php

namespace App\Models\Tenant;

use App\Models\Tenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Per-tenant counter for SO numbering. Mirrors TenantSaleCounter.
 *
 * Service layer (Stage 3) owns the atomic increment logic. This model
 * just provides Eloquent access to the row.
 *
 * Primary key is tenant_id (one counter per tenant); no surrogate UUID
 * because there's exactly one row per tenant and tenant_id is the
 * natural key.
 */
class TenantSpecialOrderCounter extends Model
{
    protected $table = 'tenant_special_order_counters';

    protected $primaryKey = 'tenant_id';
    public $incrementing = false;
    protected $keyType = 'string';

    protected $fillable = [
        'tenant_id',
        'next_number',
    ];

    protected $casts = [
        'next_number' => 'integer',
    ];

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }
}
