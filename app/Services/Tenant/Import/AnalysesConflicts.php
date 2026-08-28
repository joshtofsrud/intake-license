<?php

namespace App\Services\Tenant\Import;

use App\Support\ImportFieldRegistry;

/**
 * MARKER-IMPORT-MERGE — conflict analysis, shared by both importers.
 *
 * Answers "which fields does this file disagree with us about, and on how
 * many rows" BEFORE any direction rule is applied — which is the whole
 * point, since the direction is what the person is about to choose.
 *
 * It reuses the importer's own mapping(), cast() and lookup(), so what it
 * reports here is what buildRow() will act on later. It writes nothing.
 */
trait AnalysesConflicts
{
    /** Fields that can never be in conflict: the key, and the pseudo-fields. */
    private function diffableFields(): array
    {
        $match  = ImportFieldRegistry::matchField($this->import->type);
        $pseudo = defined(static::class . '::PSEUDO') ? (array) static::PSEUDO : [];
        $out    = [];

        foreach ($this->fields() as $key => $def) {
            if ($key === $match) { continue; }
            if (($def['type'] ?? '') === 'resolve') { continue; }   // category/vendor resolve a name
            if (! empty($def['stock'])) { continue; }               // stock is a movement, not an overwrite
            if (in_array($key, $pseudo, true)) { continue; }        // the importer's own pseudo-fields
            $out[$key] = $def;
        }

        return $out;
    }

    /** How a value should read on screen. */
    public function displayValue(string $field, $val): string
    {
        if ($val === null || $val === '') { return '— empty'; }

        $def = $this->fields()[$field] ?? [];

        if (is_bool($val))                        { return $val ? 'Yes' : 'No'; }
        if (($def['type'] ?? '') === 'money')     { return '$' . number_format(((int) $val) / 100, 2); }

        return (string) $val;
    }

    /** Differences between one file row and the record it matched. */
    private function diffRow(array $cells, $existing): array
    {
        $diffable = $this->diffableFields();
        $out      = [];

        foreach ($this->mapping() as $idx => $m) {
            $field = $m['field'];
            if (! isset($diffable[$field])) { continue; }

            [$val, $err] = $this->cast($field, (string) ($cells[$idx] ?? ''));

            // A row that can't be cast is an error, not a conflict; a blank
            // incoming value never overwrites, so it is never a conflict.
            if ($err !== null || $val === null) { continue; }

            $currentRaw = $existing->{$field} ?? null;
            $current    = is_bool($currentRaw) ? $currentRaw : (string) ($currentRaw ?? '');
            $incoming   = is_bool($val) ? $val : (string) $val;

            if ($current === $incoming) { continue; }

            $out[$field] = [
                'current'  => $currentRaw,
                'incoming' => $val,
                'blank'    => $currentRaw === null || $currentRaw === '',
            ];
        }

        return $out;
    }

    /** Something to call the matched record on screen. */
    private function recordLabel($record): string
    {
        if ($this->import->type === 'inventory') {
            return trim((string) ($record->name ?? '')) ?: (string) ($record->sku ?? '—');
        }

        $name = trim(((string) ($record->first_name ?? '')) . ' ' . ((string) ($record->last_name ?? '')));

        return $name
            ?: (trim((string) ($record->business_name ?? '')) ?: (string) ($record->email ?? '—'));
    }

