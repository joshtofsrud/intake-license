<?php
namespace App\Models\Tenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class TenantCampaignSend extends Model
{
    use HasUuids;
    public    $timestamps = false;
    protected $table      = 'tenant_campaign_sends';
    protected $fillable   = [
        'campaign_id','customer_id','email','status','tracking_token',
        'open_count','click_count','sent_at','opened_at','clicked_at','error_message','created_at',
    ];
    protected $casts = ['sent_at' => 'datetime', 'opened_at' => 'datetime', 'clicked_at' => 'datetime', 'created_at' => 'datetime'];
}
