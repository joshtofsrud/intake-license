<?php

namespace App\Services\Sales;

use App\Models\SalesActivity;
use App\Models\SalesProspect;
use App\Models\Tenant;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * MARKER-OUTREACH-SCANNERS: was a visit through a prospect's email link a
 * person, or an email security filter checking the link?
 *
 * Filters (Microsoft Safe Links, Proofpoint, Mimecast, Barracuda, Google...)
 * open every link in a hidden browser within a minute or two of delivery,
 * often several at once, and run the page's script, so they reached the
 * tracker and counted as visits. The signals, strongest first:
 *
 *   interacted   a mouse moved, a key, a tap or a wheel: a person
 *   network      the visit came from a mail-filter or data-centre network
 *                (by the address's reverse DNS name; the address isn't kept)
 *   home network a home or phone connection: a person
 *   burst        several sessions from the same link within a few seconds
 *   fast         desktop, within 2 minutes of the email, gone within 30 s
 *   re-check     one page under 10 s after a filter already opened the link
 *   phone        on a phone or tablet: a person
 *   stayed       30 s or more on the site: a person
 *
 * Anything else is "unsure". Only people count as visited/clicked, get a
 * timeline entry, and appear in Marketing traffic; scanner sessions are
 * marked as bots there.
 */
class OutreachVisitJudge
{
    public const FILTER_NETWORKS = ['microsoft', 'google', 'proofpoint', 'mimecast', 'barracuda', 'cisco', 'datacenter'];

    public const NETWORK_LABELS = [
        'microsoft'  => 'Microsoft',
        'google'     => 'Google',
        'proofpoint' => 'Proofpoint',
        'mimecast'   => 'Mimecast',
        'barracuda'  => 'Barracuda',
        'cisco'      => 'Cisco',
        'datacenter' => 'a data centre',
        'home'       => 'a home or phone connection',
    ];

    /** Reverse-DNS name patterns => network. First match wins. */
    private const HOST_PATTERNS = [
        'microsoft'  => '/(protection\.outlook\.com|\.outlook\.com$|microsoft\.com$|\.msn\.net$|cloudapp\.(azure\.com|net)$|\.azure\.com$)/i',
        'google'     => '/(google\.com$|googleusercontent\.com$|1e100\.net$|googlebot)/i',
        'proofpoint' => '/(pphosted\.com$|proofpoint)/i',
        'mimecast'   => '/mimecast/i',
        'barracuda'  => '/barracuda/i',
        'cisco'      => '/(iphmx\.com$|ironport|cisco\.com$)/i',
        'datacenter' => '/(amazonaws\.com$|linode|digitalocean|ovh\.|hetzner|vultr|leaseweb|contabo|choopa|oraclecloud|akamai|cloudflare|fastly|scaleway|googleusercontent|colocrossing|hostwinds|m247|datacamp|servers\.com)/i',
        'home'       => '/(comcast|verizon|vzw|att\.net|sbcglobal|charter|spectrum|\.rr\.com$|cox\.net|centurylink|qwest|frontier|t-?mobile|mycingular|shawcable|rogers|telus|videotron|bell\.ca|bigpond|optusnet|virginm|btcentral|sky\.com|starlink|wowway|mediacom|suddenlink|optimum|altice|xfinity|\bdsl|cable|fios|dynamic|dhcp|pool|broadband|residential|\.res\.)/i',
    ];

    /** The network an address belongs to, by its reverse DNS name. Slow: call from a job. */
    public static function networkOf(string $ip): string
    {
        if (! filter_var($ip, FILTER_VALIDATE_IP)) return 'unknown';

        return Cache::remember('outreach-net:' . hash('sha256', $ip), 86400, function () use ($ip) {
            $host = @gethostbyaddr($ip);
            if (! $host || $host === $ip) return 'unknown';
            foreach (self::HOST_PATTERNS as $net => $re) {
                if (preg_match($re, $host)) return $net;
            }
            return 'unknown';
        });
    }

    /** One visit's verdict, given its prospect's other visits. [verdict, reason] */
    public static function judge(object $v, array $all): array
    {
        $first = Carbon::parse($v->first_at);
        $dur   = max(0, Carbon::parse($v->last_at ?? $v->first_at)->getTimestamp() - $first->getTimestamp());
        $net   = (string) ($v->network ?? '');
        $desk  = ($v->device ?? 'desktop') === 'desktop' || ($v->device ?? null) === null;

        if (! empty($v->interacted_at)) return ['person', 'moved, scrolled or tapped on the page'];
        if (in_array($net, self::FILTER_NETWORKS, true)) {
            return ['scanner', 'came from ' . self::NETWORK_LABELS[$net] . "'s network, where mail filters check links"];
        }
        if ($net === 'home') return ['person', 'came from a home or phone connection'];

        $together = 0;
        $earlierScanner = false;
        foreach ($all as $o) {
            if ($o->id === $v->id) continue;
            $at = Carbon::parse($o->first_at);
            if (abs($at->getTimestamp() - $first->getTimestamp()) <= 3) $together++;
            if ($at->lt($first) && ($o->verdict ?? '') === 'scanner') $earlierScanner = true;
        }
        if ($together > 0) return ['scanner', 'opened ' . ($together + 1) . ' times within the same few seconds'];

        $after = $v->secs_after_send;
        if ($after !== null && (int) $after <= 120 && $dur <= 30 && $desk) {
            return ['scanner', 'opened ' . (int) $after . ' s after the email went out and gone in ' . $dur . ' s'];
        }
        if ((int) $v->pages <= 1 && $dur <= 10 && $desk && $earlierScanner) {
            return ['scanner', 'a quick re-check of a link a filter had already opened'];
        }
        if (in_array($v->device, ['mobile', 'tablet'], true)) return ['person', 'on a ' . ($v->device === 'mobile' ? 'phone' : 'tablet')];
        if ($dur >= 30) return ['person', 'stayed ' . self::duration($dur)];

        return ['unsure', 'too brief to tell'];
    }

