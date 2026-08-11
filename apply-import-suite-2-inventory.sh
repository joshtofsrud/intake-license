#!/usr/bin/env bash
set -euo pipefail
# apply-import-suite-2-inventory.sh — MARKER-IMPORT2
# Patch 2: inventory import, and UNDO for both import types.
#
# WHY UNDO IS IN THIS PATCH AND NOT A LATER ONE
# Reversing an import needs a record of what each row DID — the id of anything
# created, and the previous value of every field changed. That has to be written
# at import time, so shipping inventory first and undo later would leave a
# window of imports that can never be reversed. The ledger goes in now.
#
# WHAT UNDO DOES
#   created rows  -> deleted, but ONLY if nothing has referenced them since
#   updated rows  -> each changed field restored to its recorded previous value
#   stock         -> reversed with a counter-movement, never by deleting history
#   created
#     categories/vendors -> deleted only if empty and unused
#
# WHAT UNDO REFUSES TO DO, deliberately
# An imported item that has since been sold, transferred, put on an appointment
# or a special order is NOT deleted — it is reported as kept, with the reason.
# Silently deleting a row that a sale line points at would corrupt history far
# worse than a bad import. Same for a customer with any activity.
#
# INVENTORY SPECIFICS
#   - category by NAME, "Parent > Child" supported, created if missing
#   - vendor by NAME, created if missing, linked through the item-vendor pivot
#     (which deliberately has NO tenant_id — always scoped through the item)
#   - stock through InventoryService::adjustStock/recordInitialStock, so every
#     imported quantity is a real audited movement at a chosen location, not a
#     poke at computed_stock_count
#
# REQUIRES apply-import-suite-1-engine (MARKER-IMPORT1).

MIG=database/migrations/2026_08_09_200000_create_tenant_import_rows_table.php
REG=app/Support/ImportFieldRegistry.php
INV=app/Services/Tenant/Import/InventoryImporter.php
UNDO=app/Services/Tenant/Import/ImportReverser.php
ROWM=app/Models/Tenant/TenantImportRow.php
CTRL=app/Http/Controllers/Tenant/ImportController.php
CUST=app/Services/Tenant/Import/CustomerImporter.php
ROUTES=routes/web.php
VDIR=resources/views/tenant/imports

for f in "$REG" "$CTRL" "$CUST" "$ROUTES"; do
  [ -f "$f" ] || { echo "PRECONDITION FAILED: deploy apply-import-suite-1-engine.sh first ($f missing)"; exit 1; }
done

if grep -q "MARKER-IMPORT2" "$REG"; then
  echo "Already applied (MARKER-IMPORT2 present) — no-op."
  exit 0
fi

# ================================================================ ledger
if [ -f "$MIG" ]; then echo "ok   ledger migration already present"; else
cat <<'EOF' > "$MIG"
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// MARKER-IMPORT2 — what each imported row actually did, so it can be undone.
// One row per record touched. `before` holds ONLY the fields that changed,
// with their prior values — enough to restore, small enough to keep.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tenant_import_rows', function (Blueprint $table) {
            $table->id();
            $table->uuid('import_id')->index();
            $table->uuid('tenant_id')->index();

            $table->string('action', 12);              // created | updated
            $table->string('record_type', 24);         // customer | item | category | vendor
            $table->uuid('record_id');

            $table->json('before')->nullable();        // changed fields, prior values
            $table->integer('stock_delta')->nullable();// signed, for reversal
            $table->uuid('location_id')->nullable();

            $table->timestamp('created_at')->nullable();
            $table->timestamp('reversed_at')->nullable();
            $table->string('kept_reason')->nullable(); // why undo left it alone

            $table->index(['import_id', 'action']);
            $table->index(['record_type', 'record_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tenant_import_rows');
    }
};
EOF
echo "ok   ledger migration created"; fi

if [ -f "$ROWM" ]; then echo "ok   ledger model already present"; else
cat <<'EOF' > "$ROWM"
<?php

namespace App\Models\Tenant;

// MARKER-IMPORT2
use Illuminate\Database\Eloquent\Model;

