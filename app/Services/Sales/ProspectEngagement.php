<?php
// Lead score that moves on its own when a shop engages with Platform email.
// +5 the first time an email is opened, +10 the first time a link in it is
// clicked, +25 the first time they reply to it. Never above 110, and always on
// top of whatever score was set by hand. Each step lands on the timeline.

namespace App\Services\Sales;

use App\Models\PlatformCampaignSend;
use App\Models\PlatformInboxMessage;
use App\Models\SalesProspect;

class ProspectEngagement
{
    public const POINTS = ['open' => 5, 'click' => 10, 'reply' => 25];
    public const MAX = 110;

    public static function add(SalesProspect $p, string $kind, string $what): void
    {
        $was  = (int) $p->lead_score;
        $now  = min(self::MAX, max($was, $was + (self::POINTS[$kind] ?? 0)));
        if ($now !== $was) $p->forceFill(['lead_score' => $now])->save();
        $verb = ['open' => 'Opened', 'click' => 'Clicked a link in', 'reply' => 'Replied to'][$kind] ?? 'Engaged with';
        $p->activities()->create(['type' => 'email', 'body' => trim("$verb: $what") . ($now > $was ? ' (score +' . ($now - $was) . ')' : '')]);
    }

    /** The prospect an email thread belongs to: the shop it was sent from, the campaign row, then a unique email match. */
    public static function forThread(PlatformInboxMessage $m, string $from = ''): ?SalesProspect
    {
        $meta = (array) ($m->meta ?? []);
        if (! empty($meta['prospect'])) {
            if ($p = SalesProspect::find($meta['prospect'])) return $p;
        }
        if ($m->campaign_id && $m->email) {
            $id = PlatformCampaignSend::where('campaign_id', $m->campaign_id)->where('email', $m->email)
                ->where('source_type', 'prospects')->value('source_id');
            if ($id && ($p = SalesProspect::find($id))) return $p;
        }
        foreach (array_unique(array_filter([mb_strtolower(trim($from)), mb_strtolower(trim((string) $m->email))])) as $email) {
            $hits = SalesProspect::whereRaw('LOWER(email) = ?', [$email])->limit(2)->get();
            if ($hits->count() === 1) return $hits->first();
        }
        return null;
    }
}
