<?php

namespace App\Models\Tenant;

use Illuminate\Database\Eloquent\Model;

/** MARKER-SITE-HEALTH: a shop's last site check and its place in a running one. */
class TenantSiteHealth extends Model
{
    protected $table = 'tenant_site_health';

    protected $fillable = ['tenant_id', 'score', 'checked', 'weight_total', 'state', 'basics', 'history', 'checked_at'];

    protected $casts = ['state' => 'array', 'basics' => 'array', 'history' => 'array', 'checked_at' => 'datetime'];

    public static function for(string $tenantId): self
    {
        return self::firstOrCreate(['tenant_id' => $tenantId]);
    }
}