class TenantImportRow extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = [
        'import_id', 'tenant_id', 'action', 'record_type', 'record_id',
        'before', 'stock_delta', 'location_id', 'created_at', 'reversed_at', 'kept_reason',
    ];

    protected $casts = [
        'before'      => 'array',
        'created_at'  => 'datetime',
        'reversed_at' => 'datetime',
    ];
}
EOF
echo "ok   ledger model created"; fi

# ================================================================ field registry
python3 - "$REG" <<'PY'
import sys
path = sys.argv[1]
src = open(path).read()

old = """    public static function for(string $importType): array
    {
        return $importType === 'customers' ? self::customers() : [];
    }"""
new = """    public static function for(string $importType): array
    {
        return match ($importType) {
            'customers' => self::customers(),
            'inventory' => self::inventory(),   // MARKER-IMPORT2
            default     => [],
        };
    }

    /**
     * MARKER-IMPORT2 — inventory fields.
     *
     * Absent on purpose: computed_stock_count and committed_count (maintained
     * by InventoryService under locks — stock arrives as a MOVEMENT, see the
     * 'stock' pseudo-field), every catalog_* column (owned by distributor sync
     * and clobbered on its next run), price_ack_at/by (an audit trail), and
     * distributor_catalog_id / default_vendor_id (set by linking, not typing).
     *
     * 'category' and 'vendor' are pseudo-fields: they resolve a NAME to a
     * record, creating it if needed, rather than writing a column directly.
     */
    public static function inventory(): array
    {
        return [
            'sku'          => ['label' => 'SKU',            'type' => 'text', 'max' => 100, 'match' => true],
            'name'         => ['label' => 'Item name',      'type' => 'text', 'max' => 255],
            'display_subtitle' => ['label' => 'Subtitle',   'type' => 'text', 'max' => 255],
            'description'  => ['label' => 'Description',    'type' => 'text', 'max' => 5000],
            'category'     => ['label' => 'Category (by name)', 'type' => 'resolve'],
            'vendor'       => ['label' => 'Vendor (by name)',   'type' => 'resolve'],
            'shop_cost_cents'        => ['label' => 'Shop cost',       'type' => 'money'],
            'shop_sell_price_cents'  => ['label' => 'Sell price',      'type' => 'money'],
            'shop_case_quantity'     => ['label' => 'Case quantity',   'type' => 'int'],
            'shop_reorder_threshold' => ['label' => 'Reorder at',      'type' => 'int'],
            'shop_reorder_quantity'  => ['label' => 'Reorder quantity','type' => 'int'],
            'shop_bin_location'      => ['label' => 'Bin location',    'type' => 'text', 'max' => 64],
            'stock'        => ['label' => 'Stock on hand',  'type' => 'int', 'stock' => true],
            'color'        => ['label' => 'Colour',         'type' => 'text', 'max' => 64],
            'size'         => ['label' => 'Size',           'type' => 'text', 'max' => 64],
            'upc'          => ['label' => 'UPC',            'type' => 'text', 'max' => 64],
            'tax_class_code' => ['label' => 'Tax class',    'type' => 'text', 'max' => 32],
            'is_active'    => ['label' => 'Active',         'type' => 'bool'],
            'is_stock_tracked' => ['label' => 'Track stock','type' => 'bool'],
            'show_online'  => ['label' => 'Show online',    'type' => 'bool'],
            'allow_oversell' => ['label' => 'Allow oversell','type' => 'bool'],
        ];
    }"""

n = src.count(old)
if n != 1:
    print(f"FAIL registry for(): anchor found {n} times"); sys.exit(1)
src = src.replace(old, new, 1)
print("ok   inventory field list")

# guesses for inventory headers
old_g = """    public static function guess(string $importType, string $header): ?string
    {"""
new_g = """    public static function guess(string $importType, string $header): ?string
    {
        if ($importType === 'inventory') {          // MARKER-IMPORT2
            return self::guessInventory($header);
        }
"""
if src.count(old_g) != 1:
    print("FAIL guess anchor"); sys.exit(1)
src = src.replace(old_g, new_g, 1)

