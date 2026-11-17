<?php

namespace App\Services\Platform;

use App\Models\PlatformCampaign;
use App\Models\PlatformCampaignSend;
use Illuminate\Support\Facades\DB;

/**
 * MARKER-CAMPAIGN-REPORT: everything the campaign report and the campaign
 * list show, counted from the send rows (never from stored totals, which only
 * know sent / opened / clicked).
 */
class PlatformCampaignReport
{
    public const PER_PAGE = 50;

    public const FILTERS = [
        'all'          => 'Everyone',
        'opened'       => 'Opened',
        'clicked'      => 'Clicked',
        'replied'      => 'Replied',
        'unopened'     => 'Not opened',
        'bounced'      => 'Bounced',
        'complained'   => 'Marked spam',
        'unsubscribed' => 'Unsubscribed',
        'pending'      => 'Waiting to send',
        'skipped'      => 'Skipped',
        'failed'       => 'Failed',
    ];

    /** Counts for many campaigns at once, keyed by campaign id (for the list). */
    public static function countsFor(array $ids): array
    {
        if (! $ids) return [];
        $rows = DB::table('platform_campaign_sends')->whereIn('campaign_id', $ids)
            ->groupBy('campaign_id')
            ->selectRaw("campaign_id,
                COUNT(*) AS total,
                SUM(status = 'sent') AS sent,
                SUM(status = 'pending') AS pending,
                SUM(status = 'skipped') AS skipped,
                SUM(status = 'failed') AS failed,
                SUM(opened_at IS NOT NULL) AS opened,
                SUM(clicked_at IS NOT NULL) AS clicked,
                SUM(bounced_at IS NOT NULL) AS bounced,
                SUM(complained_at IS NOT NULL) AS complained,
                SUM(unsubscribed_at IS NOT NULL) AS unsubscribed,
                SUM(status = 'sent' AND sent_at >= ?) AS last_hour,
                MIN(sent_at) AS first_sent, MAX(sent_at) AS last_sent", [now()->subHour()])
            ->get();

        $replies = self::replyCounts($ids);
        $out = [];
        foreach ($rows as $r) {
            $c = array_map(fn ($v) => is_numeric($v) ? (int) $v : $v, (array) $r);
            $c['replied'] = $replies[$r->campaign_id] ?? 0;
            $out[$r->campaign_id] = self::rates($c);
        }
        return $out;
    }

    /** Real (not automatic) replies per campaign. */
    private static function replyCounts(array $ids): array
    {
        try {
            return DB::table('platform_inbox_messages as m')
                ->whereIn('m.campaign_id', $ids)
                ->whereExists(fn ($q) => $q->select(DB::raw(1))->from('platform_inbox_replies as r')
                    ->whereColumn('r.message_id', 'm.id')->where('r.direction', 'in')->where('r.is_auto', false))
                ->groupBy('m.campaign_id')
                ->selectRaw('m.campaign_id, COUNT(DISTINCT m.email) AS n')
                ->pluck('n', 'campaign_id')->map(fn ($n) => (int) $n)->all();
        } catch (\Throwable $e) {
            return [];
        }
    }

    private static function rates(array $c): array
    {
        $delivered = max(0, $c['sent'] - $c['bounced']);
        $pct = fn ($n, $d) => $d > 0 ? round($n / $d * 100, 1) : null;
        $c['delivered']      = $delivered;
        $c['open_rate']      = $pct($c['opened'], $delivered);
        $c['click_rate']     = $pct($c['clicked'], $delivered);
        $c['reply_rate']     = $pct($c['replied'], $delivered);
        $c['bounce_rate']    = $pct($c['bounced'], $c['sent']);
        $c['unsub_rate']     = $pct($c['unsubscribed'], $delivered);
        $c['done']           = $c['sent'] + $c['skipped'] + $c['failed'];
        $c['progress']       = $c['total'] > 0 ? (int) floor($c['done'] / $c['total'] * 100) : 0;

        // finish estimate from the pace of the last hour, not a guess at the pacing rules
        $c['eta_minutes'] = ($c['pending'] > 0 && $c['last_hour'] > 0)
            ? (int) ceil($c['pending'] / $c['last_hour'] * 60) : null;
        return $c;
    }

    public static function summary(PlatformCampaign $campaign): array
    {
        $c = self::countsFor([$campaign->id])[$campaign->id] ?? self::rates([
            'total' => 0, 'sent' => 0, 'pending' => 0, 'skipped' => 0, 'failed' => 0, 'opened' => 0, 'clicked' => 0,
            'bounced' => 0, 'complained' => 0, 'unsubscribed' => 0, 'last_hour' => 0, 'first_sent' => null, 'last_sent' => null, 'replied' => 0,
        ]);

        $c['skip_reasons'] = PlatformCampaignSend::where('campaign_id', $campaign->id)->where('status', 'skipped')
            ->groupBy('skip_reason')->selectRaw("COALESCE(skip_reason, 'no reason given') AS r, COUNT(*) AS n")
            ->orderByDesc('n')->limit(6)->pluck('n', 'r')->all();
        $c['bounce_types'] = PlatformCampaignSend::where('campaign_id', $campaign->id)->whereNotNull('bounced_at')
            ->groupBy('bounce_type')->selectRaw("COALESCE(bounce_type, 'Bounce') AS r, COUNT(*) AS n")
            ->orderByDesc('n')->pluck('n', 'r')->all();
        $c['timeline'] = self::timeline($campaign);
        return $c;
    }

    /**
     * Cumulative sent / opened / clicked per day, from the first send, in the
     * admin's time zone. Hourly for a campaign under two days old.
     */
    private static function timeline(PlatformCampaign $campaign): array
    {
        $rows = PlatformCampaignSend::where('campaign_id', $campaign->id)
            ->where(fn ($q) => $q->whereNotNull('sent_at')->orWhereNotNull('opened_at'))
            ->get(['sent_at', 'opened_at', 'clicked_at']);
        $first = $rows->pluck('sent_at')->filter()->min();
        if (! $first) return ['labels' => [], 'sent' => [], 'opened' => [], 'clicked' => [], 'unit' => 'day'];

        $tz     = admin_tz();
        $start  = $first->copy()->setTimezone($tz);
        $hourly = $start->diffInHours(now()) < 48;
        $start  = $hourly ? $start->startOfHour() : $start->startOfDay();
        $end    = now()->setTimezone($tz);
        $steps  = [];
        for ($t = $start->copy(); $t <= $end && count($steps) < 60; $hourly ? $t->addHour() : $t->addDay()) {
            $steps[] = $t->copy();
        }
        $bucket = function ($dt) use ($start, $hourly, $tz, $steps) {
            if (! $dt) return null;
            $d = $dt->copy()->setTimezone($tz);
            $i = $hourly ? (int) floor($start->diffInHours($d, false)) : (int) floor($start->diffInDays($d, false));
            return max(0, min(count($steps) - 1, $i));
        };
        $n = count($steps);
        $sent = $opened = $clicked = array_fill(0, $n, 0);
        foreach ($rows as $r) {
            if (($i = $bucket($r->sent_at)) !== null) $sent[$i]++;
            if (($i = $bucket($r->opened_at)) !== null) $opened[$i]++;
            if (($i = $bucket($r->clicked_at)) !== null) $clicked[$i]++;
        }
        $acc = function (array $a) { $s = 0; return array_map(function ($v) use (&$s) { return $s += $v; }, $a); };
        return [
            'labels'  => array_map(fn ($t) => $t->format($hourly ? 'D g a' : 'M j'), $steps),
            'sent'    => $acc($sent),
            'opened'  => $acc($opened),
            'clicked' => $acc($clicked),
            'unit'    => $hourly ? 'hour' : 'day',
        ];
    }

    /** One page of recipients for a filter, newest activity first. */
    public static function recipients(PlatformCampaign $campaign, string $filter, string $search, int $page): array
    {
        $q = DB::table('platform_campaign_sends as s')->where('s.campaign_id', $campaign->id);

        $repliedEmails = fn ($sub) => $sub->select('m.email')->from('platform_inbox_messages as m')
            ->where('m.campaign_id', $campaign->id)
            ->whereExists(fn ($r) => $r->select(DB::raw(1))->from('platform_inbox_replies as r')
                ->whereColumn('r.message_id', 'm.id')->where('r.direction', 'in')->where('r.is_auto', false));

        match ($filter) {
            'opened'       => $q->whereNotNull('s.opened_at'),
            'clicked'      => $q->whereNotNull('s.clicked_at'),
            'replied'      => $q->whereIn('s.email', $repliedEmails),
            'unopened'     => $q->where('s.status', 'sent')->whereNull('s.opened_at')->whereNull('s.bounced_at'),
            'bounced'      => $q->whereNotNull('s.bounced_at'),
            'complained'   => $q->whereNotNull('s.complained_at'),
            'unsubscribed' => $q->whereNotNull('s.unsubscribed_at'),
            'pending', 'skipped', 'failed' => $q->where('s.status', $filter),
            default        => null,
        };
        $search = trim($search);
        if ($search !== '') {
            $like = '%' . str_replace(['%', '_'], ['\%', '\_'], mb_strtolower($search)) . '%';
            $q->where(fn ($w) => $w->whereRaw('LOWER(s.email) LIKE ?', [$like])->orWhereRaw('LOWER(s.name) LIKE ?', [$like]));
        }

        $total = (clone $q)->count();
        $pages = max(1, (int) ceil($total / self::PER_PAGE));
        $page  = max(1, min($page, $pages));

        $rows = $q->orderByRaw('COALESCE(s.clicked_at, s.opened_at, s.bounced_at, s.sent_at, s.created_at) DESC')
            ->offset(($page - 1) * self::PER_PAGE)->limit(self::PER_PAGE)
            ->get(['s.id', 's.email', 's.name', 's.source_type', 's.source_id', 's.status', 's.skip_reason', 's.error',
                   's.sent_at', 's.opened_at', 's.clicked_at', 's.bounced_at', 's.bounce_type', 's.complained_at', 's.unsubscribed_at', 's.channel', 's.send_count']);

        // replies and the inbox thread to open, for just this page
        $threads = DB::table('platform_inbox_messages as m')->where('m.campaign_id', $campaign->id)
            ->whereIn('m.email', $rows->pluck('email'))
            ->selectRaw("m.email, m.id, (SELECT MIN(r.created_at) FROM platform_inbox_replies r WHERE r.message_id = m.id AND r.direction = 'in' AND r.is_auto = 0) AS replied_at")
            ->get()->keyBy('email');

        return [
            'rows'  => $rows->map(function ($r) use ($threads) {
                $t = $threads[$r->email] ?? null;
                $r->replied_at = $t->replied_at ?? null;
                $r->thread_id  = $t->id ?? null;
                return $r;
            })->all(),
            'total' => $total,
            'page'  => $page,
            'pages' => $pages,
            'from'  => $total ? ($page - 1) * self::PER_PAGE + 1 : 0,
            'to'    => min($total, $page * self::PER_PAGE),
        ];
    }
}
