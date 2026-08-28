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
        'encoding', 'has_header', 'columns', 'mapping', 'row_overrides', 'options', 'totals',
        'status', 'failure_reason', 'error_path', 'created_by_user_id',
        'started_at', 'finished_at',
    ];

    protected $casts = [
        'columns'     => 'array',
        'mapping'     => 'array',
        'row_overrides' => 'array',   // MARKER-IMPORT-MERGE
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
