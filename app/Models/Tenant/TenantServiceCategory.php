<?php
namespace App\Models\Tenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class TenantServiceCategory extends Model
{
    use HasUuids;
    protected $table    = 'tenant_service_categories';
    protected $fillable = ['tenant_id','name','slug','description','image_url','is_active','sort_order'];
    protected $casts    = ['is_active' => 'boolean'];

    public function items(): HasMany { return $this->hasMany(TenantServiceItem::class, 'category_id')->orderBy('sort_order'); }
}
