<?php
namespace App\Models\Tenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * A row of a site's menu. a row either points at a page
 * (page_id: follows the page's address and title, hidden while the page is
 * unpublished) or is a custom link (label + url). style: link | button |
 * outline. side: left | right.
 */
class TenantNavItem extends Model
{
    use HasUuids;
    protected $table    = 'tenant_nav_items';
    protected $fillable = ['tenant_id','page_id','label','url','style','side','is_external','open_in_new_tab','sort_order'];
    protected $casts    = ['is_external' => 'boolean', 'open_in_new_tab' => 'boolean'];

    public function page()
    {
        return $this->belongsTo(TenantPage::class, 'page_id');
    }

    /** A page row shows the page title unless a label was typed. */
    public function getLabelAttribute($value)
    {
        if (($value === null || $value === '') && $this->page_id && $this->page) {
            return $this->page->title;
        }
        return $value;
    }

    /** A page row always goes to the page's current address. */
    public function getUrlAttribute($value)
    {
        if ($this->page_id && $this->page) {
            return $this->page->is_home ? '/' : '/' . $this->page->slug;
        }
        return $value;
    }

    /** What a site's header draws: in order, without rows whose page is missing or unpublished. */
    public static function forSite(string $tenantId)
    {
        return static::with('page')->where('tenant_id', $tenantId)->orderBy('sort_order')->get()
            ->filter(fn ($i) => ! $i->page_id || ($i->page && $i->page->is_published))
            ->values();
    }
}
