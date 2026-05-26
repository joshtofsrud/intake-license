<?php
// MARKER-PATCH-149

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Models\Tenant\TenantFunnelEvent;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;

/**
 * FunnelTrackController — receives anonymous funnel events from the
 * tenant's public-facing pages (storefront, booking flow).
 *
 * Privacy:
 *   - No IP storage
 *   - Coarse device bucket only (mobile/desktop/tablet/bot)
 *   - Anonymous session cookie, 90-day lifetime, http-only, same-site lax
 *
 * Performance:
 *   - Lightweight write; rate-limited per IP to prevent abuse
 *   - Fire-and-forget from the client (no response body shape required)
 */
class FunnelTrackController extends Controller
{
    /**
     * POST /funnel/track
     */
    public function store(Request $request)
    {
        $tenant = tenant();
        if (! $tenant) {
            return response()->json(['ok' => false, 'reason' => 'no_tenant'], 404);
        }

        // Rate limit: 60 events / minute per IP per tenant. Generous
        // enough for legitimate use (page_view per nav, booking events
        // per step) but blocks abusive scripted floods.
        $key = 'funnel-track:' . $request->ip() . ':' . $tenant->id;
        if (RateLimiter::tooManyAttempts($key, 60)) {
            return response()->json(['ok' => false, 'reason' => 'rate_limited'], 429);
        }
        RateLimiter::hit($key, 60);

        $data = $request->validate([
            'event_type'   => ['required', 'string', 'in:' . implode(',', TenantFunnelEvent::VALID_TYPES)],
            'path'         => ['nullable', 'string', 'max:255'],
            'referrer_url' => ['nullable', 'string', 'max:2048'],
            'utm_source'   => ['nullable', 'string', 'max:100'],
            'utm_medium'   => ['nullable', 'string', 'max:100'],
            'utm_campaign' => ['nullable', 'string', 'max:191'],
        ]);

        // Session cookie — anonymous, 90 days
        [$sessionId, $isNew] = $this->resolveSession($request);

        // Device bucket from UA
        $device = $this->deviceFromUserAgent($request->userAgent() ?? '');

        // Skip bot traffic for funnel cleanliness
        if ($device === 'bot') {
            return response()->json(['ok' => true, 'skipped' => 'bot']);
        }

        // Referrer parsing
        $referrerDomain = null;
        $referrerUrl    = $data['referrer_url'] ?? $request->headers->get('referer');
        if ($referrerUrl) {
            $host = parse_url($referrerUrl, PHP_URL_HOST);
            if ($host) {
                $referrerDomain = preg_replace('/^www\./', '', strtolower($host));
                // Drop self-referrals — only count external sources
                $tenantHost = strtolower($request->getHost());
                if ($referrerDomain === preg_replace('/^www\./', '', $tenantHost)) {
                    $referrerDomain = null;
                    $referrerUrl    = null;
                }
            }
        }

        TenantFunnelEvent::create([
            'tenant_id'       => $tenant->id,
            'session_id'      => $sessionId,
            'event_type'      => $data['event_type'],
            'path'            => $data['path'] ?? null,
            'referrer_domain' => $referrerDomain,
            'referrer_url'    => $referrerUrl ? mb_substr($referrerUrl, 0, 2048) : null,
            'utm_source'      => $data['utm_source']   ?? null,
            'utm_medium'      => $data['utm_medium']   ?? null,
            'utm_campaign'    => $data['utm_campaign'] ?? null,
            'device'          => $device,
            'is_new_session'  => $isNew,
            'created_at'      => now(),
        ]);

        return response()->json(['ok' => true])->withCookie(
            Cookie::make('fnl_sid', $sessionId, 60 * 24 * 90, '/', null, true, true, false, 'lax')
        );
    }

    /**
     * Look up or create the anonymous session ID.
     * Returns [sessionId, isNewSession].
     */
    protected function resolveSession(Request $request): array
    {
        $existing = $request->cookie('fnl_sid');
        if ($existing && preg_match('/^[a-zA-Z0-9]{32,64}$/', $existing)) {
            return [$existing, false];
        }
        return [(string) Str::random(40), true];
    }

    /**
     * Coarse device classification. Just enough for the reports
     * mobile/desktop/tablet split — not for fingerprinting.
     */
    protected function deviceFromUserAgent(string $ua): string
    {
        $ua = strtolower($ua);
        if ($ua === '') return 'unknown';

        if (preg_match('/bot|crawl|spider|slurp|bingpreview|facebookexternalhit/', $ua)) {
            return 'bot';
        }
        if (str_contains($ua, 'ipad') || str_contains($ua, 'tablet') || (str_contains($ua, 'android') && ! str_contains($ua, 'mobile'))) {
            return 'tablet';
        }
        if (str_contains($ua, 'iphone') || str_contains($ua, 'mobile') || str_contains($ua, 'android')) {
            return 'mobile';
        }
        return 'desktop';
    }
}
