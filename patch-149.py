#!/usr/bin/env python3
"""
Patch 149 — Funnel event tracking foundation.

The first of 3 patches that build out the Traffic reports tab. This
patch is foundation-only — nothing visible yet. It adds:

  - tenant_funnel_events table  (one row per tracked event)
  - TenantFunnelEvent model
  - POST /funnel/track endpoint (tenant-scoped via subdomain)
  - Funnel session cookie helper (anonymous 90-day session id)

The endpoint accepts 4 event types via POST:
  - page_view
  - booking_page_viewed
  - booking_started
  - booking_completed

It captures referrer, UTM params, user-agent (parsed to device type
only — no fingerprinting), and an anonymous session ID. No personal
data; no IP storage; no fingerprinting.

A daily prune (added in patch 151) will remove events older than 90 days.

Idempotent.
"""

import argparse
import pathlib
import sys


# ============================================================
# NEW FILES
# ============================================================

MIGRATION = r'''<?php
// MARKER-PATCH-149

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tenant_funnel_events', function (Blueprint $table) {
            $table->id();

            $table->uuid('tenant_id');
            $table->foreign('tenant_id')->references('id')->on('tenants')->onDelete('cascade');

            // Anonymous session id (random 32-char string in a cookie).
            // Lets us join multiple events into a session without identifying users.
            $table->string('session_id', 64)->index();

            // page_view | booking_page_viewed | booking_started | booking_completed
            $table->string('event_type', 32);

            // The page where the event happened
            $table->string('path', 255)->nullable();

            // Where they came from (Referer header, before they hit us)
            $table->string('referrer_domain', 191)->nullable();
            $table->string('referrer_url', 2048)->nullable();

            // UTM params for campaign attribution
            $table->string('utm_source',   100)->nullable();
            $table->string('utm_medium',   100)->nullable();
            $table->string('utm_campaign', 191)->nullable();

            // Coarse device bucket (mobile/desktop/tablet/bot) — derived from UA, no fingerprint
            $table->string('device', 12)->nullable();

            // New vs returning (based on session cookie age)
            $table->boolean('is_new_session')->default(true);

            $table->timestamp('created_at')->useCurrent();

            // Composite index for the most common query pattern (tenant + time window)
            $table->index(['tenant_id', 'created_at']);
            $table->index(['tenant_id', 'event_type', 'created_at'], 'tfe_tenant_event_time');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tenant_funnel_events');
    }
};
'''


MODEL = r'''<?php
// MARKER-PATCH-149

namespace App\Models\Tenant;

use App\Models\Tenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * TenantFunnelEvent — one row per tracked customer-facing event.
 *
 * Anonymous by design. We track sessions, not people. No IP storage,
 * no fingerprinting, no third-party trackers.
 *
 * Retention: 90 days, pruned daily (patch 151).
 */
class TenantFunnelEvent extends Model
{
    protected $table = 'tenant_funnel_events';

    public $timestamps = false;   // we only have created_at

    protected $fillable = [
        'tenant_id',
        'session_id',
        'event_type',
        'path',
        'referrer_domain',
        'referrer_url',
        'utm_source',
        'utm_medium',
        'utm_campaign',
        'device',
        'is_new_session',
        'created_at',
    ];

    protected $casts = [
        'created_at'     => 'datetime',
        'is_new_session' => 'boolean',
    ];

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public const TYPE_PAGE_VIEW           = 'page_view';
    public const TYPE_BOOKING_PAGE_VIEWED = 'booking_page_viewed';
    public const TYPE_BOOKING_STARTED     = 'booking_started';
    public const TYPE_BOOKING_COMPLETED   = 'booking_completed';

    public const VALID_TYPES = [
        self::TYPE_PAGE_VIEW,
        self::TYPE_BOOKING_PAGE_VIEWED,
        self::TYPE_BOOKING_STARTED,
        self::TYPE_BOOKING_COMPLETED,
    ];
}
'''


