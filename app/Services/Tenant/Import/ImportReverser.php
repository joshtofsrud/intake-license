<?php

namespace App\Services\Tenant\Import;

/**
 * MARKER-IMPORT2 — undo an import.
 *
 * Created rows are deleted, updated fields restored, stock reversed with a
 * counter-movement. History is never deleted: a stock movement is corrected by
 * another movement, so the ledger still shows what happened and why.
 *
 * SAFETY: a created record that something now REFERENCES is kept, not deleted,
 * and reported with the reason. An item on a sale line, a transfer, an
 * appointment or a special order — or a customer with any activity — has left
 * the import's blast radius, and deleting it would corrupt real history far
 * worse than a bad import ever could.
 */

use App\Models\Tenant;
use App\Models\Tenant\TenantImport;
use App\Models\Tenant\TenantImportRow;
use App\Models\Tenant\TenantInventoryItem;
use App\Models\Tenant\TenantLocation;
use App\Services\Pos\InventoryService;
use Illuminate\Support\Facades\DB;

class ImportReverser
{
    public function __construct(private Tenant $tenant, private TenantImport $import) {}

    /** Tables that make a created record un-deletable, as [table, column]. */
    private const ITEM_REFS = [
        ['tenant_sale_items', 'inventory_item_id'],
        ['tenant_inventory_movements', 'inventory_item_id'],
        ['tenant_special_orders', 'inventory_item_id'],
        ['tenant_transfer_requests', 'inventory_item_id'],
        ['tenant_appointment_parts', 'inventory_item_id'],
    ];

    private const CUSTOMER_REFS = [
        ['tenant_sales', 'customer_id'],
        ['tenant_appointments', 'customer_id'],
        ['tenant_orders', 'customer_id'],
        ['tenant_special_orders', 'customer_id'],
        ['tenant_rentals', 'customer_id'],
    ];

    /** @return string|null reason it must be kept, or null if safe to delete */
    private function blockedBy(string $type, string $id, ?int $ownMovements = 0): ?string
    {
        $refs = $type === 'item' ? self::ITEM_REFS : ($type === 'customer' ? self::CUSTOMER_REFS : []);

        foreach ($refs as [$table, $column]) {
            if (! \Schema::hasTable($table) || ! \Schema::hasColumn($table, $column)) {
                continue;
            }

            $count = DB::table($table)->where($column, $id)->count();

            // The import's OWN stock movements don't count as outside use.
            if ($table === 'tenant_inventory_movements') {
                $count -= (int) $ownMovements;
            }

            if ($count > 0) {
                return 'Used by ' . str_replace(['tenant_', '_'], ['', ' '], $table);
            }
        }

        return null;
    }

    public function reverse(): array
    {
        $result = ['deleted' => 0, 'restored' => 0, 'stock_reversed' => 0, 'kept' => 0, 'keptDetail' => []];

        $inventory = app(InventoryService::class);
        $user      = auth('tenant')->user();

        $rows = TenantImportRow::where('import_id', $this->import->id)
            ->where('tenant_id', $this->tenant->id)
            ->whereNull('reversed_at')
            ->orderByDesc('id')      // newest first: undo in reverse order
            ->get();

        // 1) stock first — a counter-movement needs the item to still exist
        foreach ($rows->where('stock_delta', '!=', null) as $row) {
            $item = TenantInventoryItem::where('tenant_id', $this->tenant->id)
                ->where('id', $row->record_id)->first();
            $loc  = $row->location_id
                ? TenantLocation::where('tenant_id', $this->tenant->id)->where('id', $row->location_id)->first()
                : null;

            if (! $item || ! $loc) { continue; }

            try {
                $current = (int) $inventory->getCurrentStock($this->tenant, $item, $loc);
                $inventory->adjustStock(
                    tenant: $this->tenant, item: $item, location: $loc,
                    newCount: max(0, $current - (int) $row->stock_delta),
                    reason: 'Reversed import ' . $this->import->original_filename,
                    tenantUser: $user,
                );
                $result['stock_reversed']++;
                $row->update(['reversed_at' => now()]);
            } catch (\Throwable $e) {
                $row->update(['kept_reason' => 'Stock not reversed: ' . $e->getMessage()]);
            }
        }

        // 2) restore updated fields
        foreach ($rows->where('action', 'updated')->whereNull('stock_delta') as $row) {
            $model = $this->modelFor($row->record_type, $row->record_id);
            if (! $model) { continue; }

            $before = (array) ($row->before ?? []);
            if ($before) {
                $model->update($before);
                $result['restored']++;
            }
            $row->update(['reversed_at' => now()]);
        }

        // 3) delete created records, unless something now points at them
        foreach ($rows->where('action', 'created') as $row) {
            $ownMovements = TenantImportRow::where('import_id', $this->import->id)
                ->where('record_id', $row->record_id)
                ->whereNotNull('stock_delta')->count();

            $blocked = $this->blockedBy($row->record_type, $row->record_id, $ownMovements);

            if ($blocked) {
                $result['kept']++;
                $result['keptDetail'][] = ['type' => $row->record_type, 'id' => $row->record_id, 'why' => $blocked];
                $row->update(['kept_reason' => $blocked]);
                continue;
            }

            $model = $this->modelFor($row->record_type, $row->record_id);
            if ($model) {
                if ($row->record_type === 'item') {
                    DB::table('tenant_inventory_item_vendors')->where('inventory_item_id', $model->id)->delete();
                }
                try {
                    $model->delete();
                    $result['deleted']++;
                } catch (\Throwable $e) {
                    $result['kept']++;
                    $result['keptDetail'][] = ['type' => $row->record_type, 'id' => $row->record_id,
                                               'why' => 'Could not be deleted'];
                    $row->update(['kept_reason' => 'Could not be deleted']);
                    continue;
                }
            }
            $row->update(['reversed_at' => now()]);
        }

        return $result;
    }

    private function modelFor(string $type, string $id)
    {
        $class = match ($type) {
            'item'     => \App\Models\Tenant\TenantInventoryItem::class,
            'customer' => \App\Models\Tenant\TenantCustomer::class,
            'category' => \App\Models\Tenant\TenantInventoryCategory::class,
            'vendor'   => \App\Models\Tenant\TenantVendor::class,
            default    => null,
        };

        return $class
            ? $class::where('tenant_id', $this->tenant->id)->where('id', $id)->first()
            : null;
    }
}