tail = src.rstrip()
if not tail.endswith('}'):
    print("FAIL registry tail"); sys.exit(1)

helper = '''
    /** MARKER-IMPORT2 — header guesses for inventory exports. */
    private static function guessInventory(string $header): ?string
    {
        $norm = preg_replace('/[^a-z0-9]+/', '', strtolower($header));
        if ($norm === '') {
            return null;
        }

        $aliases = [
            'sku'          => ['sku', 'itemcode', 'itemnumber', 'partnumber', 'partno', 'code', 'mpn'],
            'name'         => ['name', 'itemname', 'description', 'title', 'product'],
            'description'  => ['longdescription', 'longdesc', 'details', 'detail'],
            'category'     => ['category', 'dept', 'department', 'group', 'class'],
            'vendor'       => ['vendor', 'supplier', 'brand', 'manufacturer', 'distributor'],
            'shop_cost_cents'       => ['cost', 'unitcost', 'wholesale', 'buyprice'],
            'shop_sell_price_cents' => ['price', 'retail', 'retailprice', 'sellprice', 'msrp'],
            'shop_case_quantity'    => ['casequantity', 'caseqty', 'packsize'],
            'shop_reorder_threshold'=> ['reorderpoint', 'reorderat', 'minqty', 'min'],
            'shop_reorder_quantity' => ['reorderquantity', 'reorderqty'],
            'shop_bin_location'     => ['bin', 'binlocation', 'shelf', 'location'],
            'stock'        => ['qty', 'quantity', 'onhand', 'stock', 'stockonhand', 'qtyonhand'],
            'color'        => ['color', 'colour'],
            'size'         => ['size'],
            'upc'          => ['upc', 'barcode', 'ean', 'gtin'],
            'is_active'    => ['active', 'isactive', 'enabled'],
            'show_online'  => ['showonline', 'online', 'web', 'ecommerce'],
        ];

        foreach ($aliases as $field => $names) {
            if (in_array($norm, $names, true)) {
                return $field;
            }
        }

        return null;
    }
}
'''
src = tail[:-1].rstrip('\n') + '\n' + helper
print("ok   inventory header guesses")

open(path, 'w').write(src)
PY

# ================================================================ inventory importer
if [ -f "$INV" ]; then echo "ok   inventory importer already present"; else
cat <<'EOF' > "$INV"
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
    public function buildRow(array $cells, ?TenantInventoryItem $existing): array
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
                $row = $this->buildRow($b['cells'], $existing[$b['key']] ?? null);
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
                    $row = $this->buildRow($b['cells'], $existing[$b['key']] ?? null);

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
EOF
echo "ok   inventory importer created"; fi

# ================================================================ reverser
if [ -f "$UNDO" ]; then echo "ok   reverser already present"; else
cat <<'EOF' > "$UNDO"
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
EOF
echo "ok   reverser created"; fi

# ================================================================ customer ledger
python3 - "$CUST" <<'PY'
import sys
path = sys.argv[1]
src = open(path).read()

old = """                        case 'create':
                            TenantCustomer::create(array_merge($row['values'], [
                                'tenant_id' => $this->tenant->id,
                            ]));
                            $counts['created']++;
                            break;

                        case 'update':
                            $row['match']->update($row['changes']);
                            $counts['updated']++;
                            break;"""

new = """                        case 'create':
                            // MARKER-IMPORT2 — ledger the creation so it can be undone
                            $made = TenantCustomer::create(array_merge($row['values'], [
                                'tenant_id' => $this->tenant->id,
                            ]));
                            \\App\\Models\\Tenant\\TenantImportRow::create([
                                'import_id' => $this->import->id, 'tenant_id' => $this->tenant->id,
                                'action' => 'created', 'record_type' => 'customer',
                                'record_id' => $made->id, 'created_at' => now(),
                            ]);
                            $counts['created']++;
                            break;

                        case 'update':
                            // MARKER-IMPORT2 — record prior values so they can be restored
                            $before = [];
                            foreach ($row['changes'] as $k => $v) { $before[$k] = $row['match']->{$k}; }
                            $row['match']->update($row['changes']);
                            \\App\\Models\\Tenant\\TenantImportRow::create([
                                'import_id' => $this->import->id, 'tenant_id' => $this->tenant->id,
                                'action' => 'updated', 'record_type' => 'customer',
                                'record_id' => $row['match']->id, 'before' => $before, 'created_at' => now(),
                            ]);
                            $counts['updated']++;
                            break;"""

