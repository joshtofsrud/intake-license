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
        'tenant_id','name','type','status','subject','preheader','show_header','body_html','body_text','blocks', // MARKER-CAMPAIGN-V2A
        'targeting', 'discount_id', 'scheduled_at', 'sent_at', // MARKER-CAMPAIGN-ATTRIBUTION
        'total_recipients','total_sent','total_opened','total_clicked','created_by',
    ];
    protected $casts = [
        'targeting'    => 'array',
        'blocks'       => 'array',
        'scheduled_at' => 'datetime',
        'sent_at'      => 'datetime',
    ];

    public function sends(): HasMany { return $this->hasMany(TenantCampaignSend::class, 'campaign_id'); }
}
