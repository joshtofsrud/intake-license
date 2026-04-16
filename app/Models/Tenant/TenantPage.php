<?php
namespace App\Models\Tenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class TenantPage extends Model
{
    use HasUuids;
    protected $table    = 'tenant_pages';
    protected $fillable = ['tenant_id','title','slug','meta_title','meta_description','is_home','is_published','is_in_nav','nav_order'];
    protected $casts    = ['is_home' => 'boolean', 'is_published' => 'boolean', 'is_in_nav' => 'boolean'];

    public function sections(): HasMany
    {
        return $this->hasMany(TenantPageSection::class, 'page_id')->orderBy('sort_order');
    }
}
