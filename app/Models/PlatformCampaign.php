<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PlatformCampaign extends Model
{
    use HasUuids;

    protected $table = 'platform_campaigns';

    // Every marker comment sits at the END of its own line: an inline // inside
    // a fillable array comments out the rest of it, which has bitten campaigns
    // once already (scheduled_at and sent_at silently un-fillable).
    protected $fillable = [
        'name', 'subject', 'preheader', 'body',
        'sender',
        'delivery', // MARKER-PERSONAL-DELIVERY
        'audience_id', 'blocks', 'theme', 'status', 'scheduled_at', 'sent_at',
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

    /**
     * MARKER-CAMPAIGN-SENDER: 'platform' (Intake) or 'outreach' (your own
     * name). Left blank, prospects hear from the outreach sender, the same
     * person who emailed them before; everyone else from Intake.
     */
    public function senderKey(): string
    {
        if (in_array($this->sender, ['platform', 'outreach'], true)) {
            return $this->sender;
        }
        return $this->audience?->source === 'prospects' ? 'outreach' : 'platform';
    }

    /** [address, name] for PlatformMailer::send, or null for the platform sender. */
    public function senderPair(): ?array
    {
        return $this->senderKey() === 'outreach' ? PlatformSettings::outreachFrom() : null;
    }

    /**
     * MARKER-PERSONAL-DELIVERY: 'personal' = one email at a time, each sent by
     * a click, on the transactional stream; 'bulk' = the campaign worker.
     * Automatic: investors get personal mail, everyone else bulk. Prospects
     * can never go personal: cold mail stays off the transactional stream.
     */
    public function deliveryKey(): string
    {
        $source = $this->audience?->source;
        if ($source === 'prospects') return 'bulk';
        if (in_array($this->delivery, ['bulk', 'personal'], true)) return $this->delivery;
        return in_array($source, ['investors', 'inv_leads'], true) ? 'personal' : 'bulk';
    }

    /** The PlatformMailer mode for this campaign's bulk sends. */
    public function bulkMode(): ?string
    {
        return $this->audience?->source === 'prospects' ? 'outreach' : null;
    }

    public function isEditable(): bool
    {
        return in_array($this->status, ['draft', 'scheduled'], true);
    }
}
