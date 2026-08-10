#!/usr/bin/env bash
set -euo pipefail
# apply-import-suite-1-engine.sh — MARKER-IMPORT1
# Patch 1 of the CSV import suite: the engine, wired end to end for CUSTOMERS.
# Inventory is patch 2 and slots into the same pipeline (its own field map +
# category/vendor resolution + stock movements).
#
# Nothing like this existed — the only CSV reading in the repo is the BTI feed
# parser and a prospects console command.
#
# PIPELINE (matches the approved mockup):
#   upload  -> detect delimiter/encoding/header, sample rows, store the file
#   map     -> per column: target field + per-field merge direction
#   rules   -> add / add+update / update-only, default merge direction
#   preview -> validate EVERY row, classify create/update/error, nothing written
#   run     -> chunked, transactional, per-row outcome recorded
#   result  -> counts + a downloadable error CSV that keeps the original columns
#              plus a reason column, so it can be fixed and re-imported directly
#
# DESIGN NOTES worth keeping
#   - The importable field list is a REGISTRY, not "every column". Passwords,
#     reset tokens, stripe ids and SMS-consent fields are deliberately absent:
#     consent has to be evidenced, not assigned.
#   - Match key is email. Rows with no email can only ever be inserts, and in
#     update-only mode they are REPORTED, not silently dropped — a silent skip
#     is how imports quietly lose half a file.
#   - Merge direction is resolved per field: csv | keep | blank (only fill
#     blanks). A blank incoming value NEVER overwrites, whatever the direction.
#   - Preview and run share ONE code path (buildRow), so what you were shown is
#     what actually happens.
#   - The uploaded file lives on the local disk under imports/{tenant}/; it is
#     never web-served.

MIG=database/migrations/2026_08_09_190000_create_tenant_imports_table.php
REG=app/Support/ImportFieldRegistry.php
CSV=app/Services/Tenant/Import/CsvFile.php
RUN=app/Services/Tenant/Import/CustomerImporter.php
MODEL=app/Models/Tenant/TenantImport.php
CTRL=app/Http/Controllers/Tenant/ImportController.php
VDIR=resources/views/tenant/imports
ROUTES=routes/web.php
NAV=resources/views/layouts/tenant/_nav-items.blade.php
CAP=app/Support/CapabilityRegistry.php

for f in "$ROUTES" "$NAV" "$CAP"; do
  [ -f "$f" ] || { echo "MISSING $f — run from the repo root"; exit 1; }
done

if grep -q "MARKER-IMPORT1" "$ROUTES"; then
  echo "Already applied (MARKER-IMPORT1 present) — no-op."
  exit 0
fi

mkdir -p "$VDIR" app/Services/Tenant/Import

# ================================================================ migration
if [ -f "$MIG" ]; then echo "ok   migration already present"; else
cat <<'EOF' > "$MIG"
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// MARKER-IMPORT1 — one row per import attempt. Keeps the file, the mapping and
// the outcome so a bad run can be diagnosed instead of guessed at.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tenant_imports', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained()->cascadeOnDelete();

            $table->string('type', 20);                 // customers | inventory
            $table->string('original_filename');
            $table->string('stored_path');              // local disk, never web-served
            $table->string('delimiter', 4)->default(',');
            $table->string('encoding', 20)->default('UTF-8');
            $table->boolean('has_header')->default(true);

            $table->json('columns')->nullable();        // header names as found
            $table->json('mapping')->nullable();        // column index => [field, dir]
            $table->json('options')->nullable();        // mode, default direction, …
            $table->json('totals')->nullable();         // created/updated/skipped/errors

            $table->string('status', 20)->default('draft'); // draft|previewed|running|done|failed
            $table->text('failure_reason')->nullable();
            $table->string('error_path')->nullable();   // generated error CSV

            $table->uuid('created_by_user_id')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tenant_imports');
    }
};
EOF
echo "ok   migration created"; fi

# ================================================================ model
if [ -f "$MODEL" ]; then echo "ok   model already present"; else
cat <<'EOF' > "$MODEL"
<?php

namespace App\Models\Tenant;

// MARKER-IMPORT1
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class TenantImport extends Model
{
    use HasUuids;

    protected $fillable = [
        'tenant_id', 'type', 'original_filename', 'stored_path', 'delimiter',
        'encoding', 'has_header', 'columns', 'mapping', 'options', 'totals',
        'status', 'failure_reason', 'error_path', 'created_by_user_id',
        'started_at', 'finished_at',
    ];

    protected $casts = [
        'columns'     => 'array',
        'mapping'     => 'array',
        'options'     => 'array',
        'totals'      => 'array',
        'has_header'  => 'boolean',
        'started_at'  => 'datetime',
        'finished_at' => 'datetime',
    ];

    public function total(string $key): int
    {
        return (int) (($this->totals ?? [])[$key] ?? 0);
    }
}
EOF
echo "ok   model created"; fi

# ================================================================ field registry
if [ -f "$REG" ]; then echo "ok   field registry already present"; else
cat <<'EOF' > "$REG"
<?php

namespace App\Support;

/**
 * MARKER-IMPORT1 — what a CSV is allowed to write.
 *
 * An ALLOW-LIST, not "every column on the table". Deliberately absent for
 * customers: password / remember_token / password_reset_* (credentials),
 * stripe_customer_id (owned by Stripe), email_verified_at, and
 * sms_opt_out_at / sms_consent_source — SMS consent has to be evidenced, not
 * assigned by a spreadsheet.
 *
 * type: text | email | phone | bool | choice | int
 */
class ImportFieldRegistry
{
    public static function for(string $importType): array
    {
        return $importType === 'customers' ? self::customers() : [];
    }

    public static function customers(): array
    {
        return [
            'first_name'  => ['label' => 'First name',    'type' => 'text',  'max' => 80],
            'last_name'   => ['label' => 'Last name',     'type' => 'text',  'max' => 80],
            'email'       => ['label' => 'Email',         'type' => 'email', 'max' => 180, 'match' => true],
            'phone'       => ['label' => 'Phone',         'type' => 'phone', 'max' => 40],
            'address_line1' => ['label' => 'Address 1',   'type' => 'text',  'max' => 255],
            'address_line2' => ['label' => 'Address 2',   'type' => 'text',  'max' => 255],
            'city'        => ['label' => 'City',          'type' => 'text',  'max' => 128],
            'state'       => ['label' => 'State',         'type' => 'text',  'max' => 64],
            'postcode'    => ['label' => 'Postal code',   'type' => 'text',  'max' => 32],
            'country'     => ['label' => 'Country',       'type' => 'text',  'max' => 64],
            'notes'       => ['label' => 'Notes',         'type' => 'text',  'max' => 5000],
            'is_vip'      => ['label' => 'VIP',           'type' => 'bool'],
            'customer_type' => ['label' => 'Customer type', 'type' => 'choice',
                                'choices' => ['person', 'business']],
            'business_name' => ['label' => 'Business name', 'type' => 'text', 'max' => 255],
            'tax_exempt'  => ['label' => 'Tax exempt',    'type' => 'bool'],
            'tax_exempt_certificate' => ['label' => 'Tax exempt certificate', 'type' => 'text', 'max' => 128],
            'payment_terms' => ['label' => 'Payment terms', 'type' => 'text', 'max' => 64],
            'po_required' => ['label' => 'PO required',   'type' => 'bool'],
        ];
    }

