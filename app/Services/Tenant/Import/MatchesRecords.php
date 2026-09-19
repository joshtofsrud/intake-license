<?php

namespace App\Services\Tenant\Import;

use App\Models\Tenant\TenantCustomer;
use App\Models\Tenant\TenantImportLedgerRow;
use App\Models\Tenant\TenantInventoryItem;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * MARKER-IMPORT-MATCH — multi-key matching, the possible-duplicate outcome,
 * and the ledger. Shared by both importers.
 *
 * Match keys, strongest first. Which one hit is recorded on every row.
 *   inventory   sku → upc → mpn
 *   customers   email → phone → name+postcode
 *
 * A match on anything but the first key, or on the first key with a strong
 * field disagreeing, is a POSSIBLE DUPLICATE and never merges on its own.
 */
trait MatchesRecords
{
    // ------------------------------------------------------------- identity

    /** Identity values for one row, through mapping + combined fields, cast. */
    protected function identityFor(array $cells): array
    {
        $want = $this->identityFields();
        $out  = [];

        foreach ($this->mapping() as $idx => $m) {
            if (! in_array($m['field'], $want, true)) { continue; }
            [$val, $err] = $this->cast($m['field'], (string) ($cells[$idx] ?? ''));
            if (! $err && $val !== null && $val !== '') { $out[$m['field']] = $val; }
        }

        foreach ($this->combinedFields() as $def) {
            if (! in_array($def['target'], $want, true)) { continue; }
            $raw = $this->combineValue($def, $cells);
            if ($raw === null) { continue; }
            [$val, $err] = $this->cast($def['target'], $raw);
            if (! $err && $val !== null && $val !== '') { $out[$def['target']] = $val; }
        }

        return $out;
    }

    protected function identityFields(): array
    {
        return $this->importType() === 'inventory'
            ? ['sku', 'upc', 'catalog_mpn', 'name']
            : ['email', 'phone', 'first_name', 'last_name', 'postcode', 'business_name'];
    }

    protected function importType(): string
    {
        return (string) $this->import->type;
    }

    // -------------------------------------------------------------- matching

    /**
     * Resolve a batch of rows to existing records. Returns one entry per
     * batch index: ['record', 'key', 'strong', 'identity'].
     */
    protected function matchBatch(array $batch): array
    {
        $ids = [];
        foreach ($batch as $i => $b) { $ids[$i] = $this->identityFor($b['cells']); }

        return $this->importType() === 'inventory'
            ? $this->matchInventory($ids)
            : $this->matchCustomers($ids);
    }

    private function matchInventory(array $ids): array
    {
        $by = fn ($f) => array_values(array_unique(array_filter(array_map(
            fn ($r) => isset($r[$f]) ? strtolower(trim((string) $r[$f])) : null, $ids))));

        $q = fn () => TenantInventoryItem::where('tenant_id', $this->tenant->id);

        $bySku = $by('sku') ? $q()->whereIn(DB::raw('LOWER(sku)'), $by('sku'))->get()->keyBy(fn ($r) => strtolower((string) $r->sku)) : collect();
        $byUpc = $by('upc') ? $q()->whereIn(DB::raw('LOWER(catalog_ean)'), $by('upc'))->get()->keyBy(fn ($r) => strtolower((string) $r->catalog_ean)) : collect();
        $byMpn = $by('catalog_mpn') ? $q()->whereIn(DB::raw('LOWER(catalog_mpn)'), $by('catalog_mpn'))->get()->keyBy(fn ($r) => strtolower((string) $r->catalog_mpn)) : collect();

        $out = [];
        foreach ($ids as $i => $r) {
            $sku = isset($r['sku']) ? strtolower(trim((string) $r['sku'])) : '';
            $upc = isset($r['upc']) ? strtolower(trim((string) $r['upc'])) : '';
            $mpn = isset($r['catalog_mpn']) ? strtolower(trim((string) $r['catalog_mpn'])) : '';

            if ($sku !== '' && $bySku->has($sku)) {
                $out[$i] = ['record' => $bySku[$sku], 'key' => 'sku', 'strong' => true, 'identity' => $r];
            } elseif ($upc !== '' && $byUpc->has($upc)) {
                $out[$i] = ['record' => $byUpc[$upc], 'key' => 'upc', 'strong' => false, 'identity' => $r];
            } elseif ($mpn !== '' && $byMpn->has($mpn)) {
                $out[$i] = ['record' => $byMpn[$mpn], 'key' => 'mpn', 'strong' => false, 'identity' => $r];
            } else {
                $out[$i] = ['record' => null, 'key' => null, 'strong' => false, 'identity' => $r];
            }
        }
        return $out;
    }

