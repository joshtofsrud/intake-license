<?php

namespace App\Models\Tenant;

// MARKER-IMPORT2
use Illuminate\Database\Eloquent\Model;

class TenantImportRow extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = [
        'import_id', 'tenant_id', 'action', 'record_type', 'record_id',
        'before', 'stock_delta', 'location_id', 'created_at', 'reversed_at', 'kept_reason',
    ];

    protected $casts = [
        'before'      => 'array',
        'created_at'  => 'datetime',
        'reversed_at' => 'datetime',
    ];
}
