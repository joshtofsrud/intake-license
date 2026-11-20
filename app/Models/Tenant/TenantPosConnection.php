<?php

namespace App\Models\Tenant;

use App\Models\Tenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * MARKER-POS-CONNECT: one shop's authorised link to its own POS.
 * Tokens are encrypted at rest; one connection per shop.
 */
class TenantPosConnection extends Model
{
    use HasUuids;

    protected $table = 'tenant_pos_connections';

    protected $fillable = [
        'tenant_id', 'adapter_code', 'status', 'access_token', 'refresh_token', 'token_expires_at',
        'external_account_id', 'external_account_name', 'context', 'locations', 'payment_types',
        'connected_by', 'connected_at', 'last_checked_at', 'last_error',
    ];

    protected $hidden = ['access_token', 'refresh_token'];

    protected $casts = [
        'access_token'     => 'encrypted',
        'refresh_token'    => 'encrypted',
        'token_expires_at' => 'datetime',
        'connected_at'     => 'datetime',
        'last_checked_at'  => 'datetime',
        'locations'        => 'array',
        'context'          => 'array', // MARKER-POS-CONTEXT
        'payment_types'    => 'array',
    ];

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function connectedByUser(): BelongsTo
    {
        return $this->belongsTo(\App\Models\Tenant\TenantUser::class, 'connected_by');
    }
}
