<?php
// MARKER-DUP-MERGE

namespace App\Models\Tenant;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * A set of inventory items that are the same product (shared barcode, or the
 * same brand + part number with no barcode). Found by DuplicateItemFinder,
 * resolved on Inventory › Duplicates. "dismissed" and "merged" rows are kept
 * so a finder run never brings back a decision someone already made.
 */
class TenantDuplicateGroup extends Model
{
    use HasUuids;

    protected $table = 'tenant_duplicate_groups';

    protected $guarded = [];

    protected $casts = [
        'reasons'     => 'array',
        'item_ids'    => 'array',
        'preview'     => 'array',
        'found_at'    => 'datetime',
        'resolved_at' => 'datetime',
    ];

    public const OPEN = ['ready', 'review', 'failed'];

    public static function openCount(string $tenantId): int
    {
        return static::where('tenant_id', $tenantId)->whereIn('status', self::OPEN)->count();
    }
}
