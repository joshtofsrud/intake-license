<?php

namespace App\Models\Tenant;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * MARKER-SHOP-SEARCH-V2: an image with a headline and a link, shown in search for
 * the words or categories the shop picks: in the dropdown, as a banner above
 * results, or as a card among them.
 */
class TenantSearchPromotion extends Model
{
    use HasUuids;

    protected $table = 'tenant_search_promotions';

    public const PLACES = ['dropdown' => 'Search dropdown', 'banner' => 'Banner above results', 'card' => 'Card among results'];
    public const LINKS  = ['category' => 'A category', 'page' => 'A page', 'brand' => 'A brand', 'item' => 'An item', 'url' => 'A web address'];

    protected $fillable = [
        'tenant_id', 'title', 'subtitle', 'image_url', 'image_media_id', 'link_type', 'link_value', 'button_label',
        'keywords', 'category_ids', 'placements', 'starts_on', 'ends_on', 'enabled', 'seen_count', 'click_count', 'created_by',
    ];

    protected $casts = [
        'keywords'     => 'array',
        'category_ids' => 'array',
        'placements'   => 'array',
        'starts_on'    => 'date',
        'ends_on'      => 'date',
        'enabled'      => 'boolean',
        'seen_count'   => 'integer',
        'click_count'  => 'integer',
    ];

    /** showing | off | scheduled | ended */
    public function status(): string
    {
        if (! $this->enabled) return 'off';
        $today = now()->startOfDay();
        if ($this->starts_on && $this->starts_on->gt($today)) return 'scheduled';
        if ($this->ends_on && $this->ends_on->lt($today)) return 'ended';
        return 'showing';
    }

    public function places(string $where): bool
    {
        return in_array($where, (array) $this->placements, true);
    }

    /** Where a click goes. Counted first by /shop/search/promo/{id}. */
    public function targetUrl(): string
    {
        $v = (string) $this->link_value;
        return match ($this->link_type) {
            'category' => '/shop?category=' . urlencode($v),
            'brand'    => '/shop?brand=' . urlencode($v),
            'item'     => ($it = TenantInventoryItem::where('tenant_id', $this->tenant_id)->find($v)) ? $it->shopPath() : '/shop',
            'page'     => '/' . ltrim($v, '/'),
            default    => $v !== '' ? $v : '/shop',
        };
    }

    public function clickUrl(): string
    {
        return '/shop/search/promo/' . $this->id;
    }
}