    /**
     * Judge every visit of one prospect and apply what follows from it: the
     * timeline entry, "link clicked", and how Marketing traffic sees the session.
     */
    public static function rejudgeProspect(string $prospectId): void
    {
        $rows = DB::table('sales_prospect_visits')->where('sales_prospect_id', $prospectId)->orderBy('first_at')->orderBy('id')->get()->all();
        if (! $rows) return;

        // in time order, so a re-check can see that an earlier visit was a scanner
        foreach ($rows as $i => $v) {
            [$verdict, $why] = self::judge($v, $rows);
            if ($verdict !== $v->verdict || $why !== $v->verdict_reason) {
                DB::table('sales_prospect_visits')->where('id', $v->id)->update(['verdict' => $verdict, 'verdict_reason' => $why]);
                $rows[$i]->verdict = $verdict;
                $rows[$i]->verdict_reason = $why;
            }
        }

        $p = SalesProspect::find($prospectId);
        if (! $p) return;
        $firstPerson = null;
        foreach ($rows as $v) {
            self::applyTimeline($p, $v);
            self::applyTraffic($v);
            if ($v->verdict === 'person' && (! $firstPerson || Carbon::parse($v->first_at)->lt($firstPerson))) $firstPerson = Carbon::parse($v->first_at);
        }

        $was = $p->link_clicked_at;
        if (($was?->getTimestamp()) !== ($firstPerson?->getTimestamp())) {
            $p->forceFill(['link_clicked_at' => $firstPerson])->save();
        }
    }

    /** A person's visit gets a timeline entry kept up to date; anything else has none. */
    private static function applyTimeline(SalesProspect $p, object $v): void
    {
        if ($v->verdict !== 'person') {
            if ($v->activity_id) {
                SalesActivity::whereKey($v->activity_id)->delete();
                DB::table('sales_prospect_visits')->where('id', $v->id)->update(['activity_id' => null]);
            }
            return;
        }
        $paths = json_decode((string) $v->paths, true) ?: [];
        $pages = (int) $v->pages;
        $secs  = max(0, Carbon::parse($v->last_at ?? $v->first_at)->getTimestamp() - Carbon::parse($v->first_at)->getTimestamp());
        $dev   = ['mobile' => 'phone', 'tablet' => 'tablet', 'desktop' => 'computer'][$v->device] ?? null;
        $body  = 'From their email link: ' . implode(' → ', array_map([ProspectOutreach::class, 'pageLabel'], $paths))
            . ' · ' . $pages . ' ' . ($pages === 1 ? 'page' : 'pages') . ' · ' . self::duration($secs) . ($dev ? ' · ' . $dev : '');
        $body = mb_substr($body, 0, 1000);

        if ($v->activity_id && SalesActivity::whereKey($v->activity_id)->exists()) {
            SalesActivity::whereKey($v->activity_id)->update(['body' => $body]);
            return;
        }
        $a = $p->activities()->create(['type' => 'visit', 'body' => $body, 'occurred_at' => $v->first_at]);
        DB::table('sales_prospect_visits')->where('id', $v->id)->update(['activity_id' => $a->id]);
    }

    /** Scanner sessions are bots to Marketing traffic; a session that turns out to be a person comes back. */
    private static function applyTraffic(object $v): void
    {
        static $platformId = false;
        if ($platformId === false) $platformId = Tenant::where('is_platform', true)->value('id');
        if (! $platformId || ! $v->session_id) return;

        $q = DB::table('tenant_funnel_events')->where('tenant_id', $platformId)->where('session_id', $v->session_id)
            ->whereBetween('created_at', [Carbon::parse($v->first_at)->subSeconds(10), Carbon::parse($v->last_at ?? $v->first_at)->addMinutes(2)]);
        if ($v->verdict === 'scanner') {
            $q->where(fn ($w) => $w->whereNull('device')->orWhere('device', '!=', 'bot'))->update(['device' => 'bot']);
        } else {
            $q->where('device', 'bot')->update(['device' => $v->device ?: 'desktop']);
        }
    }

    /** Fill in what old visits can be judged by (the email they followed) and judge them all. */
    public static function backfill(): int
    {
        $pids = DB::table('sales_prospect_visits')->distinct()->pluck('sales_prospect_id');
        foreach (DB::table('sales_prospect_visits')->whereNull('sent_at')->get(['id', 'sales_prospect_id', 'first_at']) as $v) {
            $sent = DB::table('sales_outreach_sends')->where('sales_prospect_id', $v->sales_prospect_id)
                ->where('created_at', '<=', $v->first_at)->max('created_at');
            if ($sent) {
                DB::table('sales_prospect_visits')->where('id', $v->id)->update([
                    'sent_at' => $sent,
                    'secs_after_send' => Carbon::parse($v->first_at)->getTimestamp() - Carbon::parse($sent)->getTimestamp(),
                ]);
            }
        }
        foreach ($pids as $pid) self::rejudgeProspect((string) $pid);

        return count($pids);
    }

    public static function duration(int $secs): string
    {
        return $secs < 60 ? $secs . ' s' : intdiv($secs, 60) . ' min' . ($secs % 60 ? ' ' . ($secs % 60) . ' s' : '');
    }
}
