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
    ];

    public function store(Request $request)
    {
        $data = $request->validate([
            'session_id'   => ['required', 'string', 'max:64'],
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

        self::record($data['event_type'], $data);

        return response()->json(['ok' => true]);
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