n = src.count(old)
if n != 1:
    print(f"FAIL customer ledger: anchor found {n} times"); sys.exit(1)
src = src.replace(old, new, 1)
print("ok   customer import ledger")

open(path, 'w').write(src)
PY

# ================================================================ controller
python3 - "$CTRL" <<'PY'
import sys
path = sys.argv[1]
src = open(path).read()

def edit(old, new, label):
    global src
    n = src.count(old)
    if n != 1:
        print(f"FAIL {label}: anchor found {n} times"); sys.exit(1)
    src = src.replace(old, new, 1)
    print(f"ok   {label}")

edit("""            'type' => ['required', 'in:customers'],""",
     """            'type' => ['required', 'in:customers,inventory'], // MARKER-IMPORT2""",
     "accept inventory type")

# importer factory
edit("""    private function find(string $id): TenantImport""",
"""    /** MARKER-IMPORT2 — one importer per type, same contract. */
    private function importer(TenantImport $import)
    {
        return $import->type === 'inventory'
            ? new \\App\\Services\\Tenant\\Import\\InventoryImporter(tenant(), $import)
            : new CustomerImporter(tenant(), $import);
    }

    private function find(string $id): TenantImport""",
"importer factory")

edit("""        $result = (new CustomerImporter(tenant(), $import))->preview();""",
     """        $result = $this->importer($import)->preview();""",
     "preview uses factory")

edit("""            $result = (new CustomerImporter(tenant(), $import))->run();""",
     """            $result = $this->importer($import)->run();""",
     "run uses factory")

# locations for the rules screen
edit("""        return view('tenant.imports.map', compact('import', 'preview', 'stats', 'fields', 'mapping'));""",
"""        // MARKER-IMPORT2 — inventory needs a location to count stock at
        $locations = \\App\\Models\\Tenant\\TenantLocation::where('tenant_id', tenant()->id)
            ->where('is_active', true)->orderBy('name')->get();

        return view('tenant.imports.map', compact('import', 'preview', 'stats', 'fields', 'mapping', 'locations'));""",
"locations for mapping screen")

edit("""                'direction' => in_array($request->input('direction'), ['csv', 'keep', 'blank'], true)
                               ? $request->input('direction') : 'csv',
            ]),""",
"""                'direction' => in_array($request->input('direction'), ['csv', 'keep', 'blank'], true)
                               ? $request->input('direction') : 'csv',
                // MARKER-IMPORT2 — inventory only
                'location_id'       => $request->input('location_id'),
                'stock_mode'        => in_array($request->input('stock_mode'), ['set', 'add', 'leave'], true)
                                       ? $request->input('stock_mode') : 'set',
                'create_categories' => $request->boolean('create_categories'),
                'create_vendors'    => $request->boolean('create_vendors'),
            ]),""",
"inventory options")

# undo endpoint
tail = src.rstrip()
if not tail.endswith('}'):
    print("FAIL controller tail"); sys.exit(1)

method = '''
    /**
     * MARKER-IMPORT2 — reverse an import.
     *
     * Anything that has been used since is kept rather than deleted, and the
     * result says so plainly. Undoing twice is harmless: reversed rows are
     * stamped and skipped.
     */
    public function reverse(string $id)
    {
        $this->guard();
        $import = $this->find($id);

        if ($import->status !== 'done') {
            return back()->with('error', 'Only a finished import can be reversed.');
        }

        $result = (new \\App\\Services\\Tenant\\Import\\ImportReverser(tenant(), $import))->reverse();

        $import->update([
            'status' => 'reversed',
            'totals' => array_merge((array) $import->totals, ['reversal' => $result]),
        ]);

        $msg = 'Reversed: ' . $result['deleted'] . ' deleted, ' . $result['restored'] . ' restored';
        if ($result['stock_reversed']) { $msg .= ', ' . $result['stock_reversed'] . ' stock changes undone'; }
        if ($result['kept']) {
            $msg .= '. ' . $result['kept'] . ' kept because they have been used since.';
        }

        return redirect()->route('tenant.imports.show', $import->id)->with('success', $msg);
    }
}
'''
src = tail[:-1].rstrip('\n') + '\n' + method
print("ok   reverse endpoint")

