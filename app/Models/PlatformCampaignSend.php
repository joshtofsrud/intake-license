<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/** MARKER-PLATFORM-EMAIL */
class PlatformCampaignSend extends Model
{
    use HasUuids;

    protected $table = 'platform_campaign_sends';

    protected $fillable = [
        'campaign_id', 'email', 'name', 'source_type', 'source_id',
        'status', 'skip_reason', 'error', 'tracking_token',
        'sent_at', 'opened_at', 'clicked_at',
    ];

    protected $casts = [
        'sent_at'    => 'datetime',
        'opened_at'  => 'datetime',
        'clicked_at' => 'datetime',
    ];
}