    /** The field a row is matched on for this import type. */
    public static function matchField(string $importType): string
    {
        foreach (self::for($importType) as $key => $def) {
            if (! empty($def['match'])) {
                return $key;
            }
        }

        return 'email';
    }

    /**
     * Best-guess mapping from a header name. Deliberately conservative — a
     * wrong guess a person doesn't notice is worse than no guess.
     */
    public static function guess(string $importType, string $header): ?string
    {
        $norm = preg_replace('/[^a-z0-9]+/', '', strtolower($header));
        if ($norm === '') {
            return null;
        }

        $aliases = [
            'first_name' => ['firstname', 'fname', 'given', 'givenname', 'first'],
            'last_name'  => ['lastname', 'lname', 'surname', 'family', 'last'],
            'email'      => ['email', 'emailaddress', 'mail', 'e'],
            'phone'      => ['phone', 'phonenumber', 'mobile', 'cell', 'tel', 'telephone'],
            'address_line1' => ['address', 'address1', 'addressline1', 'street', 'street1'],
            'address_line2' => ['address2', 'addressline2', 'street2', 'unit', 'apt'],
            'city'       => ['city', 'town'],
            'state'      => ['state', 'province', 'region'],
            'postcode'   => ['postcode', 'postalcode', 'zip', 'zipcode'],
            'country'    => ['country'],
            'notes'      => ['notes', 'note', 'comment', 'comments'],
            'is_vip'     => ['vip', 'isvip'],
            'business_name' => ['business', 'businessname', 'company', 'companyname', 'organisation', 'organization'],
            'tax_exempt' => ['taxexempt', 'exempt'],
            'payment_terms' => ['terms', 'paymentterms'],
            'po_required'   => ['porequired', 'ponumberrequired'],
        ];

        foreach ($aliases as $field => $names) {
            if (in_array($norm, $names, true)) {
                return $field;
            }
        }

        return null;
    }
}
EOF
echo "ok   field registry created"; fi

# ================================================================ csv reader
if [ -f "$CSV" ]; then echo "ok   csv reader already present"; else
cat <<'EOF' > "$CSV"
<?php

namespace App\Services\Tenant\Import;

/**
 * MARKER-IMPORT1 — thin CSV reader.
 *
 * Streams with fgetcsv rather than loading the file, so a 50k-row export
 * doesn't sit in memory. Encoding is converted per line for the same reason.
 */
class CsvFile
{
    public function __construct(
        private string $path,
        private string $delimiter = ',',
        private string $encoding = 'UTF-8'
    ) {}

    /** Sniff the delimiter from the first line by counting candidates. */
    public static function detectDelimiter(string $path): string
    {
        $h = @fopen($path, 'r');
        if (! $h) {
            return ',';
        }
        $line = fgets($h) ?: '';
        fclose($h);

        $best = ','; $bestCount = 0;
        foreach ([',', ';', "\t", '|'] as $d) {
            $n = substr_count($line, $d);
            if ($n > $bestCount) { $bestCount = $n; $best = $d; }
        }

        return $best;
    }

    /** UTF-8 unless the file has bytes that aren't valid UTF-8. */
    public static function detectEncoding(string $path): string
    {
        $sample = @file_get_contents($path, false, null, 0, 65536) ?: '';

        return mb_check_encoding($sample, 'UTF-8') ? 'UTF-8' : 'Windows-1252';
    }

    private function toUtf8(array $row): array
    {
        if ($this->encoding === 'UTF-8') {
            return $row;
        }

        return array_map(function ($v) {
            return is_string($v) ? mb_convert_encoding($v, 'UTF-8', $this->encoding) : $v;
        }, $row);
    }

    /** @return \Generator<int, array> yields [lineNumber, cells] */
    public function rows(): \Generator
    {
        $h = @fopen($this->path, 'r');
        if (! $h) {
            return;
        }

        $line = 0;
        while (($cells = fgetcsv($h, 0, $this->delimiter)) !== false) {
            $line++;
            // fgetcsv gives [null] for a blank line — skip rather than error.
            if ($cells === [null] || (count($cells) === 1 && trim((string) $cells[0]) === '')) {
                continue;
            }
            yield [$line, $this->toUtf8($cells)];
        }

        fclose($h);
    }

    /** Header names plus the first $n data rows, for the mapping screen. */
    public function preview(bool $hasHeader, int $n = 3): array
    {
        $header = [];
        $sample = [];
        $count  = 0;

        foreach ($this->rows() as [$line, $cells]) {
            if ($hasHeader && $count === 0 && $line === 1) {
                $header = array_map(fn ($c) => trim((string) $c), $cells);
                $count++;
                continue;
            }
            $sample[] = $cells;
            $count++;
            if (count($sample) >= $n) {
                break;
            }
        }

        if (! $hasHeader) {
            $width  = $sample ? count($sample[0]) : 0;
            $header = [];
            for ($i = 0; $i < $width; $i++) {
                $header[] = 'Column ' . ($i + 1);
            }
        }

        return ['header' => $header, 'sample' => $sample];
    }

    /** Row count excluding the header, plus rows whose width doesn't match. */
    public function stats(bool $hasHeader): array
    {
        $rows = 0; $ragged = 0; $width = null; $first = true;

        foreach ($this->rows() as [$line, $cells]) {
            if ($hasHeader && $first) { $width = count($cells); $first = false; continue; }
            if ($width === null) { $width = count($cells); }
            if (count($cells) !== $width) { $ragged++; }
            $rows++;
            $first = false;
        }

        return ['rows' => $rows, 'ragged' => $ragged, 'width' => $width ?? 0];
    }
}
EOF
echo "ok   csv reader created"; fi

# ================================================================ importer
if [ -f "$RUN" ]; then echo "ok   customer importer already present"; else
cat <<'EOF' > "$RUN"
<?php

namespace App\Services\Tenant\Import;

/**
 * MARKER-IMPORT1 — customer import.
 *
 * preview() and run() share buildRow(), so the preview is not an estimate:
 * it is the same decision the write path makes, just not persisted.
 */

use App\Models\Tenant;
use App\Models\Tenant\TenantCustomer;
use App\Models\Tenant\TenantImport;
use App\Support\ImportFieldRegistry;
use Illuminate\Support\Facades\DB;

