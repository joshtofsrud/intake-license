<?php
// MARKER-PATCH-HLC3A

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * One mapping row: how a distributor's feed fills one canonical field.
 * Edited by master admin; read by DistributorMapResolver. Not tenant-scoped.
 */
class DistributorFieldMap extends Model
{
    use HasUuids;

    protected $table = 'distributor_field_maps';

    protected $fillable = [
        'distributor_code',
        'canonical_field',
        'source_path',
        'transform',
        'transform_args',
        'lookup_table',
        'sort_order',
        'is_active',
        'notes',
    ];

    protected $casts = [
        'transform_args' => 'array',
        'lookup_table'   => 'array',
        'sort_order'     => 'integer',
        'is_active'      => 'boolean',
    ];
}
