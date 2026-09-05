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
    // MARKER-IMPORT-MERGE — conflict analysis for the merge review screen.
    use AnalysesConflicts;

    public const CHUNK = 200;

    /**
     * MARKER-CUSTOMER-TAGS — the tag for this import, made once and remembered.
     * firstOrCreate per row would be 13,000 lookups for one answer.
     */
    private ?string $tagIdMemo = null;
    private bool $tagResolved = false;

    private function importTagId(): ?string
    {
        if ($this->tagResolved) {
            return $this->tagIdMemo;
        }
        $this->tagResolved = true;

        $name = (string) (($this->import->options['tag_name'] ?? '') ?: '');
        if (trim($name) === '') {
            return $this->tagIdMemo = null;
        }

        $this->tagIdMemo = \App\Models\Tenant\TenantCustomerTag::findOrCreateFor(
            $this->tenant->id, $name
        )->id;

        return $this->tagIdMemo;
    }

    public function __construct(private Tenant $tenant, private TenantImport $import) {}

    /**
     * MARKER-IMPORT-TAG-ALL — does this outcome earn the tag?
     *
     *   created  — new customers only (the default)
     *   touched  — created or actually changed
     *   all      — everyone in the file who resolves to a customer, including
     *              rows that matched and needed no changes. This is the whole
     *              point of re-sending a list you already imported.
     *
     * 'error' and 'unmatched' never tag: there is no record to tag.
     */
    private function tagWants(string $outcome): bool
    {
        $scope = $this->import->options['tag_scope'] ?? 'created';

        return match ($scope) {
            'all'     => in_array($outcome, ['create', 'update', 'unchanged', 'skipped'], true),
            'touched' => in_array($outcome, ['create', 'update'], true),
            default   => $outcome === 'create',
        };
    }

    /**
     * MARKER-IMPORT-TAG-ALL — one insert per batch rather than one per
     * customer. insertOrIgnore keeps it idempotent, so re-running the same
     * file never duplicates a pivot row.
     */
    private function flushTags(array $customerIds, ?string $tagId): int
    {
        $customerIds = array_values(array_unique(array_filter($customerIds)));
        if (! $customerIds || ! $tagId) return 0;

        $rows = array_map(fn ($cid) => [
            'id'          => (string) \Illuminate\Support\Str::uuid(),
            'tenant_id'   => $this->tenant->id,
            'tag_id'      => $tagId,
            'customer_id' => $cid,
            'created_at'  => now(),
        ], $customerIds);

        foreach (array_chunk($rows, 500) as $chunk) {
            \Illuminate\Support\Facades\DB::table('tenant_customer_tag_pivot')->insertOrIgnore($chunk);
        }

        return count($customerIds);
    }

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

            // MARKER-IMPORT-PHONE — store one shape, the same one every
            // hand-entered number gets. Without this the table ends up with
            // (509) 555-1234 and 5095551234 as different-looking values.
            case 'phone':
                $normalized = \App\Support\PhoneNumber::normalize($raw);
                if ($normalized === null) {
                    // Unusable, but not worth failing the row over — keep
                    // what they had rather than throwing customer data away.
                    return [$raw, null];
                }
                return [$normalized, null];

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
    public function buildRow(array $cells, ?TenantCustomer $existing, ?int $line = null): array
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
            $dirs[$m['field']]   = $this->rowDirection($m['field'], $dirs[$m['field']], $line);
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
                   'skipped' => 0, 'unmatched' => 0, 'error' => 0,
                   'will_tag' => 0]; // MARKER-PREVIEW-TAGS
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
                $row = $this->buildRow($b['cells'], $existing[$b['key']] ?? null, $b['line']);
                $counts[$row['outcome']] = ($counts[$row['outcome']] ?? 0) + 1;

                // MARKER-PREVIEW-TAGS — same rule the write path uses, so the
                // preview can't promise a tag run() won't apply. 'create' has
                // no record yet but will have one; everything else needs a match.
                if ($this->tagWants($row['outcome'])
                    && ($row['outcome'] === 'create' || $row['match'])) {
                    $counts['will_tag']++;
                }

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

        // MARKER-PREVIEW-TAGS — the name too, so the button can say it.
        return ['counts' => $counts, 'sample' => $sample,
                'tag_name' => trim((string) ($this->import->options['tag_name'] ?? '')) ?: null];
    }

    /** Write it. Each chunk is its own transaction. */
    public function run(): array
    {
        $csv = new CsvFile($this->import->stored_path, $this->import->delimiter, $this->import->encoding);

        $counts = ['created' => 0, 'updated' => 0, 'unchanged' => 0,
                   'skipped' => 0, 'unmatched' => 0, 'errors' => 0,
                   'tagged' => 0]; // MARKER-IMPORT-TAG-ALL
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

            // MARKER-IMPORT-TAG-ALL — ids gathered here, written once below.
            $toTag = [];

            DB::transaction(function () use ($batch, $existing, &$counts, &$errorRows, &$toTag) {
                foreach ($batch as $b) {
                    $row = $this->buildRow($b['cells'], $existing[$b['key']] ?? null, $b['line']);

                    // MARKER-IMPORT-TAG-ALL — decided once per row, for every
                    // outcome, instead of inside two of the branches. 'create'
                    // has no id until it is made, so it is added there.
                    if ($row['outcome'] !== 'create' && $row['match'] && $this->tagWants($row['outcome'])) {
                        $toTag[] = $row['match']->id;
                    }

                    switch ($row['outcome']) {
                        case 'create':
                            // MARKER-IMPORT2 — ledger the creation so it can be undone
                            // MARKER-CUSTOMER-EMAIL-NULLABLE — name the column
                            // explicitly. Omitting it leans on a default the
                            // table does not have, which is what threw 1364.
                            // MARKER-CONSENT-IMPORT-FIX — name EVERY column the
                            // table requires. 'email' was named; first_name and
                            // last_name were not, so a CSV without a last-name
                            // column threw 1364 and killed the whole run.
                            $made = TenantCustomer::create(array_merge(
                                ['email' => null, 'first_name' => '', 'last_name' => ''],
                                $row['values'],
                                ['tenant_id' => $this->tenant->id],
                            ));

                            // MARKER-CUSTOMER-TAGS / MARKER-IMPORT-TAG-ALL —
                            // collected with the rest of the batch and written
                            // in one insert after the transaction.
                            if ($this->tagWants('create')) {
                                $toTag[] = $made->id;
                            }
                            \App\Models\Tenant\TenantImportRow::create([
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

                            // MARKER-IMPORT-TAG-CARD / MARKER-IMPORT-TAG-ALL —
                            // handled by the collector above, which covers
                            // unchanged and skipped rows too.
                            \App\Models\Tenant\TenantImportRow::create([
                                'import_id' => $this->import->id, 'tenant_id' => $this->tenant->id,
                                'action' => 'updated', 'record_type' => 'customer',
                                'record_id' => $row['match']->id, 'before' => $before, 'created_at' => now(),
                            ]);
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

            // MARKER-IMPORT-TAG-ALL — outside the transaction: a tag write must
            // never roll back the import it describes.
            $counts['tagged'] += $this->flushTags($toTag, $this->importTagId());

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