class CustomerImporter
{
    public const CHUNK = 200;

    public function __construct(private Tenant $tenant, private TenantImport $import) {}

    private function fields(): array
    {
        return ImportFieldRegistry::customers();
    }

    /** column index => ['field' => …, 'dir' => csv|keep|blank] */
    private function mapping(): array
    {
        $out = [];
        foreach ((array) ($this->import->mapping ?? []) as $idx => $m) {
            $field = is_array($m) ? ($m['field'] ?? null) : $m;
            if ($field && isset($this->fields()[$field])) {
                $out[(int) $idx] = [
                    'field' => $field,
                    'dir'   => is_array($m) ? ($m['dir'] ?? null) : null,
                ];
            }
        }

        return $out;
    }

    private function option(string $key, $default = null)
    {
        return ($this->import->options ?? [])[$key] ?? $default;
    }

    /** Cast a raw cell to the field's type. Returns [value, error|null]. */
    private function cast(string $field, string $raw): array
    {
        $def = $this->fields()[$field];
        $raw = trim($raw);

        if ($raw === '') {
            return [null, null];
        }

        switch ($def['type']) {
            case 'email':
                if (! filter_var($raw, FILTER_VALIDATE_EMAIL)) {
                    return [null, 'Email "' . $raw . '" isn\'t a valid address'];
                }
                return [strtolower($raw), null];

            case 'bool':
                $t = strtolower($raw);
                if (in_array($t, ['1', 'true', 'yes', 'y', 't'], true))  { return [true, null]; }
                if (in_array($t, ['0', 'false', 'no', 'n', 'f'], true))  { return [false, null]; }
                return [null, ucfirst(str_replace('_', ' ', $field)) . ' "' . $raw . '" isn\'t yes or no'];

            case 'choice':
                $t = strtolower($raw);
                if (! in_array($t, $def['choices'], true)) {
                    return [null, ucfirst(str_replace('_', ' ', $field)) . ' must be one of: '
                                  . implode(', ', $def['choices'])];
                }
                return [$t, null];

            case 'int':
                if (! is_numeric($raw)) {
                    return [null, ucfirst(str_replace('_', ' ', $field)) . ' isn\'t a number'];
                }
                return [(int) $raw, null];

            default:
                if (isset($def['max']) && mb_strlen($raw) > $def['max']) {
                    return [mb_substr($raw, 0, $def['max']), null]; // truncate, don't fail the row
                }
                return [$raw, null];
        }
    }

    /**
     * Turn one CSV line into a decision. Never writes.
     *
     * @return array{outcome:string, errors:array, values:array, match:?TenantCustomer, changes:array}
     */
    public function buildRow(array $cells, ?TenantCustomer $existing): array
    {
        $errors = [];
        $values = [];
        $dirs   = [];

        foreach ($this->mapping() as $idx => $m) {
            $raw = (string) ($cells[$idx] ?? '');
            [$val, $err] = $this->cast($m['field'], $raw);
            if ($err) { $errors[] = $err; continue; }
            if ($val === null) { continue; }          // blank never overwrites
            $values[$m['field']] = $val;
            $dirs[$m['field']]   = $m['dir'] ?: $this->option('direction', 'csv');
        }

        $matchField = ImportFieldRegistry::matchField('customers');
        $mode       = $this->option('mode', 'upsert');

        if (empty($values[$matchField]) && ! $existing) {
            // No email at all: still importable as a new person, but it can
            // never be matched again on a later run — say so rather than
            // pretending it's clean.
            if ($mode === 'update') {
                return ['outcome' => 'error', 'errors' => ['No email — nothing to match against'],
                        'values' => $values, 'match' => null, 'changes' => []];
            }
        }

        if ($errors) {
            return ['outcome' => 'error', 'errors' => $errors, 'values' => $values,
                    'match' => $existing, 'changes' => []];
        }

        if (! $existing) {
            if ($mode === 'update') {
                return ['outcome' => 'unmatched', 'errors' => [], 'values' => $values,
                        'match' => null, 'changes' => []];
            }
            if (empty($values['first_name']) && empty($values['last_name']) && empty($values['business_name'])) {
                return ['outcome' => 'error', 'errors' => ['Row has no name and no business name'],
                        'values' => $values, 'match' => null, 'changes' => []];
            }
            return ['outcome' => 'create', 'errors' => [], 'values' => $values,
                    'match' => null, 'changes' => $values];
        }

        if ($mode === 'insert') {
            return ['outcome' => 'skipped', 'errors' => [], 'values' => $values,
                    'match' => $existing, 'changes' => []];
        }

        // Merge: work out which fields actually change.
        $changes = [];
        foreach ($values as $field => $val) {
            if ($field === $matchField) { continue; }   // never rewrite the key
            $currentRaw = $existing->{$field};
            $current    = is_bool($currentRaw) ? $currentRaw : (string) ($currentRaw ?? '');
            $incoming   = is_bool($val) ? $val : (string) $val;

            if ($current === $incoming) { continue; }

            $dir = $dirs[$field] ?? 'csv';
            $isBlank = $currentRaw === null || $currentRaw === '';

            if ($dir === 'keep') { continue; }
            if ($dir === 'blank' && ! $isBlank) { continue; }

            $changes[$field] = $val;
        }

        return [
            'outcome' => $changes ? 'update' : 'unchanged',
            'errors'  => [], 'values' => $values, 'match' => $existing, 'changes' => $changes,
        ];
    }

    /** Existing customers keyed by match value, for the rows in this batch. */
    private function lookup(array $keys): array
    {
        $keys = array_values(array_filter(array_unique($keys)));
        if (! $keys) {
            return [];
        }

        $field = ImportFieldRegistry::matchField('customers');

        return TenantCustomer::where('tenant_id', $this->tenant->id)
            ->whereIn($field, $keys)->get()
            ->keyBy(fn ($c) => strtolower((string) $c->{$field}))->all();
    }

