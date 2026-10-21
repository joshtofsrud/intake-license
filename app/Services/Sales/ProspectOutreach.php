<?php
// MARKER-EMAIL-OUTREACH
//
// One prospect email, start to finish: fill the template, give the shop its own
// link to the page you picked, send on the platform broadcast stream (never the
// transactional one), and log it. Used by Sales > Email outreach and by the
// Email button on a Prospects card, so both behave the same.
//
// The link is intake.works/<page>?p=<code>. OutreachLink turns that into a
// cookie and a clean URL; the marketing tracker's beacons then carry the cookie,
// and recordVisit() writes what the shop looked at onto its timeline. Only
// browsers that run the tracker count, so mail scanners that pre-open links
// don't show up as visits.

namespace App\Services\Sales;

use App\Models\PlatformEmailOptout;
use App\Models\SalesEmailTemplate;
use App\Models\SalesProspect;
use App\Services\Platform\PlatformMailer;
use App\Support\MarketingNav;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class ProspectOutreach
{
    public const TOKENS = ['[first_name]', '[shop]', '[city]', '[link]'];

    /** A new visit starts after this long without a page from the same browser. */
    public const VISIT_GAP_MINUTES = 30;

    private const CODE_CHARS = 'abcdefghjkmnpqrstuvwxyz23456789';

    // ------------------------------------------------------------ landing pages

    /** ['pages' => [path => title], 'industries' => [path => label]]: published pages only. */
    public static function landingPages(): array
    {
        static $cache = null;
        if ($cache !== null) return $cache;

        $pages = ['/' => 'Home page'];
        foreach (MarketingNav::pages() as $pg) {
            if (! $pg['published'] || $pg['path'] === '/') continue;
            $pages[$pg['path']] = $pg['title'] !== '' ? $pg['title'] : $pg['path'];
        }

        // /for/<industry> pages exist only while the shared industry template is published.
        $industries = [];
        try {
            $platform = MarketingNav::platform();
            $hasTemplate = $platform && \App\Models\Tenant\TenantPage::where('tenant_id', $platform->id)
                ->where('slug', '__for-industry')->where('is_published', true)->exists();
            if ($hasTemplate) {
                foreach (app(\App\Services\IndustryPackService::class)->all() as $key => $pack) {
                    $path = '/for/' . $key;
                    if ($key === 'bike-shops' || isset($pages[$path])) continue; // /for/bike-shops redirects to /bike-shops
                    $industries[$path] = 'For ' . ($pack['name'] ?? $key);
                }
            }
        } catch (\Throwable $e) {
            report($e);
        }

        return $cache = ['pages' => $pages, 'industries' => $industries];
    }

    public static function allPages(): array
    {
        $l = self::landingPages();
        return $l['pages'] + $l['industries'];
    }

    /** A path we can link to; anything else falls back to the home page. */
    public static function normalizePage(?string $page): string
    {
        $page = (string) $page;
        return array_key_exists($page, self::allPages()) ? $page : '/';
    }

    public static function pageLabel(?string $path): string
    {
        $path = (string) $path;
        if ($path === '' || $path === '/') return 'Home page';
        $all = self::allPages();
        return $all[$path] ?? $path;
    }

    // ------------------------------------------------------------ the shop's link

    public static function code(SalesProspect $p): string
    {
        if ($p->outreach_code) return (string) $p->outreach_code;
        for ($i = 0; $i < 6; $i++) {
            $code = '';
            for ($j = 0; $j < 8; $j++) $code .= self::CODE_CHARS[random_int(0, strlen(self::CODE_CHARS) - 1)];
            if (SalesProspect::where('outreach_code', $code)->exists()) continue;
            $p->forceFill(['outreach_code' => $code])->save();
            return $code;
        }
        throw new \RuntimeException('Could not make a unique link code');
    }

    public static function link(SalesProspect $p, ?string $page): string
    {
        $path = self::normalizePage($page);
        return 'https://' . config('intake.domain', 'intake.works') . $path . '?p=' . self::code($p);
    }

    // ------------------------------------------------------------ filling a template

    public static function firstName(SalesProspect $p): string
    {
        return trim(explode(' ', trim((string) $p->owner_contact))[0] ?? '');
    }

    /** Replace the tokens. With no owner name, "Hi [first_name]," reads "Hi,". */
    public static function fill(string $text, SalesProspect $p, string $link = ''): string
    {
        $first = self::firstName($p);
        if ($first === '') {
            $text = preg_replace('/[ \t]*\[first_name\]/', '', $text);
        }
        return strtr($text, [
            '[first_name]' => $first,
            '[shop]'       => (string) $p->shop,
            '[city]'       => (string) $p->city,
            '[link]'       => $link,
        ]);
    }

    public static function subject(string $subject, SalesProspect $p): string
    {
        return trim(preg_replace('/\s+/', ' ', self::fill(str_replace('[link]', '', $subject), $p)));
    }

    public static function footer(string $to): string
    {
        return '<p style="font-size:12px;color:#777;margin-top:28px">' . e(PlatformMailer::fromName())
            . (PlatformMailer::postalAddress() ? ' · ' . e(PlatformMailer::postalAddress()) : '')
            . '<br><a href="' . e(\App\Http\Controllers\Platform\PlatformUnsubscribeController::url($to)) . '" style="color:#777">Unsubscribe</a></p>';
    }

    /** The email exactly as it goes out (also the preview). */
    public static function html(string $body, SalesProspect $p, ?string $page, string $to): string
    {
        $marker = "\u{E000}LINK\u{E000}";
        $text = e(self::fill(trim($body), $p, $marker));
        if (str_contains($text, $marker)) {
            $url  = self::link($p, $page);
            $text = str_replace($marker, '<a href="' . e($url) . '" style="color:#2563eb">' . e($url) . '</a>', $text);
        }
        return '<div style="font-family:Inter,Arial,sans-serif;font-size:15px;line-height:1.6;color:#111">'
            . nl2br($text) . self::footer($to) . '</div>';
    }

    // ------------------------------------------------------------ sending

    /** @return array{0: bool, 1: string} sent?, message for the person sending */
    public static function send(SalesProspect $p, string $subject, string $body, ?string $page, ?string $templateId = null): array
    {
        $to = mb_strtolower(trim((string) $p->email));
        if (! filter_var($to, FILTER_VALIDATE_EMAIL)) {
            return [false, 'Add an email address for this shop first'];
        }
        if (PlatformEmailOptout::has($to)) {
            return [false, 'Not sent: this address unsubscribed from Intake email'];
        }

        $page    = self::normalizePage($page);
        $subject = self::subject($subject, $p);
        if ($subject === '') {
            return [false, 'Add a subject'];
        }
        $html    = self::html($body, $p, $page, $to);
        $tpl     = $templateId ? SalesEmailTemplate::find($templateId) : null;
        $user    = Auth::guard('web')->user();

        $thread = \App\Models\PlatformInboxMessage::create([
            'kind'    => \App\Models\PlatformInboxMessage::KIND_CONTACT,
            'status'  => 'archived',
            'name'    => $p->owner_contact ?: $p->shop,
            'email'   => $to,
            'company' => $p->shop,
            'subject' => $subject,
            'body'    => 'Emailed from Prospects: ' . trim(self::fill($body, $p, self::link($p, $page))),
            'meta'    => ['origin' => 'prospect_email', 'prospect' => $p->id, 'template' => $tpl?->id, 'page' => $page],
        ]);

        try {
            $ok = PlatformMailer::send($to, $p->owner_contact ?: null, $subject, $html,
                \App\Http\Controllers\Platform\PlatformUnsubscribeController::url($to),
                ['X-PM-Metadata-prospect' => $p->id, 'X-PM-Metadata-platform_thread' => $thread->id], $thread->replyToken());
        } catch (\Throwable $e) {
            report($e);
            PlatformMailer::log('prospect', $to, $subject, ['status' => 'failed', 'error' => mb_substr($e->getMessage(), 0, 400)]);
            $thread->delete();
            return [false, 'Not sent: ' . $e->getMessage()];
        }
        if (! $ok) {
            $thread->delete();
            return [false, 'Not sent: platform email has no broadcast stream set. Set it on Platform email, then try again.'];
        }

        PlatformMailer::log('prospect', $to, $subject);
        $p->activities()->create([
            'type' => 'email',
            'body' => 'Emailed: ' . $subject . ' (' . ($user?->name ?? 'staff') . ')'
                . ($tpl ? ' · template: ' . $tpl->name : '') . ' · lands on: ' . self::pageLabel($page),
        ]);

        $changes = ['last_contacted_at' => now(), 'emailed_at' => now(), 'email_skipped_at' => null];
        if ($p->stage === 'prospect') $changes['stage'] = 'contacted';
        $p->forceFill($changes)->save();

        try {
            DB::table('sales_outreach_sends')->insert([
                'sales_prospect_id' => $p->id,
                'template_id'       => $tpl?->id,
                'page'              => $page,
                'email'             => $to,
                'subject'           => mb_substr($subject, 0, 191),
                'sent_by'           => $user?->id,
                'thread_id'         => (string) $thread->id,
                'created_at'        => now(),
            ]);
        } catch (\Throwable $e) {
            report($e); // the email went; a missing tally row must not say otherwise
        }

        return [true, 'Sent to ' . $to];
    }

    // ------------------------------------------------------------ remembered choices

    /** This admin's remembered outreach choices (template, page, filters). */
    public static function prefs(): array
    {
        $u = Auth::guard('web')->user();
        if (! $u) return [];
        try {
            $all = json_decode((string) DB::table('users')->where('id', $u->id)->value('admin_prefs'), true);
        } catch (\Throwable $e) {
            return [];
        }
        return is_array($all['outreach'] ?? null) ? $all['outreach'] : [];
    }

    public static function savePrefs(array $changes): void
    {
        $u = Auth::guard('web')->user();
        if (! $u) return;
        try {
            $all = json_decode((string) DB::table('users')->where('id', $u->id)->value('admin_prefs'), true);
            $all = is_array($all) ? $all : [];
            $all['outreach'] = array_merge(is_array($all['outreach'] ?? null) ? $all['outreach'] : [], $changes);
            DB::table('users')->where('id', $u->id)->update(['admin_prefs' => json_encode($all)]);
        } catch (\Throwable $e) {
            report($e);
        }
    }

    // ------------------------------------------------------------ visits from the link

    /**
     * Called by the marketing tracker endpoint for each beacon. $code comes from
     * the mkt_p cookie OutreachLink set when the shop opened its link.
     */
    public static function recordVisit(string $code, string $sessionId, string $eventType, ?string $path, ?string $device): void
    {
        if (! preg_match('/^[a-z0-9]{8}$/', $code) || $sessionId === '') return;
        if (! in_array($eventType, ['page_view', 'page_exit', 'cta_click'], true)) return;

        $p = SalesProspect::where('outreach_code', $code)->first();
        if (! $p) return;

        $v = DB::table('sales_prospect_visits')
            ->where('sales_prospect_id', $p->id)->where('session_id', $sessionId)
            ->where('last_at', '>=', now()->subMinutes(self::VISIT_GAP_MINUTES))
            ->orderByDesc('last_at')->first();

        if (! $v) {
            if ($eventType !== 'page_view') return;
            $activity = $p->activities()->create(['type' => 'visit', 'body' => 'From their email link', 'occurred_at' => now()]);
            $id = DB::table('sales_prospect_visits')->insertGetId([
                'sales_prospect_id' => $p->id,
                'session_id'        => mb_substr($sessionId, 0, 64),
                'activity_id'       => $activity->id,
                'paths'             => json_encode([]),
                'pages'             => 0,
                'device'            => $device ? mb_substr($device, 0, 12) : null,
                'first_at'          => now(),
                'last_at'           => now(),
            ]);
            $v = DB::table('sales_prospect_visits')->where('id', $id)->first();
            if (! $p->link_clicked_at) $p->forceFill(['link_clicked_at' => now()])->save();
        }

        $paths = json_decode((string) $v->paths, true) ?: [];
        $pages = (int) $v->pages;
        if ($eventType === 'page_view') {
            $path = '/' . ltrim((string) $path, '/');
            if (end($paths) !== $path) $paths[] = $path;
            $pages++;
        }
        $paths = array_slice($paths, -12);
        DB::table('sales_prospect_visits')->where('id', $v->id)->update([
            'paths' => json_encode($paths), 'pages' => $pages, 'last_at' => now(),
        ]);

        if ($v->activity_id) {
            $secs = max(0, now()->getTimestamp() - \Illuminate\Support\Carbon::parse($v->first_at)->getTimestamp());
            $dur  = $secs < 60 ? $secs . 's' : intdiv($secs, 60) . 'm' . ($secs % 60 ? ' ' . ($secs % 60) . 's' : '');
            $dev  = ['mobile' => 'phone', 'tablet' => 'tablet', 'desktop' => 'computer'][$v->device] ?? null;
            $body = 'From their email link: ' . implode(' → ', array_map([self::class, 'pageLabel'], $paths))
                . ' · ' . $pages . ' ' . ($pages === 1 ? 'page' : 'pages') . ' · ' . $dur . ($dev ? ' · ' . $dev : '');
            \App\Models\SalesActivity::whereKey($v->activity_id)->update(['body' => mb_substr($body, 0, 1000)]);
        }
    }
}
