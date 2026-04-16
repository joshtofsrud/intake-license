<?php
namespace App\Models\Tenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class TenantCampaign extends Model
{
    use HasUuids;
    protected $table    = 'tenant_campaigns';
    protected $fillable = [
        'tenant_id','name','type','status','subject','body_html','body_text',
        'targeting','scheduled_at','sent_at',
        'total_recipients','total_sent','total_opened','total_clicked','created_by',
    ];
    protected $casts = [
        'targeting'    => 'array',
        'scheduled_at' => 'datetime',
        'sent_at'      => 'datetime',
    ];

    public function sends(): HasMany { return $this->hasMany(TenantCampaignSend::class, 'campaign_id'); }
}