    private function matchCustomers(array $ids): array
    {
        $q = fn () => TenantCustomer::where('tenant_id', $this->tenant->id);

        $emails = array_values(array_unique(array_filter(array_map(fn ($r) => isset($r['email']) ? strtolower(trim($r['email'])) : null, $ids))));
        $phones = array_values(array_unique(array_filter(array_map(fn ($r) => $r['phone'] ?? null, $ids))));

        $byEmail = $emails ? $q()->whereIn(DB::raw('LOWER(email)'), $emails)->get()->keyBy(fn ($c) => strtolower((string) $c->email)) : collect();
        $byPhone = $phones ? $q()->whereIn('phone', $phones)->get()->keyBy(fn ($c) => (string) $c->phone) : collect();

        // Name + postcode: only for rows that have all three, looked up as a set.
        $nameKeys = [];
        foreach ($ids as $i => $r) {
            if (! empty($r['first_name']) && ! empty($r['last_name']) && ! empty($r['postcode'])) {
                $nameKeys[$i] = strtolower(trim($r['last_name'])) . '|' . strtolower(trim($r['first_name'])) . '|' . strtolower(preg_replace('/\s+/', '', $r['postcode']));
            }
        }
        $byName = collect();
        if ($nameKeys) {
            $lasts = array_values(array_unique(array_map(fn ($k) => explode('|', $k)[0], $nameKeys)));
            $byName = $q()->whereIn(DB::raw('LOWER(last_name)'), $lasts)->get()
                ->keyBy(fn ($c) => strtolower((string) $c->last_name) . '|' . strtolower((string) $c->first_name) . '|' . strtolower(preg_replace('/\s+/', '', (string) $c->postcode)));
        }

        $out = [];
        foreach ($ids as $i => $r) {
            $email = isset($r['email']) ? strtolower(trim($r['email'])) : '';
            $phone = (string) ($r['phone'] ?? '');
            $nk    = $nameKeys[$i] ?? '';

            if ($email !== '' && $byEmail->has($email)) {
                $out[$i] = ['record' => $byEmail[$email], 'key' => 'email', 'strong' => true, 'identity' => $r];
            } elseif ($phone !== '' && $byPhone->has($phone)) {
                $out[$i] = ['record' => $byPhone[$phone], 'key' => 'phone', 'strong' => false, 'identity' => $r];
            } elseif ($nk !== '' && $byName->has($nk)) {
                $out[$i] = ['record' => $byName[$nk], 'key' => 'name', 'strong' => false, 'identity' => $r];
            } else {
                $out[$i] = ['record' => null, 'key' => null, 'strong' => false, 'identity' => $r];
            }
        }
        return $out;
    }

    // --------------------------------------------------------------- judging

    /** The stored decision for a possible duplicate on this line, if any. */
    protected function matchDecision(int $line): ?string
    {
        $d = (($this->import->row_overrides ?? [])['__match'] ?? [])[(string) $line] ?? null;
        return in_array($d, ['merge', 'create', 'skip'], true) ? $d : null;
    }

