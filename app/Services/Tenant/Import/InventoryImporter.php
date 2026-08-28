<?php

namespace App\Services\Tenant\Import;

/**
 * MARKER-IMPORT2 — inventory import.
 *
 * Same contract as CustomerImporter: preview() and run() share buildRow(), so
 * the preview is the write path's own decision rather than an estimate.
 *
 * Three things are NOT plain column writes:
 *   category  — a name (optionally "Parent > Child") resolved to a record
 *   vendor    — a name resolved to a record and linked through the pivot
 *   stock     — a quantity turned into an audited movement at a location
 */

use App\Models\Tenant;
use App\Models\Tenant\TenantImport;
use App\Models\Tenant\TenantImportRow;
use App\Models\Tenant\TenantInventoryCategory;
use App\Models\Tenant\TenantInventoryItem;
use App\Models\Tenant\TenantLocation;
use App\Models\Tenant\TenantVendor;
use App\Services\Pos\InventoryService;
use App\Support\ImportFieldRegistry;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class InventoryImporter
{
    // MARKER-IMPORT-MERGE — conflict analysis for the merge review screen.
    use AnalysesConflicts;

    public const CHUNK = 100;

    /** Column names that are pseudo-fields, not item columns. */
    private const PSEUDO = ['category', 'vendor', 'stock', 'upc'];

    private array $categoryCache = [];
    private array $vendorCache   = [];

    public function __construct(private Tenant $tenant, private TenantImport $import) {}

    private function fields(): array
    {
        return ImportFieldRegistry::inventory();
    }

    private function option(string $key, $default = null)
    {
        return ($this->import->options ?? [])[$key] ?? $default;
    }

    private function mapping(): array
    {
        $out = [];
        foreach ((array) ($this->import->mapping ?? []) as $idx => $m) {
            $field = is_array($m) ? ($m['field'] ?? null) : $m;
            if ($field && isset($this->fields()[$field])) {
                $out[(int) $idx] = ['field' => $field, 'dir' => is_array($m) ? ($m['dir'] ?? null) : null];
            }
        }

        return $out;
    }

    /** Money arrives as "42.10", "$42.10", "1,299.00" — store cents. */
    private function money(string $raw): array
    {
        $clean = preg_replace('/[^0-9.\-]/', '', $raw);
        if ($clean === '' || ! is_numeric($clean)) {
            return [null, 'Amount "' . $raw . '" isn\'t a valid number'];
        }

        return [(int) round(((float) $clean) * 100), null];
    }

    private function cast(string $field, string $raw): array
    {
        $def = $this->fields()[$field];
        $raw = trim($raw);

        if ($raw === '') {
            return [null, null];
        }

        switch ($def['type']) {
            case 'money':
                return $this->money($raw);

            case 'int':
                $clean = preg_replace('/[^0-9\-]/', '', $raw);
                if ($clean === '' || ! is_numeric($clean)) {
                    return [null, $def['label'] . ' "' . $raw . '" isn\'t a number'];
                }
                return [(int) $clean, null];

            case 'bool':
                $t = strtolower($raw);
                if (in_array($t, ['1', 'true', 'yes', 'y', 't'], true)) { return [true, null]; }
                if (in_array($t, ['0', 'false', 'no', 'n', 'f'], true)) { return [false, null]; }
                return [null, $def['label'] . ' "' . $raw . '" isn\'t yes or no'];

            default:
                if (isset($def['max']) && mb_strlen($raw) > $def['max']) {
                    return [mb_substr($raw, 0, $def['max']), null];
                }
                return [$raw, null];
        }
    }

    /** "Parts > Brakes" → the Brakes record, creating the chain if needed. */
    public function resolveCategory(string $path, bool $create, ?array &$made = null): ?TenantInventoryCategory
    {
        $key = strtolower(trim($path));
        if ($key === '') { return null; }
        if (isset($this->categoryCache[$key])) { return $this->categoryCache[$key]; }

        $parts  = preg_split('/\s*(?:>|›|\/)\s*/', trim($path));
        $parent = null;

        foreach ($parts as $name) {
            $name = trim($name);
            if ($name === '') { continue; }

            $q = TenantInventoryCategory::where('tenant_id', $this->tenant->id)
                ->whereRaw('LOWER(name) = ?', [strtolower($name)]);
            $q = $parent ? $q->where('parent_id', $parent->id) : $q->whereNull('parent_id');

            $found = $q->first();

            if (! $found) {
                if (! $create) { return null; }
                $found = TenantInventoryCategory::create([
                    'tenant_id' => $this->tenant->id,
                    'parent_id' => $parent?->id,
                    'name'      => $name,
                    'slug'      => Str::slug($name) ?: Str::random(8),
                ]);
                if ($made !== null) { $made[] = ['category', $found->id, $name]; }
            }

            $parent = $found;
        }

        return $this->categoryCache[$key] = $parent;
    }

    public function resolveVendor(string $name, bool $create, ?array &$made = null): ?TenantVendor
    {
        $key = strtolower(trim($name));
        if ($key === '') { return null; }
        if (isset($this->vendorCache[$key])) { return $this->vendorCache[$key]; }

        $vendor = TenantVendor::where('tenant_id', $this->tenant->id)
            ->whereRaw('LOWER(name) = ?', [$key])->first();

        if (! $vendor) {
            if (! $create) { return null; }
            $vendor = TenantVendor::create([
                'tenant_id' => $this->tenant->id,
                'name'      => trim($name),
            ]);
            if ($made !== null) { $made[] = ['vendor', $vendor->id, trim($name)]; }
        }

        return $this->vendorCache[$key] = $vendor;
    }

    /** Decide what one row does. Writes nothing. */
    public function buildRow(array $cells, ?TenantInventoryItem $existing, ?int $line = null): array
    {
        $errors = []; $values = []; $dirs = []; $extra = [];

        foreach ($this->mapping() as $idx => $m) {
            $raw = (string) ($cells[$idx] ?? '');
            $f   = $m['field'];

            if ($f === 'category' || $f === 'vendor') {
                if (trim($raw) !== '') { $extra[$f] = trim($raw); }
                continue;
            }

            [$val, $err] = $this->cast($f, $raw);
            if ($err)          { $errors[] = $err; continue; }
            if ($val === null) { continue; }

            if ($f === 'stock' || $f === 'upc') { $extra[$f] = $val; continue; }

            $values[$f] = $val;
            $dirs[$f]   = $m['dir'] ?: $this->option('direction', 'csv');
            $dirs[$f]   = $this->rowDirection($f, $dirs[$f], $line);
        }

        $mode = $this->option('mode', 'upsert');

        if (empty($values['sku'])) {
            return ['outcome' => 'error', 'errors' => ['SKU is blank — required to identify the item'],
                    'values' => $values, 'extra' => $extra, 'match' => null, 'changes' => []];
        }
        if ($errors) {
            return ['outcome' => 'error', 'errors' => $errors, 'values' => $values,
                    'extra' => $extra, 'match' => $existing, 'changes' => []];
        }

        if (! $existing) {
            if ($mode === 'update') {
                return ['outcome' => 'unmatched', 'errors' => [], 'values' => $values,
                        'extra' => $extra, 'match' => null, 'changes' => []];
            }
            if (empty($values['name'])) {
                return ['outcome' => 'error', 'errors' => ['New items need a name'],
                        'values' => $values, 'extra' => $extra, 'match' => null, 'changes' => []];
            }
            return ['outcome' => 'create', 'errors' => [], 'values' => $values,
                    'extra' => $extra, 'match' => null, 'changes' => $values];
        }

        if ($mode === 'insert') {
            return ['outcome' => 'skipped', 'errors' => [], 'values' => $values,
                    'extra' => $extra, 'match' => $existing, 'changes' => []];
        }

        $changes = [];
        foreach ($values as $field => $val) {
            if ($field === 'sku') { continue; }
            $currentRaw = $existing->{$field};
            $current  = is_bool($currentRaw) ? $currentRaw : (string) ($currentRaw ?? '');
            $incoming = is_bool($val) ? $val : (string) $val;
            if ($current === $incoming) { continue; }

            $dir = $dirs[$field] ?? 'csv';
            if ($dir === 'keep') { continue; }
            if ($dir === 'blank' && ! ($currentRaw === null || $currentRaw === '')) { continue; }

            $changes[$field] = $val;
        }

        $touchesStock = array_key_exists('stock', $extra)
            && $this->option('stock_mode', 'set') !== 'leave';

        return [
            'outcome' => ($changes || $touchesStock || $extra) ? 'update' : 'unchanged',
            'errors'  => [], 'values' => $values, 'extra' => $extra,
            'match'   => $existing, 'changes' => $changes,
        ];
    }

    private function lookup(array $skus): array
    {
        $skus = array_values(array_filter(array_unique($skus)));
        if (! $skus) { return []; }

        return TenantInventoryItem::where('tenant_id', $this->tenant->id)
            ->whereIn('sku', $skus)->get()
            ->keyBy(fn ($i) => strtolower((string) $i->sku))->all();
    }

    private function skuIndex(): ?int
    {
        foreach ($this->mapping() as $idx => $m) {
            if ($m['field'] === 'sku') { return $idx; }
        }

        return null;
    }

    /** @return array{counts:array, sample:array, newCategories:array, newVendors:array} */
    public function preview(int $sampleLimit = 250): array
    {
        $csv = new CsvFile($this->import->stored_path, $this->import->delimiter, $this->import->encoding);

        $counts = ['create' => 0, 'update' => 0, 'unchanged' => 0,
                   'skipped' => 0, 'unmatched' => 0, 'error' => 0];
        $sample = []; $seen = []; $first = true;
        $newCats = []; $newVendors = [];
        $skuIdx  = $this->skuIndex();

        $batch = [];
        $flush = function () use (&$batch, &$counts, &$sample, $sampleLimit, &$newCats, &$newVendors) {
            if (! $batch) { return; }
            $existing = $this->lookup(array_map(fn ($b) => $b['key'], $batch));

            foreach ($batch as $b) {
                $row = $this->buildRow($b['cells'], $existing[$b['key']] ?? null, $b['line']);
                $counts[$row['outcome']] = ($counts[$row['outcome']] ?? 0) + 1;

                // Which categories/vendors WOULD be created — resolve read-only.
                if (! empty($row['extra']['category'])) {
                    $name = $row['extra']['category'];
                    if (! $this->resolveCategory($name, false)) { $newCats[$name] = true; }
                }
                if (! empty($row['extra']['vendor'])) {
                    $name = $row['extra']['vendor'];
                    if (! $this->resolveVendor($name, false)) { $newVendors[$name] = true; }
                }

                if (count($sample) < $sampleLimit) {
                    $sample[] = [
                        'line'    => $b['line'],
                        'outcome' => $row['outcome'],
                        'errors'  => $row['errors'],
                        'sku'     => $row['values']['sku'] ?? '—',
                        'name'    => $row['values']['name'] ?? ($row['match']->name ?? '—'),
                        'changes' => array_keys($row['changes']),
                        'stock'   => $row['extra']['stock'] ?? null,
                    ];
                }
            }
            $batch = [];
        };

        foreach ($csv->rows() as [$line, $cells]) {
            if ($this->import->has_header && $first) { $first = false; continue; }
            $first = false;

            $key = $skuIdx !== null ? strtolower(trim((string) ($cells[$skuIdx] ?? ''))) : '';

            if ($key !== '' && isset($seen[$key])) {
                $counts['error']++;
                if (count($sample) < $sampleLimit) {
                    $sample[] = ['line' => $line, 'outcome' => 'error',
                                 'errors' => ['SKU appears twice in this file (also line ' . $seen[$key] . ')'],
                                 'sku' => $key, 'name' => '—', 'changes' => [], 'stock' => null];
                }
                continue;
            }
            if ($key !== '') { $seen[$key] = $line; }

            $batch[] = ['line' => $line, 'cells' => $cells, 'key' => $key];
            if (count($batch) >= self::CHUNK) { $flush(); }
        }
        $flush();

        return ['counts' => $counts, 'sample' => $sample,
                'newCategories' => array_keys($newCats), 'newVendors' => array_keys($newVendors)];
    }

    public function run(): array
    {
        $csv = new CsvFile($this->import->stored_path, $this->import->delimiter, $this->import->encoding);
        $inventory = app(InventoryService::class);

        $location = $this->option('location_id')
            ? TenantLocation::where('tenant_id', $this->tenant->id)
                ->where('id', $this->option('location_id'))->first()
            : null;

        $createCats    = (bool) $this->option('create_categories', true);
        $createVendors = (bool) $this->option('create_vendors', true);
        $stockMode     = $this->option('stock_mode', 'set');
        $user          = auth('tenant')->user();

        $counts = ['created' => 0, 'updated' => 0, 'unchanged' => 0, 'skipped' => 0,
                   'unmatched' => 0, 'errors' => 0, 'categories' => 0, 'vendors' => 0, 'movements' => 0];
        $errorRows = []; $seen = []; $first = true;
        $skuIdx = $this->skuIndex();

        $batch = [];
        $flush = function () use (&$batch, &$counts, &$errorRows, $inventory, $location,
                                 $createCats, $createVendors, $stockMode, $user) {
            if (! $batch) { return; }
            $existing = $this->lookup(array_map(fn ($b) => $b['key'], $batch));

            DB::transaction(function () use ($batch, $existing, &$counts, &$errorRows, $inventory,
                                            $location, $createCats, $createVendors, $stockMode, $user) {
                foreach ($batch as $b) {
                    $row = $this->buildRow($b['cells'], $existing[$b['key']] ?? null, $b['line']);

                    if ($row['outcome'] === 'error') {
                        $counts['errors']++;
                        $errorRows[] = [$b['cells'], implode('; ', $row['errors'])];
                        continue;
                    }
                    if ($row['outcome'] === 'unmatched') {
                        $counts['unmatched']++;
                        $errorRows[] = [$b['cells'], 'No existing item matched this SKU'];
                        continue;
                    }
                    if (in_array($row['outcome'], ['skipped', 'unchanged'], true)) {
                        $counts[$row['outcome']]++;
                        continue;
                    }

                    $made = [];

                    // category / vendor resolution
                    $categoryId = null;
                    if (! empty($row['extra']['category'])) {
                        $cat = $this->resolveCategory($row['extra']['category'], $createCats, $made);
                        $categoryId = $cat?->id;
                    }

                    $item = $row['match'];

                    if ($row['outcome'] === 'create') {
                        $item = TenantInventoryItem::create(array_merge($row['values'], array_filter([
                            'tenant_id'   => $this->tenant->id,
                            'category_id' => $categoryId,
                        ])));
                        $counts['created']++;
                        TenantImportRow::create([
                            'import_id' => $this->import->id, 'tenant_id' => $this->tenant->id,
                            'action' => 'created', 'record_type' => 'item',
                            'record_id' => $item->id, 'created_at' => now(),
                        ]);
                    } else {
                        $changes = $row['changes'];
                        if ($categoryId && $categoryId !== $item->category_id) {
                            $changes['category_id'] = $categoryId;
                        }
                        if ($changes) {
                            $before = [];
                            foreach ($changes as $k => $v) { $before[$k] = $item->{$k}; }
                            $item->update($changes);
                            TenantImportRow::create([
                                'import_id' => $this->import->id, 'tenant_id' => $this->tenant->id,
                                'action' => 'updated', 'record_type' => 'item',
                                'record_id' => $item->id, 'before' => $before, 'created_at' => now(),
                            ]);
                        }
                        $counts['updated']++;
                    }

                    // vendor link through the pivot (no tenant_id — scope via item)
                    if (! empty($row['extra']['vendor'])) {
                        $vendor = $this->resolveVendor($row['extra']['vendor'], $createVendors, $made);
                        if ($vendor) {
                            $linked = DB::table('tenant_inventory_item_vendors')
                                ->where('inventory_item_id', $item->id)
                                ->where('vendor_id', $vendor->id)->exists();
                            if (! $linked) {
                                DB::table('tenant_inventory_item_vendors')->insert([
                                    'inventory_item_id' => $item->id,
                                    'vendor_id'         => $vendor->id,
                                    'unit_cost_cents'   => $row['values']['shop_cost_cents'] ?? null,
                                    'is_preferred'      => 0,
                                    'created_at'        => now(), 'updated_at' => now(),
                                ]);
                            }
                        }
                    }

                    foreach ($made as [$type, $id, $name]) {
                        $counts[$type === 'category' ? 'categories' : 'vendors']++;
                        TenantImportRow::create([
                            'import_id' => $this->import->id, 'tenant_id' => $this->tenant->id,
                            'action' => 'created', 'record_type' => $type,
                            'record_id' => $id, 'created_at' => now(),
                        ]);
                    }

                    // stock — always an audited movement, never a column poke
                    if ($location && array_key_exists('stock', $row['extra']) && $stockMode !== 'leave') {
                        $qty = (int) $row['extra']['stock'];
                        try {
                            $current = (int) $inventory->getCurrentStock($this->tenant, $item, $location);
                            $target  = $stockMode === 'add' ? $current + $qty : $qty;
                            $delta   = $target - $current;

                            if ($delta !== 0) {
                                $inventory->adjustStock(
                                    tenant: $this->tenant, item: $item, location: $location,
                                    newCount: $target, reason: 'Imported from ' . $this->import->original_filename,
                                    tenantUser: $user,
                                );
                                $counts['movements']++;
                                TenantImportRow::create([
                                    'import_id' => $this->import->id, 'tenant_id' => $this->tenant->id,
                                    'action' => 'updated', 'record_type' => 'item',
                                    'record_id' => $item->id, 'stock_delta' => $delta,
                                    'location_id' => $location->id, 'created_at' => now(),
                                ]);
                            }
                        } catch (\Throwable $e) {
                            $errorRows[] = [$b['cells'], 'Stock not set: ' . $e->getMessage()];
                        }
                    }
                }
            });

            $batch = [];
        };

        foreach ($csv->rows() as [$line, $cells]) {
            if ($this->import->has_header && $first) { $first = false; continue; }
            $first = false;

            $key = $skuIdx !== null ? strtolower(trim((string) ($cells[$skuIdx] ?? ''))) : '';

            if ($key !== '' && isset($seen[$key])) {
                $counts['errors']++;
                $errorRows[] = [$cells, 'Duplicate of line ' . $seen[$key] . ' in this file'];
                continue;
            }
            if ($key !== '') { $seen[$key] = $line; }

            $batch[] = ['line' => $line, 'cells' => $cells, 'key' => $key];
            if (count($batch) >= self::CHUNK) { $flush(); }
        }
        $flush();

        return ['counts' => $counts, 'errorRows' => $errorRows];
    }
}
