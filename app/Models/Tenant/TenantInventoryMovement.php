<?php

namespace App\Models\Tenant;

use App\Models\Tenant;
use BadMethodCallException;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Append-only audit log of inventory stock changes.
 *
 * SOURCE OF TRUTH for stock counts. The computed_stock_count caches on
 * tenant_inventory_items and tenant_inventory_item_locations are derived
 * from SUM(quantity_delta) on this table.
 *
 * NEVER UPDATED. NEVER DELETED. The model overrides update/delete to
 * throw, enforcing the append-only invariant at the application layer.
 * (The DB itself doesn't enforce this — it's a convention, but a strict one.)
 *
 * If you genuinely need to "fix" a movement (rare), write a compensating
 * movement of the opposite delta. Never modify history.
 */
class TenantInventoryMovement extends Model
{
    use HasUuids;

    protected $table = 'tenant_inventory_movements';

    public $timestamps = false; // only created_at exists, populated by useCurrent

    protected $fillable = [
        'tenant_id',
        'inventory_item_id',
        'location_id',
        'quantity_delta',
        'movement_type',
        'reference_type',
        'reference_id',
        'item_name_snapshot',
        'item_sku_snapshot',
        'cost_cents_at_time',
        'reason',
        'notes',
        'tenant_user_id',
    ];

    protected $casts = [
        'quantity_delta' => 'integer',
        'cost_cents_at_time' => 'integer',
        'created_at' => 'datetime',
    ];

    // ─── Append-only enforcement ───────────────────────────────────────

    public function update(array $attributes = [], array $options = [])
    {
        throw new BadMethodCallException(
            'TenantInventoryMovement is append-only. To correct a mistake, '
            . 'write a compensating movement with the opposite delta. '
            . 'Never modify history.'
        );
    }

    public function delete()
    {
        throw new BadMethodCallException(
            'TenantInventoryMovement is append-only. Movements cannot be deleted.'
        );
    }

    public function forceDelete()
    {
        throw new BadMethodCallException(
            'TenantInventoryMovement is append-only. Movements cannot be deleted.'
        );
    }

    // ─── Relationships ─────────────────────────────────────────────────

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(TenantInventoryItem::class, 'inventory_item_id');
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(TenantLocation::class, 'location_id');
    }

    public function tenantUser(): BelongsTo
    {
        return $this->belongsTo(TenantUser::class, 'tenant_user_id');
    }
}
