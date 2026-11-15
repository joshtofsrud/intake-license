<?php

namespace App\Models\Tenant;

use Illuminate\Database\Eloquent\Model;

/** MARKER-SHOP-SEARCH-V2: a no-results search the shop has looked at. It stays counted. */
class TenantSearchDismissal extends Model
{
    protected $table = 'tenant_search_dismissals';
    protected $fillable = ['tenant_id', 'query', 'dismissed_by'];
}
