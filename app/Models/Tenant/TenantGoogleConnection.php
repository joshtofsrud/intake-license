<?php

namespace App\Models\Tenant;

use Illuminate\Database\Eloquent\Model;

/** MARKER-SITE-HEALTH: one shop's Google sign-in for Site health. Tokens are encrypted at rest. */
class TenantGoogleConnection extends Model
{
    protected $table = 'tenant_google_connections';

    protected $guarded = ['id'];

    protected $hidden = ['access_token', 'refresh_token'];

    protected $casts = [
        'access_token' => 'encrypted', 'refresh_token' => 'encrypted', 'token_expires_at' => 'datetime',
        'picks' => 'array', 'options' => 'array', 'data' => 'array', 'state' => 'array',
        'connected_at' => 'datetime', 'synced_at' => 'datetime',
    ];
}