    /**
     * One streaming pass. $emit($line, $existing, $diffs) is called for every
     * MATCHED row; returning false from it stops the scan early.
     *
     * @return array{matched:int,identical:int,new:int}
     */
    private function scanConflicts(callable $emit): array
    {
        $counts = ['matched' => 0, 'identical' => 0, 'new' => 0];

        $matchField = ImportFieldRegistry::matchField($this->import->type);
        $matchIdx   = null;
        foreach ($this->mapping() as $idx => $m) {
            if ($m['field'] === $matchField) { $matchIdx = $idx; }
        }
        if ($matchIdx === null) { return $counts; }

        // Insert-only never merges anything, so nothing can be in conflict.
        if ($this->option('mode', 'upsert') === 'insert') { return $counts; }

        $csv   = new CsvFile($this->import->stored_path, $this->import->delimiter, $this->import->encoding);
        $first = true;
        $stop  = false;
        $batch = [];

        $flush = function () use (&$batch, &$counts, &$stop, $emit) {
            if (! $batch) { return; }
            if ($stop)    { $batch = []; return; }

            $existing = $this->lookup(array_map(fn ($b) => $b['key'], $batch));

            foreach ($batch as $b) {
                $found = $existing[$b['key']] ?? null;
                if (! $found) { $counts['new']++; continue; }

                $counts['matched']++;
                $diffs = $this->diffRow($b['cells'], $found);
                if (! $diffs) { $counts['identical']++; continue; }

                if ($emit($b['line'], $found, $diffs) === false) { $stop = true; break; }
            }

            $batch = [];
        };

        foreach ($csv->rows() as [$line, $cells]) {
            if ($first && $this->import->has_header) { $first = false; continue; }
            $first = false;

            $key = strtolower(trim((string) ($cells[$matchIdx] ?? '')));
            if ($key === '') { $counts['new']++; continue; }

            $batch[] = ['line' => $line, 'cells' => $cells, 'key' => $key];
            if (count($batch) >= self::CHUNK) { $flush(); }
            if ($stop) { break; }
        }

        $flush();

        return $counts;
    }

    /**
     * Conflicts grouped by field, with a few sample rows each.
     *
     * @return array{counts:array,fields:array}
     */
    public function conflicts(int $samplesPerField = 3): array
    {
        $fields   = [];
        $diffable = $this->diffableFields();

        $counts = $this->scanConflicts(function ($line, $existing, $diffs) use (&$fields, $samplesPerField) {
            foreach ($diffs as $field => $d) {
                if (! isset($fields[$field])) {
                    $fields[$field] = ['count' => 0, 'samples' => []];
                }
                $fields[$field]['count']++;

                if (count($fields[$field]['samples']) < $samplesPerField) {
                    $fields[$field]['samples'][] = [
                        'line'     => $line,
                        'record'   => $this->recordLabel($existing),
                        'current'  => $d['current'],
                        'incoming' => $d['incoming'],
                        'blank'    => $d['blank'],
                    ];
                }
            }

            return true;
        });

        // Registry order, not order-of-appearance — the same file twice
        // should never produce two different screens.
        $ordered = [];
        foreach ($diffable as $key => $def) {
            if (! isset($fields[$key])) { continue; }
            $ordered[$key] = $fields[$key] + ['label' => $def['label']];
        }

        return ['counts' => $counts, 'fields' => $ordered];
    }

    /**
     * Every differing row for ONE field, paged. The escape hatch for the
     * handful of rows the field rule gets wrong.
     *
     * @return array{rows:array,total:int}
     */
    public function conflictRows(string $field, int $offset = 0, int $limit = 50, string $filter = ''): array
    {
        $rows   = [];
        $total  = 0;
        $filter = trim(mb_strtolower($filter));
        $end    = $offset + $limit;

        $this->scanConflicts(function ($line, $existing, $diffs) use (
            $field, $offset, $end, $filter, &$rows, &$total
        ) {
            if (! isset($diffs[$field])) { return true; }

            $label = $this->recordLabel($existing);
            if ($filter !== '' && ! str_contains(mb_strtolower($label), $filter)) { return true; }

            $total++;
            $idx = $total - 1;
            if ($idx >= $offset && $idx < $end) {
                $rows[] = [
                    'line'     => $line,
                    'record'   => $label,
                    'current'  => $diffs[$field]['current'],
                    'incoming' => $diffs[$field]['incoming'],
                ];
            }

            return true;   // keep counting so the pager knows the total
        });

        return ['rows' => $rows, 'total' => $total];
    }

    /**
     * MARKER-IMPORT-MERGE — a decision made on one row in the merge review
     * beats the rule set for the whole field. Called from buildRow(), which
     * is why it lives here rather than in the screen.
     */
    private function rowDirection(string $field, string $dir, ?int $line): string
    {
        if ($line === null) { return $dir; }

        $ov = ($this->import->row_overrides ?? [])[$field][(string) $line] ?? null;

        return in_array($ov, ['csv', 'keep', 'blank'], true) ? $ov : $dir;
    }

    /** Per-row overrides for one field: line => csv|keep. */
    public function overridesFor(string $field): array
    {
        return (array) (($this->import->row_overrides ?? [])[$field] ?? []);
    }
}
