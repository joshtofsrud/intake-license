<?php

namespace App\Models\Tenant;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * MARKER-SHOP-SEARCH-V2: what a shopper did after a search.
 *   click        opened an item from the dropdown or results
 *   add          put that item in the cart within 30 minutes (order_id = the cart, which becomes the order)
 *   promo_click  opened a promotion
 * "Bought" is not stored: it is an add whose cart was paid for.
 */
class TenantSearchEvent extends Model
{
    use HasUuids;

    public $timestamps = false;
    protected $table = 'tenant_search_events';
    protected $fillable = ['tenant_id', 'query_id', 'query', 'kind', 'item_id', 'promotion_id', 'order_id', 'session_id', 'created_at'];
    protected $casts = ['created_at' => 'datetime'];
}
