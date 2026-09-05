<?php

namespace App\Models\Tenant;

use Illuminate\Database\Eloquent\Model;

// MARKER-CATALOG-HISTORY — one item's before-and-after within a batch.
class CatalogChangeItem extends Model
{
    protected $table = 'catalog_change_items';

    protected $fillable = ['batch_id', 'tenant_id', 'item_id', 'before', 'after', 'restored_at'];

    protected $casts = [
        'before'      => 'array',
        'after'       => 'array',
        'restored_at' => 'datetime',
    ];

    /**
     * Has someone edited this by hand since the batch?
     *
     * If the current value is neither what we wrote nor what was there before,
     * a person has been at it, and putting the old value back would throw away
     * newer work. The importer makes the same call and calls it "used since".
     */
    public function changedSince($item): bool
    {
        foreach (($this->after ?? []) as $field => $written) {
            $current = $item->{$field} ?? null;
            if ($current != $written) {
                return true;
            }
        }
        return false;
    }
}
