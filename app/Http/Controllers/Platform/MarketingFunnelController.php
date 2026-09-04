<?php

namespace App\Http\Controllers\Platform;

// MARKER-MKTTRAFFIC — the marketing site's own funnel endpoint.
//
// The tenant tracker posts to /funnel/track, which is declared inside the
// TENANT host group and resolves a tenant from the host. intake.works has no
// tenant host, so marketing pages could never reach it — which is why the
// marketing site has recorded nothing at all. This is the same contract on the
// platform host, writing under the platform tenant.

use App\Http\Controllers\Controller;
use App\Models\Tenant;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Cookie; // MARKER-MKTSID
use Illuminate\Support\Str;             // MARKER-MKTSID

class MarketingFunnelController extends Controller
{
    /** Event types the marketing site is allowed to record. */
    public const ALLOWED = [
        'page_view',
        'pricing_viewed',
        'quiz_started',
        'quiz_completed',
        'contact_submitted',
        'signup_started',    // reserved — self-serve signup not built yet
        'signup_completed',  // reserved
        // MARKER-MKTCONV — demo and booking. The two recorded server-side
        // (demo_entered, booking_completed) are the honest ones; cta_click
        // and page_exit come from the browser and can be blocked.
        'demo_entered',
        'booking_started', // MARKER-MKTTILES — matches the tile the service already counts
        'booking_completed',
        'cta_click',
        'page_exit',
    ];

    public function store(Request $request)
    {
        $data = $request->validate([
            'session_id'   => ['nullable', 'string', 'max:64'], // MARKER-MKTSID
            'event_type'   => ['required', 'string', 'max:32'],
            'path'         => ['nullable', 'string', 'max:255'],
            'referrer_url' => ['nullable', 'string', 'max:2048'],
            'utm_source'   => ['nullable', 'string', 'max:100'],
            'utm_medium'   => ['nullable', 'string', 'max:100'],
            'utm_campaign' => ['nullable', 'string', 'max:191'],
            'device'       => ['nullable', 'string', 'max:12'],
            'step'         => ['nullable', 'string', 'max:48'],
        ]);

        if (! in_array($data['event_type'], self::ALLOWED, true)) {
            return response()->json(['ok' => false], 204);
        }

        // MARKER-MKTBOTFIX -- classify from the User-Agent server-side (the
        // client's own guess is advisory) and drop crawler traffic before it
        // is written, exactly as the tenant tracker does. Every crawler page
        // hit was arriving with a fresh sessionStorage id, so each one
        // surfaced as its own 1-page session.
        $data['device'] = self::deviceFromUserAgent($request->userAgent() ?? '');

        if ($data['device'] === 'bot') {
            return response()->json(['ok' => true, 'skipped' => 'bot']);
        }

        // MARKER-MKTSID -- resolve the visitor's id, then persist it as a
        // cookie so it survives a tab close and a blocked sessionStorage.
        $data['session_id'] = $this->resolveSession($request);

        self::record($data['event_type'], $data);

        return response()->json(['ok' => true])->withCookie(
            Cookie::make('mkt_sid', $data['session_id'], 60 * 24 * 90, '/', null, true, true, false, 'lax')
        );
    }

    /**
     * MARKER-MKTSID -- anonymous session id, same shape as the tenant
     * tracker's resolveSession().
     *
     * The COOKIE wins when present and well-formed (see the body). Several first-visit
     * beacons can be in flight before any Set-Cookie lands, and preferring
     * the cookie would mint a separate id for each of them (the bug the
     * tenant side fixed in MARKER-FUNNEL-SESSION-FIX). The regex allows 12
     * chars because this tracker mints base36 time + random (~18), shorter
     * than the tenant's 40-char ids -- and it rejects the old 'nostore'
     * literal, which used to merge every storage-blocked visitor into one
     * shared session.
     */
    protected function resolveSession(Request $request): string
    {
        // MARKER-TRAFFIC-IDENTITY -- COOKIE FIRST, then payload, then random.
        //
        // The old order put the payload first, which made "visitors" mean
        // "sessions": sessionStorage dies with the tab, so the same person
        // returning tomorrow arrived with a brand-new id even though their
        // 90-day cookie was sitting right there.
        //
        // The comment below used to warn that preferring the cookie would mint
        // an id per in-flight beacon on a first visit. That is only true if the
        // cookie-miss case falls through to random() -- with the payload as the
        // middle step, those first-visit beacons still share the client's id,
        // because no cookie exists yet to prefer. Both problems are covered by
        // this order, and neither is by the other one.
        $cookie = (string) $request->cookie('mkt_sid', '');
        if ($cookie !== '' && preg_match('/^[a-zA-Z0-9]{12,64}$/', $cookie)) {
            return $cookie;
        }

        $fromPayload = (string) $request->input('session_id', '');
        if ($fromPayload !== '' && preg_match('/^[a-zA-Z0-9]{12,64}$/', $fromPayload)) {
            return $fromPayload;
        }

        return (string) Str::random(40);
    }

