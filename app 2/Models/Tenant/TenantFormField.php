<?php
namespace App\Models\Tenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class TenantFormField extends Model
{
    use HasUuids;
    protected $table    = 'tenant_form_fields';
    protected $fillable = [
        'tenant_id','section_id','field_key','field_type','label',
        'placeholder','help_text','is_required','is_core','width',
        'options','condition','style_json','sort_order',
    ];
    protected $casts = [
        'is_required' => 'boolean',
        'is_core'     => 'boolean',
        'options'     => 'array',
        'condition'   => 'array',
        'style_json'  => 'array',
    ];
}