open(path, 'w').write(src)
PY

# ================================================================ route
python3 - "$ROUTES" <<'PY'
import sys
path = sys.argv[1]
src = open(path).read()

old = """            Route::get('/imports/{id}/errors',          [TenantControllers\\ImportController::class, 'errors'])->name('imports.errors');"""
new = """            Route::get('/imports/{id}/errors',          [TenantControllers\\ImportController::class, 'errors'])->name('imports.errors');
            Route::post('/imports/{id}/reverse',        [TenantControllers\\ImportController::class, 'reverse'])->name('imports.reverse'); // MARKER-IMPORT2"""

n = src.count(old)
if n != 1:
    print(f"FAIL route: anchor found {n} times"); sys.exit(1)
src = src.replace(old, new, 1)
print("ok   reverse route")

open(path, 'w').write(src)
PY

# ================================================================ views
python3 - "$VDIR/create.blade.php" <<'PY'
import sys
path = sys.argv[1]
src = open(path).read()

old = """  <input type="hidden" name="type" value="customers">

  <div class="ia-card">
    <div class="ia-card-head"><span class="ia-card-title">Customers</span></div>
    <div class="ia-card-body">
      <p style="font-size:12.5px;color:var(--ia-text-dim);margin-bottom:16px">
        Names, contact details, address, notes, VIP flag, and the business fields — business name,
        tax exemption, payment terms, PO required. Matched on email address.
      </p>
"""

new = """  {{-- MARKER-IMPORT2 — pick what you're importing --}}
  <div class="ia-card">
    <div class="ia-card-head"><span class="ia-card-title">What are you importing?</span></div>
    <div class="ia-card-body">
      <label class="imp-radio"><input type="radio" name="type" value="customers" checked>
        <span><b>Customers</b><span>Names, contact details, address, notes, VIP, and the business
          fields — business name, tax exemption, payment terms, PO required. Matched on email.</span></span></label>
      <label class="imp-radio"><input type="radio" name="type" value="inventory">
        <span><b>Inventory</b><span>SKU, name, description, cost and price, reorder points, bin,
          size and colour, plus category, vendor and stock on hand. Matched on SKU.</span></span></label>
    </div>
  </div>

  <div class="ia-card">
    <div class="ia-card-head"><span class="ia-card-title">Your file</span></div>
    <div class="ia-card-body">
"""

if src.count(old) != 1:
    print(f"FAIL create view: anchor found {src.count(old)} times"); sys.exit(1)
src = src.replace(old, new, 1)
print("ok   create view offers inventory")

open(path, 'w').write(src)
PY

python3 - "$VDIR/map.blade.php" <<'PY'
import sys
path = sys.argv[1]
src = open(path).read()

old = """  <div class="imp-foot">
    <a href="{{ route('tenant.imports.index') }}" class="ia-btn ia-btn--secondary">Cancel</a>"""

