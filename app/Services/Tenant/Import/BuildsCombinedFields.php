<?php

namespace App\Services\Tenant\Import;

/**
 * MARKER-IMPORT-COMBINE — assemble a field from several columns and text.
 *
 * A combined field is stored in options.combined as:
 *
 *   ['target' => 'name', 'sep' => ' ', 'parts' => [
 *       ['type' => 'col',  'idx' => 3],
 *       ['type' => 'text', 'value' => '—'],
 *       ['type' => 'col',  'idx' => 5],
 *   ]]
 *
 * Empty pieces are dropped along with their separator: a row with no colour
 * yields "Nano Puff", never "Nano Puff / ". Literal text is kept even when
 * its neighbours are empty only if at least one column piece produced a
 * value — text with nothing around it would be a constant, not a combination.
 *
 * The importer casts the result exactly as it would a mapped column, so the
 * same validation (length, money, int) applies.
 */
trait BuildsCombinedFields
{
    /** @return array<int, array{target:string, sep:string, parts:array}> */
    protected function combinedFields(): array
    {
        $fields = $this->fields();
        $out    = [];

        foreach ((array) $this->option('combined', []) as $def) {
            $target = $def['target'] ?? null;
            if (! $target || ! isset($fields[$target])) {
                continue;
            }

            $parts = [];
            foreach ((array) ($def['parts'] ?? []) as $p) {
                $type = $p['type'] ?? null;
                if ($type === 'col' && isset($p['idx']) && $p['idx'] !== '') {
                    $parts[] = ['type' => 'col', 'idx' => (int) $p['idx']];
                } elseif ($type === 'text' && trim((string) ($p['value'] ?? '')) !== '') {
                    $parts[] = ['type' => 'text', 'value' => (string) $p['value']];
                }
            }

            if (! array_filter($parts, fn ($p) => $p['type'] === 'col')) {
                continue; // no column piece = a constant, not a combination
            }

            $out[] = [
                'target' => $target,
                'sep'    => (string) ($def['sep'] ?? ' '),
                'parts'  => $parts,
            ];
        }

        return $out;
    }

    /** Targets combined fields will write — used to satisfy the match-key rule. */
    protected function combinedTargets(): array
    {
        return array_column($this->combinedFields(), 'target');
    }

    /**
     * Assemble one combined value from a row's cells. Null when every column
     * piece was blank, so it behaves like an unmapped cell.
     */
    protected function combineValue(array $def, array $cells): ?string
    {
        $pieces = [];
        $anyCol = false;

        foreach ($def['parts'] as $p) {
            if ($p['type'] === 'col') {
                $v = trim((string) ($cells[$p['idx']] ?? ''));
                if ($v !== '') {
                    $pieces[] = $v;
                    $anyCol   = true;
                }
            } else {
                $pieces[] = $p['value'];
            }
        }

        if (! $anyCol) {
            return null;
        }

        // Collapse runs of literal text that ended up adjacent because the
        // columns between them were blank — "A / / B" becomes "A / B".
        $joined = implode($def['sep'], $pieces);
        $joined = preg_replace('/(\s*' . preg_quote($def['sep'], '/') . '\s*){2,}/', $def['sep'], $joined);

        return trim($joined);
    }

    /**
     * Apply every combined field to $values / $dirs (and $extra for the
     * pseudo-fields an importer routes elsewhere). Called after the direct
     * mapping loop so a combined field wins over a direct one to the same
     * target — that is the rule the screen states.
     */
    protected function applyCombined(array $cells, array &$values, array &$dirs, array &$errors, ?int $line, array &$extra = []): void
    {
        foreach ($this->combinedFields() as $def) {
            $raw = $this->combineValue($def, $cells);
            if ($raw === null) {
                continue;
            }

            $f = $def['target'];

            // Pseudo-fields the inventory importer keeps out of $values.
            if (in_array($f, ['category', 'vendor'], true)) {
                $extra[$f] = $raw;
                continue;
            }

            [$val, $err] = $this->cast($f, $raw);
            if ($err) {
                $errors[] = $err . ' (combined field)';
                continue;
            }
            if ($val === null) {
                continue;
            }

            if (in_array($f, ['stock', 'upc'], true)) {
                $extra[$f] = $val;
                continue;
            }

            $values[$f] = $val;
            $dirs[$f]   = $this->option('direction', 'csv');
            if (method_exists($this, 'rowDirection')) {
                $dirs[$f] = $this->rowDirection($f, $dirs[$f], $line);
            }
        }
    }
}
