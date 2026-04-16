<?php
namespace App\Models\Tenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class TenantFormSection extends Model
{
    use HasUuids;
    protected $table    = 'tenant_form_sections';
    protected $fillable = ['tenant_id','title','description','is_core','sort_order'];
    protected $casts    = ['is_core' => 'boolean'];

    public function fields(): HasMany { return $this->hasMany(TenantFormField::class, 'section_id')->orderBy('sort_order'); }
}
