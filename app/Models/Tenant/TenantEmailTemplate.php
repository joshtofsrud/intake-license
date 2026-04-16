<?php
namespace App\Models\Tenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class TenantEmailTemplate extends Model
{
    use HasUuids;
    protected $table    = 'tenant_email_templates';
    protected $fillable = ['tenant_id','template_type','subject','body_html','body_text','is_enabled','send_delay_minutes'];
    protected $casts    = ['is_enabled' => 'boolean', 'send_delay_minutes' => 'integer'];
}