    /** Validate the whole file. Writes nothing. */
    public function preview(int $sampleLimit = 250): array
    {
        $csv = new CsvFile($this->import->stored_path, $this->import->delimiter, $this->import->encoding);

        $counts = ['create' => 0, 'update' => 0, 'unchanged' => 0,
                   'skipped' => 0, 'unmatched' => 0, 'error' => 0];
        $sample = [];
        $seen   = [];
        $first  = true;

        $matchIdx = null;
        foreach ($this->mapping() as $idx => $m) {
            if ($m['field'] === ImportFieldRegistry::matchField('customers')) { $matchIdx = $idx; }
        }

        // Batch the existence lookups instead of one query per row.
        $batch = [];
        $flush = function () use (&$batch, &$counts, &$sample, $sampleLimit) {
            if (! $batch) { return; }
            $existing = $this->lookup(array_map(fn ($b) => $b['key'], $batch));
            foreach ($batch as $b) {
                $row = $this->buildRow($b['cells'], $existing[$b['key']] ?? null);
                $counts[$row['outcome']] = ($counts[$row['outcome']] ?? 0) + 1;
                if (count($sample) < $sampleLimit) {
                    $sample[] = [
                        'line'    => $b['line'],
                        'outcome' => $row['outcome'],
                        'errors'  => $row['errors'],
                        'label'   => trim(($row['values']['first_name'] ?? '') . ' ' .
                                          ($row['values']['last_name'] ?? '')) ?:
                                     ($row['values']['business_name'] ?? '—'),
                        'key'     => $b['key'] ?: '—',
                        'changes' => array_keys($row['changes']),
                    ];
                }
            }
            $batch = [];
        };

        foreach ($csv->rows() as [$line, $cells]) {
            if ($this->import->has_header && $first) { $first = false; continue; }
            $first = false;

            $key = $matchIdx !== null ? strtolower(trim((string) ($cells[$matchIdx] ?? ''))) : '';

            if ($key !== '' && isset($seen[$key])) {
                $counts['error']++;
                if (count($sample) < $sampleLimit) {
                    $sample[] = ['line' => $line, 'outcome' => 'error',
                                 'errors' => ['Appears twice in this file (also line ' . $seen[$key] . ')'],
                                 'label' => '—', 'key' => $key, 'changes' => []];
                }
                continue;
            }
            if ($key !== '') { $seen[$key] = $line; }

            $batch[] = ['line' => $line, 'cells' => $cells, 'key' => $key];
            if (count($batch) >= self::CHUNK) { $flush(); }
        }
        $flush();

        return ['counts' => $counts, 'sample' => $sample];
    }

