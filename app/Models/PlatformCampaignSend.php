<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class PlatformCampaignSend extends Model
{
    use HasUuids;

    protected $table = 'platform_campaign_sends';

    protected $fillable = [
        'campaign_id', 'email', 'name', 'source_type', 'source_id',
        'status', 'paced', 'skip_reason', 'error', 'tracking_token',
        'sent_at', 'opened_at', 'clicked_at',
        'bounced_at', 'bounce_type', 'complained_at', 'unsubscribed_at', // MARKER-CAMPAIGN-REPORT
        'channel', 'send_count', // MARKER-PERSONAL-DELIVERY
    ];

    protected $casts = [
        'sent_at'    => 'datetime',
        'opened_at'  => 'datetime',
        'clicked_at' => 'datetime',
        'bounced_at'      => 'datetime',
        'complained_at'   => 'datetime',
        'unsubscribed_at' => 'datetime',
    ];

    /**
     * MARKER-CAMPAIGN-REPORT: stamp a bounce, complaint or unsubscribe on the
     * send that caused it. Postmark tells us which send when it can; when it
     * can't (an unsubscribe link carries only the address), it goes on the
     * newest campaign email that address was sent in the last 60 days. First
     * stamp wins, so a replayed webhook changes nothing.
     */
    public static function stampOutcome(string $email, string $field, ?string $sendId = null, array $extra = []): void
    {
        if (! in_array($field, ['bounced_at', 'complained_at', 'unsubscribed_at'], true)) return;
        try {
            $row = $sendId ? static::find($sendId) : null;
            if (! $row) {
                $email = mb_strtolower(trim($email));
                if ($email === '') return;
                $row = static::where('email', $email)->where('status', 'sent')
                    ->where('sent_at', '>=', now()->subDays(60))
                    ->orderByDesc('sent_at')->first();
            }
            if (! $row || $row->{$field}) return;
            $row->forceFill([$field => now()] + $extra)->save();
        } catch (\Throwable $e) {
            logger()->warning('Campaign outcome not recorded', ['field' => $field, 'error' => $e->getMessage()]);
        }
    }
}