    /**
     * Post-process a built row against how it was matched. May turn it into a
     * possible_duplicate, or honour a stored decision. Returns the row with
     * 'match_key' and 'reason' added.
     */
    protected function judge(array $row, array $m, int $line, array $cells): array
    {
        $row['match_key'] = $m['key'];
        $row['reason']    = $row['reason'] ?? null;

        if (! $m['record']) {
            return $row; // nothing matched; create/unmatched/error stand
        }

        $why = null;
        if (! $m['strong']) {
            $why = 'Matched on ' . strtoupper($m['key']) . ', not ' . $this->strongKeyLabel();
        } elseif (in_array($row['outcome'], ['update', 'unchanged'], true)) {
            $why = $this->strongDisagreement($m['record'], $m['identity']);
        }

        if ($why === null) {
            return $row;
        }

        $decision = $this->matchDecision($line);

        if ($decision === null) {
            $row['outcome'] = 'possible_duplicate';
            $row['reason']  = $why;
            return $row;
        }

        if ($decision === 'skip') {
            $row['outcome'] = 'skipped';
            $row['reason']  = 'You chose to skip this possible duplicate';
            $row['changes'] = [];
            return $row;
        }

        if ($decision === 'create') {
            $fresh = $this->buildRow($cells, null, $line);
            $fresh['match_key'] = null;
            $fresh['reason']    = 'You chose to create a new ' . $this->nounSingular() . ' (was a possible duplicate)';
            return $fresh;
        }

        // merge: the built row stands
        $row['reason'] = 'You chose to merge into the existing ' . $this->nounSingular() . ' (' . $why . ')';
        return $row;
    }

    private function strongKeyLabel(): string
    {
        return $this->importType() === 'inventory' ? 'SKU' : 'email';
    }

    private function nounSingular(): string
    {
        return $this->importType() === 'inventory' ? 'item' : 'customer';
    }

    /** Strong key matched, but does a strong field disagree? */
    private function strongDisagreement($record, array $identity): ?string
    {
        $norm = fn ($v) => strtolower(trim(preg_replace('/\s+/', ' ', (string) $v)));

        if ($this->importType() === 'inventory') {
            if (! empty($identity['name']) && ! empty($record->name) && $norm($identity['name']) !== $norm($record->name)) {
                return 'Same SKU, different name: file says "' . Str::limit($identity['name'], 40) . '", you have "' . Str::limit($record->name, 40) . '"';
            }
            return null;
        }

        if (! empty($identity['last_name']) && ! empty($record->last_name) && $norm($identity['last_name']) !== $norm($record->last_name)) {
            return 'Same email, different surname: file says "' . $identity['last_name'] . '", you have "' . $record->last_name . '"';
        }
        return null;
    }

    // ---------------------------------------------------------------- ledger

    protected function ledgerStart(string $phase): void
    {
        TenantImportLedgerRow::where('import_id', $this->import->id)->where('phase', $phase)->delete();
        $this->ledgerBuffer = [];
    }

    protected array $ledgerBuffer = [];

    protected function ledgerRow(string $phase, int $line, array $cells, array $row, ?array $m = null): void
    {
        $record = $row['match'] ?? ($m['record'] ?? null);

        $this->ledgerBuffer[] = [
            'id'            => (string) Str::uuid(),
            'import_id'     => $this->import->id,
            'phase'         => $phase,
            'line'          => $line,
            'outcome'       => $row['outcome'],
            'reason'        => Str::limit((string) ($row['reason'] ?? ($row['errors'] ? implode('; ', $row['errors']) : '')), 250),
            'match_key'     => $row['match_key'] ?? ($m['key'] ?? null),
            'matched_id'    => $record->id ?? null,
            'matched_label' => $record ? Str::limit((string) ($record->name ?? trim(($record->first_name ?? '') . ' ' . ($record->last_name ?? ''))), 180) : null,
            'changes'       => json_encode(array_keys($row['changes'] ?? [])),
            'cells'         => json_encode(array_values($cells)),
            'created_at'    => now(), 'updated_at' => now(),
        ];

        if (count($this->ledgerBuffer) >= 250) { $this->ledgerFlush(); }
    }

    protected function ledgerFlush(): void
    {
        if ($this->ledgerBuffer) {
            DB::table('tenant_import_ledger')->insert($this->ledgerBuffer);
            $this->ledgerBuffer = [];
        }
    }
}
