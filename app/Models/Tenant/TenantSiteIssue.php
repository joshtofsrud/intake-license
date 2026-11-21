<?php

namespace App\Models\Tenant;

use Illuminate\Database\Eloquent\Model;

/** MARKER-SITE-HEALTH: one thing to fix on a shop's site. */
class TenantSiteIssue extends Model
{
    protected $table = 'tenant_site_issues';

    protected $guarded = ['id'];

    protected $casts = ['detail' => 'array', 'undo' => 'array'];
}
