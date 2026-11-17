<?php

namespace App\Services\Tenant;

use App\Models\Tenant\TenantCampaign;
use Illuminate\Support\Facades\DB;

/**
 * MARKER-SHOP-CAMPAIGN-REPORT: a shop campaign's report and the numbers on
 * the campaign list, always counted from the send rows. Times are the shop's.
 */
class CampaignReport
{
    public const PER_PAGE = 50;

    public const FILTERS = [
        'all'          => 'Everyone',
        'opened'       => 'Opened',
        'clicked'      => 'Clicked',
        'unopened'     => 'Not opened',
        'bounced'      => 'Bounced',
        'complained'   => 'Marked spam',
        'unsubscribed' => 'Unsubscribed',
        'pending'      => 'Waiting to send',
        'skipped'      => 'Skipped',
        'failed'       => 'Failed',
    ];

    /** Counts for several campaigns at once, keyed by campaign id. */
    public static function countsFor(array $ids): array
    {
        if (! $ids) return [];
        $rows = DB::table('tenant_campaign_sends')->whereIn('campaign_id', $ids)
            ->groupBy('campaign_id')
            ->selectRaw("campaign_id,
                COUNT(*) AS total,
                SUM(status = 'sent') AS sent,
                SUM(status = 'pending') AS pending,
                SUM(status = 'skipped') AS skipped,
                SUM(status = 'failed') AS failed,
                SUM(status = 'bounced') AS bounced,
                SUM(opened_at IS NOT NULL) AS opened,
                SUM(clicked_at IS NOT NULL) AS clicked,
                SUM(complained_at IS NOT NULL) AS complained,
                SUM(unsubscribed_at IS NOT NULL) AS unsubscribed,
                SUM(status IN ('sent','bounced') AND sent_at >= ?) AS last_hour,
                MIN(sent_at) AS first_sent", [now()->subHour()])
            ->get();

        $out = [];
        foreach ($rows as $r) {
            $c = array_map(fn ($v) => is_numeric($v) ? (int) $v : $v, (array) $r);
            $out[$r->campaign_id] = self::rates($c);
        }
        return $out;
    }

    private static function rates(array $c): array
    {
        // a bounced row was sent and then rejected, so it counts as sent here
        $c['went_out']   = $c['sent'] + $c['bounced'];
        $c['delivered']  = $c['sent'];
        $pct = fn ($n, $d) => $d > 0 ? round($n / $d * 100, 1) : null;
        $c['open_rate']  = $pct($c['opened'], $c['delivered']);
        $c['click_rate'] = $pct($c['clicked'], $c['delivered']);
        $c['bounce_rate'] = $pct($c['bounced'], $c['went_out']);
        $c['unsub_rate'] = $pct($c['unsubscribed'], $c['delivered']);
        $c['done']       = $c['went_out'] + $c['skipped'] + $c['failed'];
        $c['progress']   = $c['total'] > 0 ? (int) floor($c['done'] / $c['total'] * 100) : 0;
        $c['eta_minutes'] = ($c['pending'] > 0 && $c['last_hour'] > 0) ? (int) ceil($c['pending'] / $c['last_hour'] * 60) : null;
        return $c;
    }

    private const EMPTY = ['total' => 0, 'sent' => 0, 'pending' => 0, 'skipped' => 0, 'failed' => 0, 'bounced' => 0, 'opened' => 0,
        'clicked' => 0, 'complained' => 0, 'unsubscribed' => 0, 'last_hour' => 0, 'first_sent' => null];

    public static function summary(TenantCampaign $campaign): array
    {
        $c = self::countsFor([$campaign->id])[$campaign->id] ?? self::rates(self::EMPTY);
        $c['skip_reasons'] = DB::table('tenant_campaign_sends')->where('campaign_id', $campaign->id)
            ->whereIn('status', ['skipped', 'failed'])
            ->selectRaw("COALESCE(NULLIF(error_message, ''), 'no reason given') AS r, COUNT(*) AS n")
            ->groupBy('r')->orderByDesc('n')->limit(6)->pluck('n', 'r')->all();
        $c['bounce_types'] = DB::table('tenant_campaign_sends')->where('campaign_id', $campaign->id)->where('status', 'bounced')
            ->selectRaw("COALESCE(bounce_type, 'Bounce') AS r, COUNT(*) AS n")
            ->groupBy('r')->orderByDesc('n')->pluck('n', 'r')->all();
        $c['timeline'] = self::timeline($campaign);
        return $c;
    }

    /** Running totals of sent, opened and clicked: by hour for the first two days, then by day. */
    private static function timeline(TenantCampaign $campaign): array
    {
        $rows = DB::table('tenant_campaign_sends')->where('campaign_id', $campaign->id)
            ->whereNotNull('sent_at')->get(['sent_at', 'opened_at', 'clicked_at']);
        if ($rows->isEmpty()) return ['labels' => [], 'sent' => [], 'opened' => [], 'clicked' => [], 'unit' => 'day'];

        $tz     = tenant()?->timezone() ?? config('app.timezone', 'UTC');
        $parse  = fn ($v) => $v ? \Carbon\Carbon::parse($v, 'UTC')->setTimezone($tz) : null;
        $first  = $rows->map(fn ($r) => $parse($r->sent_at))->min();
        $hourly = $first->diffInHours(now()) < 48;
        $start  = $hourly ? $first->copy()->startOfHour() : $first->copy()->startOfDay();
        $steps  = [];
        for ($t = $start->copy(); $t <= now()->setTimezone($tz) && count($steps) < 60; $hourly ? $t->addHour() : $t->addDay()) {
            $steps[] = $t->copy();
        }
        $n = max(1, count($steps));
        $at = function ($v) use ($parse, $start, $hourly, $n) {
            $d = $parse($v);
            if (! $d) return null;
            $i = $hourly ? (int) floor($start->diffInHours($d, false)) : (int) floor($start->diffInDays($d, false));
            return max(0, min($n - 1, $i));
        };
        $sent = $opened = $clicked = array_fill(0, $n, 0);
        foreach ($rows as $r) {
            if (($i = $at($r->sent_at)) !== null) $sent[$i]++;
            if (($i = $at($r->opened_at)) !== null) $opened[$i]++;
            if (($i = $at($r->clicked_at)) !== null) $clicked[$i]++;
        }
        $acc = function (array $a) { $s = 0; return array_map(function ($v) use (&$s) { return $s += $v; }, $a); };
        return [
            'labels'  => array_map(fn ($t) => $t->format($hourly ? 'D g a' : 'M j'), $steps ?: [$start]),
            'sent'    => $acc($sent), 'opened' => $acc($opened), 'clicked' => $acc($clicked),
            'unit'    => $hourly ? 'hour' : 'day',
        ];
    }

    /** One page of recipients for a filter and search. */
    public static function recipients(TenantCampaign $campaign, string $filter, string $search, int $page): array
    {
        $q = DB::table('tenant_campaign_sends as s')
            ->leftJoin('tenant_customers as cu', 'cu.id', '=', 's.customer_id')
            ->where('s.campaign_id', $campaign->id);

        match ($filter) {
            'opened'       => $q->whereNotNull('s.opened_at'),
            'clicked'      => $q->whereNotNull('s.clicked_at'),
            'unopened'     => $q->where('s.status', 'sent')->whereNull('s.opened_at'),
            'bounced'      => $q->where('s.status', 'bounced'),
            'complained'   => $q->whereNotNull('s.complained_at'),
            'unsubscribed' => $q->whereNotNull('s.unsubscribed_at'),
            'pending', 'skipped', 'failed' => $q->where('s.status', $filter),
            default        => null,
        };
        $search = trim($search);
        if ($search !== '') {
            $like = '%' . str_replace(['%', '_'], ['\%', '\_'], mb_strtolower($search)) . '%';
            $q->where(fn ($w) => $w->whereRaw('LOWER(s.email) LIKE ?', [$like])
                ->orWhereRaw("LOWER(CONCAT(COALESCE(cu.first_name,''), ' ', COALESCE(cu.last_name,''))) LIKE ?", [$like]));
        }

        $total = (clone $q)->count();
        $pages = max(1, (int) ceil($total / self::PER_PAGE));
        $page  = max(1, min($page, $pages));
        $rows  = $q->orderByRaw('COALESCE(s.clicked_at, s.opened_at, s.sent_at, s.created_at) DESC')
            ->offset(($page - 1) * self::PER_PAGE)->limit(self::PER_PAGE)
            ->get(['s.id', 's.email', 's.customer_id', 's.status', 's.error_message', 's.sent_at', 's.opened_at', 's.clicked_at',
                   's.open_count', 's.click_count', 's.bounce_type', 's.complained_at', 's.unsubscribed_at', 'cu.first_name', 'cu.last_name']);

        return ['rows' => $rows->all(), 'total' => $total, 'page' => $page, 'pages' => $pages,
                'from' => $total ? ($page - 1) * self::PER_PAGE + 1 : 0, 'to' => min($total, $page * self::PER_PAGE)];
    }
}
