<?php

namespace App\Models\Tenant;

// MARKER-IMPORT-PRESETS
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class TenantImportMapping extends Model
{
    use HasUuids;

    protected $fillable = [
        'tenant_id', 'type', 'name', 'mapping', 'options',
        'header_hash', 'header', 'use_count', 'last_used_at', 'created_by_user_id',
    ];

    protected $casts = [
        'mapping'      => 'array',
        'options'      => 'array',
        'header'       => 'array',
        'last_used_at' => 'datetime',
    ];

    /**
     * A header row's fingerprint. Case and surrounding whitespace are noise —
     * the same export from the same system twice must hash the same. The unit
     * separator can't appear in a CSV cell, so joining on it can't collide two
     * different headers into one hash.
     */
    public static function hashHeader(array $header): string
    {
        $norm = array_map(fn ($h) => mb_strtolower(trim((string) $h)), $header);

        return sha1(implode("\x1f", $norm));
    }
}
