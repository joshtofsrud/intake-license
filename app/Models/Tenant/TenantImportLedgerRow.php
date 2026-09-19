<?php

namespace App\Models\Tenant;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/** MARKER-IMPORT-MATCH — one input row's fate, per phase. */
class TenantImportLedgerRow extends Model
{
    use HasUuids;

    protected $table    = 'tenant_import_ledger';
    protected $fillable = [
        'import_id', 'phase', 'line', 'outcome', 'reason',
        'match_key', 'matched_id', 'matched_label', 'changes', 'cells',
    ];
    protected $casts = ['changes' => 'array', 'cells' => 'array', 'line' => 'integer'];

    public const OUTCOMES = [
        'create'             => 'Will be created',
        'update'             => 'Will be updated',
        'unchanged'          => 'Already match',
        'possible_duplicate' => 'Possible duplicate',
        'skipped'            => 'Skipped',
        'unmatched'          => 'No match',
        'error'              => 'Error',
    ];
}