CONTROLLER = r'''<?php
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
    public function store(string $subdomain, Request $request)
    {
        // Rate limit: 60 events / minute per IP per tenant. Generous
        // enough for legitimate use (page_view per nav, booking events
        // per step) but blocks abusive scripted floods.
        $key = 'funnel-track:' . $request->ip() . ':' . $subdomain;
        if (RateLimiter::tooManyAttempts($key, 60)) {
            return response()->json(['ok' => false, 'reason' => 'rate_limited'], 429);
        }
        RateLimiter::hit($key, 60);

        $tenant = tenant();
        if (! $tenant) {
            return response()->json(['ok' => false, 'reason' => 'no_tenant'], 404);
        }

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
'''


# ============================================================
# EDITS — register routes
# ============================================================
#
# Route goes inside the public tenant-scoped group (resolves tenant by
# subdomain) but BEFORE the auth-required admin section. Anchor on the
# existing /book route which is the closest sibling.

OLD_ROUTES = """    Route::get('/book',                  [TenantControllers\\BookingController::class, 'index'])->name('tenant.booking');"""

NEW_ROUTES = """    Route::get('/book',                  [TenantControllers\\BookingController::class, 'index'])->name('tenant.booking');
    // MARKER-PATCH-149 — anonymous funnel event tracking from public pages
    Route::post('/funnel/track',         [TenantControllers\\FunnelTrackController::class, 'store'])->name('tenant.funnel.track');"""


# CSRF exemption — the tracking endpoint is hit from public JS, no CSRF token available.
# The endpoint is rate-limited and only writes to a single append-only table; the
# security trade-off is fine.
OLD_CSRF = """            'webhooks/ses-bounce',  // MARKER-PATCH-146"""

NEW_CSRF = """            'webhooks/ses-bounce',  // MARKER-PATCH-146
            'funnel/track',  // MARKER-PATCH-149
            '*/funnel/track',  // MARKER-PATCH-149 — match all subdomains"""


NEW_FILES = {
    'database/migrations/2026_05_26_000001_create_tenant_funnel_events_table.php': MIGRATION,
    'app/Models/Tenant/TenantFunnelEvent.php':                    MODEL,
    'app/Http/Controllers/Tenant/FunnelTrackController.php':      CONTROLLER,
}

EDITS = [
    ('routes/web.php',    OLD_ROUTES, NEW_ROUTES, 'routes: funnel track endpoint', 'MARKER-PATCH-149 — anonymous funnel event tracking'),
    ('bootstrap/app.php', OLD_CSRF,   NEW_CSRF,   'CSRF exempt funnel/track',      'funnel/track\',  // MARKER-PATCH-149'),
]


def main():
    ap = argparse.ArgumentParser(); ap.add_argument('root'); ap.add_argument('--apply', action='store_true')
    a = ap.parse_args()
    root = pathlib.Path(a.root)
    if not (root / 'routes' / 'web.php').exists():
        print('ERROR: not an intake repo', file=sys.stderr); sys.exit(2)
    mode = 'APPLY' if a.apply else 'DRY-RUN'
    print(f'=== patch-149 [{mode}] target={root} ===\n')

    for rel, content in NEW_FILES.items():
        p = root / rel
        if p.exists() and p.read_text() == content:
            print(f'  unchanged: {rel}'); continue
        if a.apply:
            p.parent.mkdir(parents=True, exist_ok=True)
            p.write_text(content)
        print(f'  {"written" if a.apply else "would_write"}: {rel}')

    for rel, old, new, label, marker in EDITS:
        p = root / rel
        t = p.read_text()
        if marker in t:
            print(f'  already_applied: {label}'); continue
        if old not in t:
            print(f'  ERROR: anchor missing for {label}', file=sys.stderr); sys.exit(2)
        if t.count(old) > 1:
            print(f'  ERROR: anchor not unique for {label}', file=sys.stderr); sys.exit(2)
        if a.apply:
            p.write_text(t.replace(old, new, 1))
        print(f'  {"applied" if a.apply else "would_apply"}: {label}')

    if a.apply:
        print('\nDeploy steps:')
        print('  php artisan migrate --force')
        print('  php artisan optimize:clear')
        print('  systemctl stop php8.3-fpm && sleep 2 && systemctl start php8.3-fpm')
        print('\nNothing visible yet — patch 150 wires the tracking JS into pages.')
    else:
        print('\n(dry-run — no files written.)')


if __name__ == '__main__':
    main()