    /**
     * MARKER-MKTBOTFIX -- same coarse buckets as the tenant tracker's
     * FunnelTrackController. Enough for the mobile/desktop/tablet split;
     * deliberately not fingerprinting.
     */
    public static function deviceFromUserAgent(string $ua): string
    {
        $ua = strtolower($ua);

        if ($ua === '') {
            return 'unknown';
        }
        if (preg_match('/bot|crawl|spider|slurp|bingpreview|facebookexternalhit/', $ua)) {
            return 'bot';
        }
        if (str_contains($ua, 'ipad') || str_contains($ua, 'tablet')
            || (str_contains($ua, 'android') && ! str_contains($ua, 'mobile'))) {
            return 'tablet';
        }
        if (str_contains($ua, 'iphone') || str_contains($ua, 'mobile') || str_contains($ua, 'android')) {
            return 'mobile';
        }

        return 'desktop';
    }

    /**
     * Record a marketing funnel event. Also called server-side (quiz, contact)
     * where the server is the honest place to know the thing happened.
     * Best-effort: analytics must never break a page.
     */
    public static function record(string $eventType, array $data = []): void
    {
        try {
            // MARKER-MKTCONV — join server-side conversions to the browsing
            // that led to them. The tracker writes mkt_sid as a 90-day cookie.
            if (empty($data['session_id']) && request()) {
                $cookie = request()->cookie('mkt_sid');
                if (is_string($cookie) && $cookie !== '') {
                    $data['session_id'] = $cookie;
                }
                $data['path']         = $data['path'] ?? request()->path();
                $data['referrer_url'] = $data['referrer_url'] ?? request()->headers->get('referer');
                $data['device']       = $data['device'] ?? self::deviceFromUserAgent((string) request()->userAgent());
            }
            $tenant = Tenant::where('is_platform', true)->first();
            if (! $tenant) {
                return;
            }

            $referrer = $data['referrer_url'] ?? null;
            $host     = $referrer ? parse_url($referrer, PHP_URL_HOST) : null;

            DB::table('tenant_funnel_events')->insert([
                'tenant_id'       => $tenant->id,
                'session_id'      => substr((string) ($data['session_id'] ?? 'server'), 0, 64),
                'event_type'      => $eventType,
                'path'            => $data['path'] ?? null,
                'referrer_domain' => $host ? substr($host, 0, 191) : null,
                'referrer_url'    => $referrer,
                'utm_source'      => $data['utm_source'] ?? null,
                'utm_medium'      => $data['utm_medium'] ?? null,
                'utm_campaign'    => $data['utm_campaign'] ?? null,
                'device'          => $data['device'] ?? null,
                'step'            => $data['step'] ?? null,
                // MARKER-MKTFIX — this table has created_at ONLY (declared
                // useCurrent, no updated_at). Writing updated_at threw on every
                // insert, and the catch below made it silent.
                'created_at'      => now(),
            ]);
        } catch (\Throwable $e) {
            // Still swallowed on purpose — analytics must not break a page —
            // but at error level so it surfaces in any log scan. MARKER-MKTFIX
            \Log::error('marketing funnel event failed', [
                'event' => $eventType, 'error' => $e->getMessage(),
            ]);
        }
    }
}
