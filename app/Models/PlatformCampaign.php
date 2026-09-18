<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** MARKER-PLATFORM-EMAIL */
class PlatformCampaign extends Model
{
    use HasUuids;

    protected $table = 'platform_campaigns';

    // Every marker comment sits at the END of its own line: an inline // inside
    // a fillable array comments out the rest of it, which has bitten campaigns
    // once already (scheduled_at and sent_at silently un-fillable).
    protected $fillable = [
        'name', 'subject', 'preheader', 'from_name', 'from_email',
        'audience_id', 'blocks', 'status', 'scheduled_at', 'sent_at',
        'total_recipients', 'total_sent', 'total_opened', 'total_clicked',
    ];

    protected $casts = [
        'blocks'       => 'array',
        'scheduled_at' => 'datetime',
        'sent_at'      => 'datetime',
    ];

    public function audience(): BelongsTo
    {
        return $this->belongsTo(PlatformAudience::class, 'audience_id');
    }

    public function sends(): HasMany
    {
        return $this->hasMany(PlatformCampaignSend::class, 'campaign_id');
    }

    public function isEditable(): bool
    {
        return in_array($this->status, ['draft', 'scheduled'], true);
    }
}
