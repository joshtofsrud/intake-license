<?php

namespace App\Models\Tenant;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Internal note on a special order. Append-only thread.
 *
 * Two flavors:
 *   - User-authored: tenant_user_id set, is_system = false.
 *   - System-authored: tenant_user_id null, is_system = true. Used for
 *     auto-arrival entries, status transitions logged by the service
 *     layer, etc.
 */
class TenantSpecialOrderNote extends Model
{
    use HasUuids, SoftDeletes;

    protected $table = 'tenant_special_order_notes';

    protected $fillable = [
        'special_order_id',
        'tenant_user_id',
        'is_system',
        'body',
    ];

    protected $casts = [
        'is_system' => 'boolean',
    ];

    public function specialOrder(): BelongsTo
    {
        return $this->belongsTo(TenantSpecialOrder::class, 'special_order_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(TenantUser::class, 'tenant_user_id');
    }
}
