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
        'progress_done', 'progress_total', 'progress_stage', 'progress_seen_at', // MARKER-IMPORT-PROGRESS
        'cancel_requested_at', // MARKER-IMPORT-QUEUE
    ];

    protected $casts = [
        'columns'     => 'array',
        'mapping'     => 'array',
        'row_overrides' => 'array',   // MARKER-IMPORT-MERGE
        'options'     => 'array',
        'totals'      => 'array',
        'cancel_requested_at' => 'datetime', // MARKER-IMPORT-QUEUE
        'has_header'  => 'boolean',
        // MARKER-IMPORT-PROGRESS-500 — added Sep 5 with the other progress
        // columns and never cast, because nothing read it until the modal.
        'progress_seen_at' => 'datetime',
        'started_at'  => 'datetime',
        'finished_at' => 'datetime',
    ];

    /**
     * MARKER-IMPORT-RESULTS — a run's counts. Queued runs (Sep 19 on) store
     * them under totals['run']; earlier synchronous runs stored them at the
     * top level. Read whichever this import has — never a mix of the two.
     */
    public function total(string $key): int
    {
        $t   = (array) ($this->totals ?? []);
        $src = (isset($t['run']) && is_array($t['run'])) ? $t['run'] : $t;

        return (int) ($src[$key] ?? 0);
    }

    /**
     * MARKER-IMPORT-PROGRESS-FIX — rows in the file.
     *
     * The uploader stores this in OPTIONS (see MARKER-IMPORT3). Three separate
     * readers had gone looking in totals, so every progress bar divided by
     * zero while the import ran perfectly. One accessor, so a fourth reader
     * cannot get it wrong.
     */
    public function rowCount(): int
    {
        return (int) (($this->options ?? [])['row_count']
            ?? ($this->totals ?? [])['row_count']
            ?? 0);
    }
}