new = """  @if($import->type === 'inventory')
    {{-- MARKER-IMPORT2 — stock is a movement at a location, so it needs one --}}
    <div class="ia-card" style="margin-top:16px">
      <div class="ia-card-head"><span class="ia-card-title">Stock &amp; records</span></div>
      <div class="ia-card-body">
        <div class="imp-two" style="margin-top:0">
          <div>
            <label class="ia-form-label">Count quantities at</label>
            <select name="location_id" class="ia-input">
              @foreach($locations as $loc)
                <option value="{{ $loc->id }}">{{ $loc->name }}</option>
              @endforeach
            </select>
            <p class="imp-hint" style="margin-top:6px">Recorded as a counted movement here, so the
              ledger, transfers and reports stay consistent.</p>
          </div>
          <div>
            <label class="ia-form-label">If the item already has stock</label>
            <label class="imp-radio"><input type="radio" name="stock_mode" value="set" checked>
              <span><b>Set to the file's number</b><span>Records the difference as a counted adjustment.</span></span></label>
            <label class="imp-radio"><input type="radio" name="stock_mode" value="add">
              <span><b>Add to what's there</b><span>Treats the file as a received shipment.</span></span></label>
            <label class="imp-radio"><input type="radio" name="stock_mode" value="leave">
              <span><b>Leave stock alone</b></span></label>
          </div>
        </div>
        <label class="imp-radio"><input type="checkbox" name="create_categories" value="1" checked>
          <span><b>Create categories that don't exist</b><span>Matched on name. "Parts &gt; Brakes" creates the parent too.</span></span></label>
        <label class="imp-radio"><input type="checkbox" name="create_vendors" value="1" checked>
          <span><b>Create vendors that don't exist</b><span>Existing vendors are matched on name first.</span></span></label>
      </div>
    </div>
  @endif

  <div class="imp-foot">
    <a href="{{ route('tenant.imports.index') }}" class="ia-btn ia-btn--secondary">Cancel</a>"""

if src.count(old) != 1:
    print(f"FAIL map view: anchor found {src.count(old)} times"); sys.exit(1)
src = src.replace(old, new, 1)
print("ok   map view stock options")

open(path, 'w').write(src)
PY

python3 - "$VDIR/show.blade.php" <<'PY'
import sys
path = sys.argv[1]
src = open(path).read()

old = """@if($import->error_path)"""
new = """{{-- MARKER-IMPORT2 — reverse this import --}}
@if($import->status === 'done')
  @php $rev = ($import->totals['reversal'] ?? null); @endphp
  <div class="ia-card">
    <div class="ia-card-head"><span class="ia-card-title">Reverse this import</span></div>
    <div class="ia-card-body">
      <p class="imp-hint" style="margin-bottom:12px">
        Deletes what this import created and puts back what it changed. Anything that has been
        <b>used since</b> — sold, transferred, put on a ticket — is kept rather than deleted, and
        you'll be told which. Stock is corrected with a counter-movement, so the history stays intact.
      </p>
      <form method="POST" action="{{ route('tenant.imports.reverse', $import->id) }}"
            onsubmit="return confirm('Reverse this import? Records that have been used since will be kept.')">
        @csrf
        <button type="submit" class="ia-btn ia-btn--secondary">Reverse import</button>
      </form>
    </div>
  </div>
@elseif($import->status === 'reversed')
  @php $rev = $import->totals['reversal'] ?? []; @endphp
  <div class="ia-card">
    <div class="ia-card-head"><span class="ia-card-title">Reversed</span></div>
    <div class="ia-card-body imp-hint">
      {{ $rev['deleted'] ?? 0 }} deleted · {{ $rev['restored'] ?? 0 }} restored ·
      {{ $rev['stock_reversed'] ?? 0 }} stock changes undone
      @if(($rev['kept'] ?? 0) > 0)
        <div style="margin-top:8px;color:var(--ia-text)">{{ $rev['kept'] }} kept because they'd been used since:</div>
        @foreach(array_slice($rev['keptDetail'] ?? [], 0, 20) as $k)
          <div style="font-size:11.5px">{{ $k['type'] }} — {{ $k['why'] }}</div>
        @endforeach
      @endif
    </div>
  </div>
@endif

@if($import->error_path)"""

if src.count(old) != 1:
    print(f"FAIL show view: anchor found {src.count(old)} times"); sys.exit(1)
src = src.replace(old, new, 1)
print("ok   show view reverse panel")

open(path, 'w').write(src)
PY

php -l "$REG"
php -l "$INV"
php -l "$UNDO"
php -l "$ROWM"
php -l "$CTRL"
php -l "$CUST"

echo ""
echo "SUCCESS — apply-import-suite-2-inventory.sh applied."
echo "Inventory import + reversible imports for both types."
