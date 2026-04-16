<?php
namespace App\Models\Tenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class TenantPageSection extends Model
{
    use HasUuids;
    protected $table    = 'tenant_page_sections';
    protected $fillable = ['page_id','tenant_id','section_type','content','bg_color','padding','is_visible','sort_order'];
    protected $casts    = ['content' => 'array', 'is_visible' => 'boolean'];
}