    /** Write it. Each chunk is its own transaction. */
    public function run(): array
    {
        $csv = new CsvFile($this->import->stored_path, $this->import->delimiter, $this->import->encoding);

        $counts = ['created' => 0, 'updated' => 0, 'unchanged' => 0,
                   'skipped' => 0, 'unmatched' => 0, 'errors' => 0];
        $errorRows = [];
        $first = true;
        $seen  = [];

        $matchIdx = null;
        foreach ($this->mapping() as $idx => $m) {
            if ($m['field'] === ImportFieldRegistry::matchField('customers')) { $matchIdx = $idx; }
        }

        $batch = [];
        $flush = function () use (&$batch, &$counts, &$errorRows) {
            if (! $batch) { return; }
            $existing = $this->lookup(array_map(fn ($b) => $b['key'], $batch));

            DB::transaction(function () use ($batch, $existing, &$counts, &$errorRows) {
                foreach ($batch as $b) {
                    $row = $this->buildRow($b['cells'], $existing[$b['key']] ?? null);

                    switch ($row['outcome']) {
                        case 'create':
                            TenantCustomer::create(array_merge($row['values'], [
                                'tenant_id' => $this->tenant->id,
                            ]));
                            $counts['created']++;
                            break;

                        case 'update':
                            $row['match']->update($row['changes']);
                            $counts['updated']++;
                            break;

                        case 'error':
                            $counts['errors']++;
                            $errorRows[] = [$b['cells'], implode('; ', $row['errors'])];
                            break;

                        case 'unmatched':
                            $counts['unmatched']++;
                            $errorRows[] = [$b['cells'], 'No existing customer matched this row'];
                            break;

                        default:
                            $counts[$row['outcome']] = ($counts[$row['outcome']] ?? 0) + 1;
                    }
                }
            });

            $batch = [];
        };

        foreach ($csv->rows() as [$line, $cells]) {
            if ($this->import->has_header && $first) { $first = false; continue; }
            $first = false;

            $key = $matchIdx !== null ? strtolower(trim((string) ($cells[$matchIdx] ?? ''))) : '';

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
echo "ok   customer importer created"; fi

# ================================================================ controller
if [ -f "$CTRL" ]; then echo "ok   controller already present"; else
cat <<'EOF' > "$CTRL"
<?php

namespace App\Http\Controllers\Tenant;

// MARKER-IMPORT1 — the import wizard. Customers in patch 1; the pipeline is
// shaped so inventory drops in beside it.

use App\Http\Controllers\Controller;
use App\Models\Tenant\TenantImport;
use App\Services\Tenant\Import\CsvFile;
use App\Services\Tenant\Import\CustomerImporter;
use App\Support\ImportFieldRegistry;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class ImportController extends Controller
{
    private function guard(): void
    {
        abort_unless(auth('tenant')->user()?->can('customers.import'), 403);
    }

    private function find(string $id): TenantImport
    {
        return TenantImport::where('tenant_id', tenant()->id)->where('id', $id)->firstOrFail();
    }

    public function index()
    {
        $this->guard();

        $imports = TenantImport::where('tenant_id', tenant()->id)
            ->orderByDesc('created_at')->limit(50)->get();

        return view('tenant.imports.index', compact('imports'));
    }

    public function create()
    {
        $this->guard();

        return view('tenant.imports.create');
    }

    public function store(Request $request)
    {
        $this->guard();

        $data = $request->validate([
            'type' => ['required', 'in:customers'],
            'file' => ['required', 'file', 'mimes:csv,txt', 'max:20480'],
        ]);

        $tenant = tenant();
        $path   = $request->file('file')->store('imports/' . $tenant->id, 'local');
        $abs    = Storage::disk('local')->path($path);

        $import = TenantImport::create([
            'tenant_id'         => $tenant->id,
            'type'              => $data['type'],
            'original_filename' => $request->file('file')->getClientOriginalName(),
            'stored_path'       => $abs,
            'delimiter'         => CsvFile::detectDelimiter($abs),
            'encoding'          => CsvFile::detectEncoding($abs),
            'has_header'        => true,
            'status'            => 'draft',
            'created_by_user_id'=> auth('tenant')->id(),
        ]);

        return redirect()->route('tenant.imports.map', $import->id);
    }

    public function map(string $id)
    {
        $this->guard();
        $import = $this->find($id);

        $csv     = new CsvFile($import->stored_path, $import->delimiter, $import->encoding);
        $preview = $csv->preview($import->has_header);
        $stats   = $csv->stats($import->has_header);

        $fields  = ImportFieldRegistry::for($import->type);

        // Guess once, then remember whatever the person chose.
        $mapping = $import->mapping ?? [];
        if (! $mapping) {
            foreach ($preview['header'] as $i => $h) {
                $guess = ImportFieldRegistry::guess($import->type, $h);
                if ($guess) { $mapping[$i] = ['field' => $guess, 'dir' => null]; }
            }
            $import->update(['columns' => $preview['header'], 'mapping' => $mapping]);
        }

        return view('tenant.imports.map', compact('import', 'preview', 'stats', 'fields', 'mapping'));
    }

    public function saveMapping(Request $request, string $id)
    {
        $this->guard();
        $import = $this->find($id);

        $fields = ImportFieldRegistry::for($import->type);
        $map    = [];

        foreach ((array) $request->input('field', []) as $idx => $field) {
            if (! $field || ! isset($fields[$field])) { continue; }
            $dir = $request->input('dir.' . $idx);
            $map[(int) $idx] = [
                'field' => $field,
                'dir'   => in_array($dir, ['csv', 'keep', 'blank'], true) ? $dir : null,
            ];
        }

        $used = array_column($map, 'field');
        $match = ImportFieldRegistry::matchField($import->type);
        if (! in_array($match, $used, true)) {
            return back()->with('error',
                'Map a column to ' . ($fields[$match]['label'] ?? $match) .
                ' — it is how an existing record is recognised.');
        }

        $import->update([
            'mapping' => $map,
            'options' => array_merge((array) $import->options, [
                'mode'      => in_array($request->input('mode'), ['upsert', 'insert', 'update'], true)
                               ? $request->input('mode') : 'upsert',
                'direction' => in_array($request->input('direction'), ['csv', 'keep', 'blank'], true)
                               ? $request->input('direction') : 'csv',
            ]),
        ]);

        return redirect()->route('tenant.imports.preview', $import->id);
    }

    public function preview(string $id)
    {
        $this->guard();
        $import = $this->find($id);

        $result = (new CustomerImporter(tenant(), $import))->preview();
        $import->update(['status' => 'previewed']);

        return view('tenant.imports.preview', compact('import', 'result'));
    }

    public function run(string $id)
    {
        $this->guard();
        $import = $this->find($id);

        $import->update(['status' => 'running', 'started_at' => now()]);

        try {
            $result = (new CustomerImporter(tenant(), $import))->run();
        } catch (\Throwable $e) {
            $import->update(['status' => 'failed', 'failure_reason' => $e->getMessage(),
                             'finished_at' => now()]);
            \Log::error('customer import failed', ['import' => $import->id, 'error' => $e->getMessage()]);

            return redirect()->route('tenant.imports.show', $import->id)
                ->with('error', 'The import stopped: ' . $e->getMessage());
        }

        $errorPath = null;
        if ($result['errorRows']) {
            $errorPath = $this->writeErrorCsv($import, $result['errorRows']);
        }

        $import->update([
            'status'      => 'done',
            'totals'      => $result['counts'],
            'error_path'  => $errorPath,
            'finished_at' => now(),
        ]);

        return redirect()->route('tenant.imports.show', $import->id);
    }

    /** Original columns + a reason column, so it can be fixed and re-imported. */
    private function writeErrorCsv(TenantImport $import, array $rows): string
    {
        $rel = 'imports/' . $import->tenant_id . '/errors-' . $import->id . '.csv';
        $abs = Storage::disk('local')->path($rel);
        @mkdir(dirname($abs), 0775, true);

        $h = fopen($abs, 'w');
        $header = $import->columns ?? [];
        if ($header) { fputcsv($h, array_merge($header, ['Why it was skipped'])); }
        foreach ($rows as [$cells, $why]) {
            fputcsv($h, array_merge((array) $cells, [$why]));
        }
        fclose($h);

        return $abs;
    }

    public function show(string $id)
    {
        $this->guard();

        return view('tenant.imports.show', ['import' => $this->find($id)]);
    }

    public function errors(string $id)
    {
        $this->guard();
        $import = $this->find($id);

        abort_unless($import->error_path && is_file($import->error_path), 404);

        return response()->download($import->error_path,
            'import-errors-' . $import->original_filename);
    }
}
EOF
echo "ok   controller created"; fi

# ================================================================ views
cat <<'EOF' > "$VDIR/index.blade.php"
@extends('layouts.tenant.app')
@php $pageTitle = 'Import'; @endphp
{{-- MARKER-IMPORT1 --}}

@section('content')
@include('tenant.imports._styles')

@if(session('error'))<div class="ia-flash ia-flash--error" style="margin-bottom:14px">{{ session('error') }}</div>@endif
@if(session('success'))<div class="ia-flash ia-flash--success" style="margin-bottom:14px">{{ session('success') }}</div>@endif

<div class="ia-page-head">
  <div>
    <h1 class="ia-page-title">Import</h1>
    <p class="ia-page-subtitle">Bring customers in from a spreadsheet.</p>
  </div>
  <div class="ia-page-actions">
    <a href="{{ route('tenant.imports.create') }}" class="ia-btn ia-btn--primary">+ New import</a>
  </div>
</div>

<div class="ia-card">
  <div class="ia-card-head"><span class="ia-card-title">Past imports</span></div>
  @if($imports->isEmpty())
    <div class="imp-empty">Nothing imported yet.</div>
  @else
    <table class="imp">
      <thead><tr>
        <th>When</th><th>File</th><th>Type</th><th>Created</th><th>Updated</th><th>Skipped</th><th>Status</th><th></th>
      </tr></thead>
      <tbody>
        @foreach($imports as $imp)
          <tr>
            <td>{{ tlocal_datetime($imp->created_at, 'M j, g:i A') }}</td>
            <td class="mono">{{ $imp->original_filename }}</td>
            <td style="text-transform:capitalize">{{ $imp->type }}</td>
            <td><b>{{ number_format($imp->total('created')) }}</b></td>
            <td>{{ number_format($imp->total('updated')) }}</td>
            <td>{{ number_format($imp->total('errors') + $imp->total('unmatched')) }}</td>
            <td><span class="chip chip--{{ $imp->status }}">{{ $imp->status }}</span></td>
            <td style="text-align:right">
              <a href="{{ route('tenant.imports.show', $imp->id) }}" class="ia-btn ia-btn--secondary ia-btn--sm">View</a>
            </td>
          </tr>
        @endforeach
      </tbody>
    </table>
  @endif
</div>
@endsection
EOF
echo "ok   index view"

cat <<'EOF' > "$VDIR/create.blade.php"
@extends('layouts.tenant.app')
@php $pageTitle = 'New import'; @endphp
{{-- MARKER-IMPORT1 --}}

@section('content')
@include('tenant.imports._styles')

@if($errors->any())<div class="ia-flash ia-flash--error" style="margin-bottom:14px">{{ $errors->first() }}</div>@endif

<div class="ia-page-head">
  <div>
    <h1 class="ia-page-title">New import</h1>
    <p class="ia-page-subtitle">Upload a CSV. Nothing is written until you've seen a preview.</p>
  </div>
  <div class="ia-page-actions">
    <a href="{{ route('tenant.imports.index') }}" class="ia-btn ia-btn--ghost">Cancel</a>
  </div>
</div>

<form method="POST" action="{{ route('tenant.imports.store') }}" enctype="multipart/form-data">
  @csrf
  <input type="hidden" name="type" value="customers">

  <div class="ia-card">
    <div class="ia-card-head"><span class="ia-card-title">Customers</span></div>
    <div class="ia-card-body">
      <p style="font-size:12.5px;color:var(--ia-text-dim);margin-bottom:16px">
        Names, contact details, address, notes, VIP flag, and the business fields — business name,
        tax exemption, payment terms, PO required. Matched on email address.
      </p>

      <div class="imp-drop">
        <input type="file" name="file" accept=".csv,.txt" required class="ia-input" style="max-width:420px;margin:0 auto">
        <p style="margin-top:10px">CSV or tab-separated · up to 20&nbsp;MB</p>
      </div>

      <p class="imp-hint" style="margin-top:14px">
        Passwords, SMS consent and Stripe ids can't be imported. Consent has to be evidenced, not assigned.
      </p>
    </div>
  </div>

  <div class="imp-foot">
    <span></span>
    <button type="submit" class="ia-btn ia-btn--primary">Upload and map fields</button>
  </div>
</form>
@endsection
EOF
echo "ok   create view"

cat <<'EOF' > "$VDIR/map.blade.php"
@extends('layouts.tenant.app')
@php $pageTitle = 'Map fields'; @endphp
{{-- MARKER-IMPORT1 --}}

@section('content')
@include('tenant.imports._styles')

@if(session('error'))<div class="ia-flash ia-flash--error" style="margin-bottom:14px">{{ session('error') }}</div>@endif

<div class="ia-page-head">
  <div>
    <h1 class="ia-page-title">Map your columns</h1>
    <p class="ia-page-subtitle mono">{{ $import->original_filename }} ·
      {{ number_format($stats['rows']) }} rows · {{ count($preview['header']) }} columns</p>
  </div>
</div>

@if($stats['ragged'] > 0)
  <div class="ia-flash ia-flash--error" style="margin-bottom:14px">
    {{ $stats['ragged'] }} {{ Str::plural('row', $stats['ragged']) }} have a different number of columns
    than the header. They'll still be read, but check them in the preview.
  </div>
@endif

<div class="ia-flash ia-flash--info" style="margin-bottom:14px">
  <b>Email is required</b> — it's how an existing customer is recognised. Anything you leave unmapped is ignored.
</div>

<form method="POST" action="{{ route('tenant.imports.mapping', $import->id) }}">
  @csrf

  <div class="ia-card">
    <div class="ia-card-head"><span class="ia-card-title">{{ count($preview['header']) }} columns</span></div>
    <div class="imp-scroll">
      <table class="imp">
        <thead><tr>
          <th style="width:170px">Your column</th>
          <th style="width:210px">Sample</th>
          <th style="width:230px">Intake field</th>
          <th style="width:200px">When it already has a value</th>
        </tr></thead>
        <tbody>
          @foreach($preview['header'] as $i => $head)
            @php
              $chosen = $mapping[$i]['field'] ?? null;
              $dir    = $mapping[$i]['dir'] ?? '';
              $sample = $preview['sample'][0][$i] ?? '';
            @endphp
            <tr>
              <td class="mono">{{ $head !== '' ? $head : 'Column ' . ($i + 1) }}</td>
              <td><span class="imp-sample">{{ Str::limit((string) $sample, 40) }}</span></td>
              <td>
                <select name="field[{{ $i }}]" class="imp-sel">
                  <option value="">— ignore this column —</option>
                  @foreach($fields as $key => $def)
                    <option value="{{ $key }}" @selected($chosen === $key)>{{ $def['label'] }}</option>
                  @endforeach
                </select>
              </td>
              <td>
                <select name="dir[{{ $i }}]" class="imp-dir">
                  <option value="" @selected($dir === '')>Use the default</option>
                  <option value="csv" @selected($dir === 'csv')>File wins</option>
                  <option value="keep" @selected($dir === 'keep')>Keep existing</option>
                  <option value="blank" @selected($dir === 'blank')>Only fill blanks</option>
                </select>
              </td>
            </tr>
          @endforeach
        </tbody>
      </table>
    </div>
  </div>

  <div class="imp-two">
    <div class="ia-card">
      <div class="ia-card-head"><span class="ia-card-title">Existing customers</span></div>
      <div class="ia-card-body">
        <label class="imp-radio"><input type="radio" name="mode" value="upsert" checked>
          <span><b>Add and update</b><span>New emails are created; ones you already have are merged.</span></span></label>
        <label class="imp-radio"><input type="radio" name="mode" value="insert">
          <span><b>Add only</b><span>Existing customers are left alone and reported as skipped.</span></span></label>
        <label class="imp-radio"><input type="radio" name="mode" value="update">
          <span><b>Update only</b><span>Nothing new is created. Rows with no match are listed, not dropped.</span></span></label>
      </div>
    </div>

    <div class="ia-card">
      <div class="ia-card-head"><span class="ia-card-title">Default merge direction</span></div>
      <div class="ia-card-body">
        <label class="imp-radio"><input type="radio" name="direction" value="csv" checked>
          <span><b>File wins</b><span>Your spreadsheet is the source of truth for every mapped field.</span></span></label>
        <label class="imp-radio"><input type="radio" name="direction" value="blank">
          <span><b>Only fill blanks</b><span>Adds what's missing, never overwrites what someone typed.</span></span></label>
        <label class="imp-radio"><input type="radio" name="direction" value="keep">
          <span><b>Keep existing</b><span>Reference only — useful for a dry comparison.</span></span></label>
        <p class="imp-hint" style="margin-top:8px">Any column above can override this for itself.</p>
      </div>
    </div>
  </div>

  <div class="imp-foot">
    <a href="{{ route('tenant.imports.index') }}" class="ia-btn ia-btn--secondary">Cancel</a>
    <button type="submit" class="ia-btn ia-btn--primary">Check the file</button>
  </div>
</form>
@endsection
EOF
echo "ok   map view"

cat <<'EOF' > "$VDIR/preview.blade.php"
@extends('layouts.tenant.app')
@php $pageTitle = 'Preview import'; @endphp
{{-- MARKER-IMPORT1 --}}

@section('content')
@include('tenant.imports._styles')

@php $c = $result['counts']; $writes = ($c['create'] ?? 0) + ($c['update'] ?? 0); @endphp

<div class="ia-page-head">
  <div>
    <h1 class="ia-page-title">Preview</h1>
    <p class="ia-page-subtitle">Nothing has been written yet. This is exactly what will happen.</p>
  </div>
</div>

<div class="imp-tiles">
  <div class="imp-tile"><div class="k">Will be created</div><div class="v ok">{{ number_format($c['create'] ?? 0) }}</div></div>
  <div class="imp-tile"><div class="k">Will be updated</div><div class="v acc">{{ number_format($c['update'] ?? 0) }}</div></div>
  <div class="imp-tile"><div class="k">Already match</div><div class="v dim">{{ number_format($c['unchanged'] ?? 0) }}</div></div>
  <div class="imp-tile"><div class="k">Skipped</div><div class="v dim">{{ number_format($c['skipped'] ?? 0) }}</div></div>
  <div class="imp-tile"><div class="k">No match</div><div class="v dim">{{ number_format($c['unmatched'] ?? 0) }}</div></div>
  <div class="imp-tile"><div class="k">Errors</div><div class="v bad">{{ number_format($c['error'] ?? 0) }}</div></div>
</div>

@if(($c['error'] ?? 0) > 0)
  <div class="ia-flash ia-flash--error" style="margin-bottom:14px">
    {{ number_format($c['error']) }} {{ Str::plural('row', $c['error']) }} can't be imported and will be
    skipped. Everything else still goes in — you can download the skipped rows afterwards, fix them,
    and import that file straight back.
  </div>
@endif

<div class="ia-card">
  <div class="ia-card-head"><span class="ia-card-title">Row by row</span>
    <span style="margin-left:auto;font-size:11.5px;color:var(--ia-text-dim)">first {{ count($result['sample']) }} rows</span></div>
  <div class="imp-scroll">
    <table class="imp">
      <thead><tr><th style="width:60px">Line</th><th style="width:220px">Email</th><th>Name</th><th style="width:280px">Outcome</th></tr></thead>
      <tbody>
        @foreach($result['sample'] as $row)
          <tr>
            <td class="mono">{{ $row['line'] }}</td>
            <td class="mono">{{ $row['key'] }}</td>
            <td>{{ $row['label'] }}</td>
            <td>
              <span class="chip chip--{{ $row['outcome'] }}">{{ str_replace('_',' ', $row['outcome']) }}</span>
              @if($row['errors'])
                <div class="imp-err">{{ implode(' · ', $row['errors']) }}</div>
              @elseif($row['outcome'] === 'update' && $row['changes'])
                <span class="imp-changes">{{ implode(', ', $row['changes']) }}</span>
              @endif
            </td>
          </tr>
        @endforeach
      </tbody>
    </table>
  </div>
</div>

<form method="POST" action="{{ route('tenant.imports.run', $import->id) }}" class="imp-foot">
  @csrf
  <a href="{{ route('tenant.imports.map', $import->id) }}" class="ia-btn ia-btn--secondary">Back to mapping</a>
  <button type="submit" class="ia-btn ia-btn--primary" @disabled($writes === 0)>
    Import {{ number_format($writes) }} {{ Str::plural('row', $writes) }}
  </button>
</form>
@endsection
EOF
echo "ok   preview view"

cat <<'EOF' > "$VDIR/show.blade.php"
@extends('layouts.tenant.app')
@php $pageTitle = 'Import result'; @endphp
{{-- MARKER-IMPORT1 --}}

@section('content')
@include('tenant.imports._styles')

@if(session('error'))<div class="ia-flash ia-flash--error" style="margin-bottom:14px">{{ session('error') }}</div>@endif

<div class="ia-page-head">
  <div>
    <h1 class="ia-page-title">
      {{ $import->status === 'done' ? 'Import finished' : 'Import ' . $import->status }}
    </h1>
    <p class="ia-page-subtitle mono">{{ $import->original_filename }}</p>
  </div>
  <div class="ia-page-actions">
    <a href="{{ route('tenant.customers.index') }}" class="ia-btn ia-btn--secondary">View customers</a>
    <a href="{{ route('tenant.imports.index') }}" class="ia-btn ia-btn--primary">Done</a>
  </div>
</div>

@if($import->status === 'failed')
  <div class="ia-flash ia-flash--error">{{ $import->failure_reason }}</div>
@elseif($import->status === 'done')
  <div class="ia-flash ia-flash--success">
    {{ number_format($import->total('created') + $import->total('updated')) }} rows imported.
  </div>
@endif

<div class="imp-tiles">
  <div class="imp-tile"><div class="k">Created</div><div class="v ok">{{ number_format($import->total('created')) }}</div></div>
  <div class="imp-tile"><div class="k">Updated</div><div class="v acc">{{ number_format($import->total('updated')) }}</div></div>
  <div class="imp-tile"><div class="k">Already matched</div><div class="v dim">{{ number_format($import->total('unchanged')) }}</div></div>
  <div class="imp-tile"><div class="k">Skipped</div><div class="v dim">{{ number_format($import->total('skipped')) }}</div></div>
  <div class="imp-tile"><div class="k">No match</div><div class="v dim">{{ number_format($import->total('unmatched')) }}</div></div>
  <div class="imp-tile"><div class="k">Errors</div><div class="v bad">{{ number_format($import->total('errors')) }}</div></div>
</div>

@if($import->error_path)
  <div class="ia-card">
    <div class="ia-card-head"><span class="ia-card-title">Skipped rows</span>
      <a href="{{ route('tenant.imports.errors', $import->id) }}"
         class="ia-btn ia-btn--secondary ia-btn--sm" style="margin-left:auto">Download as CSV</a></div>
    <div class="ia-card-body imp-hint">
      Keeps your original columns and adds a reason column, so you can fix it in the spreadsheet
      and import that file straight back.
    </div>
  </div>
@endif
@endsection
EOF
echo "ok   show view"

cat <<'EOF' > "$VDIR/_styles.blade.php"
{{-- MARKER-IMPORT1 — shared importer styling, all off the --ia-* theme vars --}}
<style>
.imp{width:100%;border-collapse:collapse;font-size:12.5px}
.imp th{text-align:left;font-size:10.5px;text-transform:uppercase;letter-spacing:.06em;
        color:var(--ia-text-dim);font-weight:600;padding:9px 14px;border-bottom:.5px solid var(--ia-border)}
.imp td{padding:9px 14px;border-bottom:.5px solid rgba(255,255,255,.06);vertical-align:middle}
.imp tr:last-child td{border-bottom:0}
.imp-scroll{max-height:460px;overflow-y:auto}
.imp-sample{color:var(--ia-text-dim);font-size:11.5px;font-family:ui-monospace,monospace;
            white-space:nowrap;overflow:hidden;text-overflow:ellipsis;max-width:200px;display:block}
.imp-sel,.imp-dir{font-size:12.5px;padding:6px 9px;border-radius:6px;border:.5px solid var(--ia-border);
        background:var(--ia-input-bg);color:var(--ia-text);font-family:inherit;width:100%}
.imp-drop{border:1.5px dashed var(--ia-border-strong);border-radius:var(--ia-r-lg);padding:30px 20px;
          text-align:center;background:rgba(255,255,255,.02);font-size:12.5px;color:var(--ia-text-dim)}
.imp-two{display:grid;grid-template-columns:1fr 1fr;gap:16px;margin-top:16px}
@media(max-width:760px){.imp-two{grid-template-columns:1fr}}
.imp-radio{display:flex;gap:9px;align-items:flex-start;padding:8px 0;cursor:pointer}
.imp-radio input{margin-top:3px;accent-color:var(--ia-accent)}
.imp-radio b{font-weight:600;font-size:13px;display:block}
.imp-radio span span{font-size:11.5px;color:var(--ia-text-dim);display:block;margin-top:1px;line-height:1.45}
.imp-tiles{display:grid;grid-template-columns:repeat(auto-fit,minmax(140px,1fr));gap:12px;margin-bottom:16px}
.imp-tile{background:var(--ia-surface);border-radius:var(--ia-r-lg);padding:14px 16px;
          box-shadow:inset 0 0 0 .5px var(--ia-border)}
.imp-tile .k{font-size:11px;color:var(--ia-text-dim)}
.imp-tile .v{font-size:23px;font-weight:700;margin-top:2px;line-height:1}
.imp-tile .v.ok{color:#7FD98F}.imp-tile .v.acc{color:var(--ia-accent)}
.imp-tile .v.bad{color:#F09595}.imp-tile .v.dim{color:var(--ia-text-dim)}
.imp-foot{display:flex;justify-content:space-between;gap:10px;margin-top:18px}
.imp-hint{font-size:11.5px;color:var(--ia-text-dim);line-height:1.55}
.imp-empty{padding:28px;text-align:center;font-size:13px;color:var(--ia-text-dim)}
.imp-err{font-size:11.5px;color:#F09595;margin-top:3px}
.imp-changes{font-size:11px;color:var(--ia-text-dim);margin-left:6px}
.chip{display:inline-flex;font-size:10px;font-weight:800;letter-spacing:.05em;text-transform:uppercase;
      padding:3px 8px;border-radius:100px;white-space:nowrap}
.chip--create,.chip--done{background:rgba(127,217,143,.13);color:#7FD98F;border:.5px solid rgba(127,217,143,.3)}
.chip--update,.chip--previewed{background:rgba(190,242,100,.10);color:var(--ia-accent);border:.5px solid rgba(190,242,100,.3)}
.chip--error,.chip--failed{background:rgba(240,149,149,.11);color:#F09595;border:.5px solid rgba(240,149,149,.3)}
.chip--unchanged,.chip--skipped,.chip--unmatched,.chip--draft,.chip--running{
      background:rgba(255,255,255,.05);color:var(--ia-text-dim);border:.5px solid rgba(255,255,255,.1)}
.mono{font-family:ui-monospace,monospace;font-size:12px}
</style>
EOF
echo "ok   shared styles"

# ================================================================ capability
python3 - "$CAP" <<'PY'
import sys
path = sys.argv[1]
src = open(path).read()

old = """            // ---- Customers ---- MARKER-CUST-ACCOUNT"""
new = """            // ---- Customers ---- MARKER-IMPORT1
            'customers.import' => [
                'label'   => 'Import from a spreadsheet',
                'section' => 'customers',
                'desc'    => 'Upload a CSV to create or update customers in bulk.',
                'gate'    => null,
                'default_roles' => ['Manager'],
            ],

            // ---- Customers ---- MARKER-CUST-ACCOUNT"""

n = src.count(old)
if n != 1:
    print(f"FAIL capability: anchor found {n} times"); sys.exit(1)
src = src.replace(old, new, 1)
print("ok   capability customers.import")

open(path, 'w').write(src)
PY

# ================================================================ routes
python3 - "$ROUTES" <<'PY'
import sys
path = sys.argv[1]
src = open(path).read()

old = """            Route::get('/customers',            [TenantControllers\\CustomerController::class, 'index'])->name('customers.index');"""
new = """            // MARKER-IMPORT1 — CSV import wizard (capability-gated in the controller)
            Route::get('/imports',                      [TenantControllers\\ImportController::class, 'index'])->name('imports.index');
            Route::get('/imports/new',                  [TenantControllers\\ImportController::class, 'create'])->name('imports.create');
            Route::post('/imports',                     [TenantControllers\\ImportController::class, 'store'])->name('imports.store');
            Route::get('/imports/{id}/map',             [TenantControllers\\ImportController::class, 'map'])->name('imports.map');
            Route::post('/imports/{id}/map',            [TenantControllers\\ImportController::class, 'saveMapping'])->name('imports.mapping');
            Route::get('/imports/{id}/preview',         [TenantControllers\\ImportController::class, 'preview'])->name('imports.preview');
            Route::post('/imports/{id}/run',            [TenantControllers\\ImportController::class, 'run'])->name('imports.run');
            Route::get('/imports/{id}',                 [TenantControllers\\ImportController::class, 'show'])->name('imports.show');
            Route::get('/imports/{id}/errors',          [TenantControllers\\ImportController::class, 'errors'])->name('imports.errors');

            Route::get('/customers',            [TenantControllers\\CustomerController::class, 'index'])->name('customers.index');"""

n = src.count(old)
if n != 1:
    print(f"FAIL routes: anchor found {n} times"); sys.exit(1)
src = src.replace(old, new, 1)
print("ok   import routes")

open(path, 'w').write(src)
PY

# ================================================================ nav
python3 - "$NAV" <<'PY'
import sys
path = sys.argv[1]
src = open(path).read()

old = """      'route'  => 'tenant.customers.index',
      'label'  => 'Customers',"""

if src.count(old) != 1:
    print(f"FAIL nav anchor: found {src.count(old)} times"); sys.exit(1)

# Add the Import entry to the settings group, not next to Customers — it is an
# occasional administrative action, not a daily surface.
anchor = """    // MARKER-PATCH-570 — storefront settings (online store control panel)"""
new = """    // MARKER-IMPORT1 — CSV import lives with the other setup surfaces
    [
      'route'  => 'tenant.imports.index',
      'label'  => 'Import',
      'icon'   => '<svg width="14" height="14" viewBox="0 0 14 14" fill="none"><path d="M7 1.5v7M4.5 6L7 8.5 9.5 6" stroke="currentColor" stroke-width="1.2" stroke-linecap="round" stroke-linejoin="round"/><path d="M2 10.5v1a1 1 0 0 0 1 1h8a1 1 0 0 0 1-1v-1" stroke="currentColor" stroke-width="1.2" stroke-linecap="round"/></svg>',
      'group'  => 'settings',
    ],
""" + anchor

if src.count(anchor) != 1:
    print(f"FAIL nav insert point: found {src.count(anchor)} times"); sys.exit(1)
src = src.replace(anchor, new, 1)
print("ok   nav entry")

open(path, 'w').write(src)
PY

php -l "$REG"
php -l "$CSV"
php -l "$RUN"
php -l "$MODEL"
php -l "$CTRL"

echo ""
echo "SUCCESS — apply-import-suite-1-engine applied."
echo "Customers import is live at /imports. Grant 'Import from a spreadsheet'"
echo "in Roles & access (Owner always has it)."
