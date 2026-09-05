<?php

namespace App\Services\Tenant;

use App\Models\Tenant\CatalogChangeBatch;
use App\Models\Tenant\CatalogChangeItem;
use App\Models\Tenant\TenantInventoryItem;
use Illuminate\Support\Facades\Auth;

/**
 * MARKER-CATALOG-HISTORY — puts a batch back.
 *
 * The rule is the importer's: anything CHANGED SINCE the batch is kept, not
 * overwritten, and the count is reported. Undo that silently discards newer
 * edits is worse than no undo at all, because nothing shows what it took.
 */
class CatalogChangeReverser
{
    public function __construct(private CatalogChangeBatch $batch) {}

    public function revert(?string $onlyItemId = null): array
    {
        $restored = 0;
        $kept     = 0;
        $keptItems = [];

        $rows = CatalogChangeItem::where('batch_id', $this->batch->id)
            ->whereNull('restored_at')
            ->when($onlyItemId, fn ($q) => $q->where('item_id', $onlyItemId))
            ->cursor();

        foreach ($rows as $row) {
            $item = TenantInventoryItem::find($row->item_id);
            if (! $item) {
                continue;   // deleted since; nothing to put back
            }

            if ($row->changedSince($item)) {
                $kept++;
                if (count($keptItems) < 20) {
                    $keptItems[] = $item->name;
                }
                continue;
            }

            foreach (($row->before ?? []) as $field => $value) {
                $item->{$field} = $value;
            }
            $item->save();

            $row->restored_at = now();
            $row->save();
            $restored++;
        }

        // A per-item put-back does not close the batch.
        if ($onlyItemId === null) {
            $this->batch->undone_at      = now();
            $this->batch->undone_by      = Auth::guard('tenant')->user()?->email;
            $this->batch->restored_count = $restored;
            $this->batch->kept_count     = $kept;
            $this->batch->save();
        }

        return ['restored' => $restored, 'kept' => $kept, 'keptItems' => $keptItems];
    }
}
