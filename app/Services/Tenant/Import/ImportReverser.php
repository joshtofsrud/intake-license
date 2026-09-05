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

    /**
     * MARKER-IMPORT-PROGRESS — usable ref tables, resolved ONCE per run.
     * These were five Schema::hasTable + five Schema::hasColumn probes PER
     * ROW, each one hitting information_schema.
     */
    private array $refCache = [];

    private function refsFor(string $type): array
    {
        if (isset($this->refCache[$type])) return $this->refCache[$type];

        $refs = $type === 'item' ? self::ITEM_REFS : ($type === 'customer' ? self::CUSTOMER_REFS : []);
        $out  = [];
        foreach ($refs as [$table, $column]) {
            if (\Schema::hasTable($table) && \Schema::hasColumn($table, $column)) {
                $out[] = [$table, $column];
            }
        }
        return $this->refCache[$type] = $out;
    }

    /**
     * MARKER-IMPORT-PROGRESS — one grouped query per ref table for a whole
     * chunk of ids, instead of a COUNT per row per table.
     *
     * @param  array<string,int>  $ownMovements  record_id => movements this import made
     * @return array<string,string>  record_id => reason it must be kept
     */
    private function blockedForBatch(string $type, array $ids, array $ownMovements = []): array
    {
        $ids = array_values(array_unique(array_filter($ids)));
        if (! $ids) return [];

        $blocked = [];
        foreach ($this->refsFor($type) as [$table, $column]) {
            $counts = DB::table($table)->whereIn($column, $ids)
                ->selectRaw("{$column} as rid, COUNT(*) as n")
                ->groupBy($column)->pluck('n', 'rid');

            foreach ($counts as $rid => $n) {
                $n = (int) $n;
                // The import's OWN stock movements don't count as outside use.
                if ($table === 'tenant_inventory_movements') {
                    $n -= (int) ($ownMovements[$rid] ?? 0);
                }
                if ($n > 0 && ! isset($blocked[$rid])) {
                    $blocked[$rid] = 'Used by ' . str_replace(['tenant_', '_'], ['', ' '], $table);
                }
            }
        }

        return $blocked;
    }

    /** Single-id convenience, kept for any caller outside the batch path. */
    private function blockedBy(string $type, string $id, ?int $ownMovements = 0): ?string
    {
        return $this->blockedForBatch($type, [$id], [$id => (int) $ownMovements])[$id] ?? null;
    }

    /** MARKER-IMPORT-PROGRESS — per-chunk progress callback, set by the job. */
    public $onProgress = null;

    private function tick(int $done): void
    {
        if (is_callable($this->onProgress)) {
            ($this->onProgress)($done);
        }
    }

    public function reverse(): array
    {
        $result = ['deleted' => 0, 'restored' => 0, 'stock_reversed' => 0, 'kept' => 0, 'keptDetail' => []];

        $inventory = app(InventoryService::class);
        $user      = auth('tenant')->user();

        // MARKER-IMPORT-PROGRESS — the whole ledger used to load at once.
        // Chunked so memory is flat whatever the file size, and so progress
        // can be reported as it goes.
        $base = TenantImportRow::where('import_id', $this->import->id)
            ->where('tenant_id', $this->tenant->id)
            ->whereNull('reversed_at');

        $total = (clone $base)->count();
        $seen  = 0;
        $this->import->update(['progress_done' => 0, 'progress_total' => $total, 'progress_stage' => 'reversing']);

        (clone $base)->orderByDesc('id')->chunkById(200, function ($rows) use (&$result, &$seen, $inventory, $user) {
            $this->reverseChunk($rows, $result, $inventory, $user);
            $seen += $rows->count();
            $this->import->update(['progress_done' => $seen]);
            $this->tick($seen);
        }, 'id', 'id');

        return $result;
    }

    /**
     * MARKER-IMPORT-PROGRESS — the original body, now per chunk. Reference
     * checks for the whole chunk are resolved up front in one pass.
     */
    private function reverseChunk($rows, array &$result, $inventory, $user): void
    {

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
        // MARKER-IMPORT-PROGRESS — batch the "is this still referenced?"
        // question for every created row in the chunk, before touching any.
        $createdRows = $rows->where('action', 'created');
        $createdIds  = $createdRows->pluck('record_id')->all();

        // This import's own stock movements, counted across the WHOLE import
        // (not just this chunk — a record's stock rows can land in another
        // chunk), in one grouped query instead of one COUNT per row.
        $ownMoves = $createdIds
            ? TenantImportRow::where('import_id', $this->import->id)
                ->whereIn('record_id', $createdIds)->whereNotNull('stock_delta')
                ->selectRaw('record_id, COUNT(*) as n')->groupBy('record_id')
                ->pluck('n', 'record_id')->map(fn ($n) => (int) $n)->all()
            : [];

        $blockedItems = $this->blockedForBatch('item', $createdRows->where('record_type', 'item')->pluck('record_id')->all(), $ownMoves);
        $blockedCusts = $this->blockedForBatch('customer', $createdRows->where('record_type', 'customer')->pluck('record_id')->all());

        foreach ($createdRows as $row) {
            // MARKER-IMPORT-PROGRESS — both answers were computed for the
            // whole chunk above; this was a COUNT plus fifteen queries per row.
            $blocked = $row->record_type === 'item'
                ? ($blockedItems[$row->record_id] ?? null)
                : ($blockedCusts[$row->record_id] ?? null);

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
