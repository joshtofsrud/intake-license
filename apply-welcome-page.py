#!/usr/bin/env python3
"""Welcome page — a site-wide holding page.

Publishing is per page. A shop that isn't open yet needs the opposite:
everything hidden behind one holding page. There IS a coming-soon view
today, but it only appears when the HOME page happens to be unpublished,
its copy is hardcoded, and every other page still renders — so a
half-built site leaks.

This adds a real switch:
  * Tenant setting, not a page — survives page deletion and can't be
    accidentally unpublished.
  * Middleware on the public routes short-circuits to the welcome view.
  * Signed-in staff ALWAYS bypass it. Otherwise you'd have to switch it
    off to check your own work, which is exactly when people forget to
    switch it back on.
  * An allow-list, defaulting to /book and /account: a shop that isn't
    "open" still wants bookings and existing customers.
  * Welcome beats Splash when both are on, and the splash card says so.
  * The pages list stops claiming pages are "Live" while nobody can
    reach them.
Run from repo root: python3 apply-welcome-page.py
"""
import os, sys

ROOT = os.getcwd()
def read(p):
    with open(os.path.join(ROOT, p)) as f: return f.read()
def write(p, s):
    os.makedirs(os.path.dirname(os.path.join(ROOT, p)), exist_ok=True)
    with open(os.path.join(ROOT, p), 'w') as f: f.write(s)
def sub(p, old, new, label):
    s = read(p)
    if new == '':
        if old not in s:
            print(f"SKIP (already applied): {label}"); return
    elif new in s:
        print(f"SKIP (already applied): {label}"); return
    if old not in s:
        print(f"FAIL: anchor not found for {label} in {p}"); sys.exit(1)
    write(p, s.replace(old, new, 1))
    print(f"OK: {label}")
def newfile(p, content, label):
    if os.path.exists(os.path.join(ROOT, p)):
        print(f"SKIP (exists): {label}"); return
    write(p, content)
    print(f"OK: {label}")

# ============================================================
# 1) Settings accessor — one place that decides
# ============================================================
newfile('app/Support/WelcomePage.php', """<?php

namespace App\\Support;

use App\\Models\\Tenant;

/**
 * MARKER-WELCOME — the site-wide holding page.
 *
 * Distinct from Splash: a splash interrupts a visitor BEFORE a page they
 * are then allowed to see. Welcome REPLACES the site. When both are on,
 * welcome wins — there is no point splashing someone who can't reach the
 * page behind it.
 */
class WelcomePage
{
    /** Paths that stay reachable while the welcome page is up. */
    public const ALLOWABLE = [
        'book'    => ['label' => 'Booking page',     'path' => '/book'],
        'account' => ['label' => 'Customer account', 'path' => '/account'],
        'rentals' => ['label' => 'Rentals',          'path' => '/rentals'],
        'contact' => ['label' => 'Contact',          'path' => '/contact'],
    ];

    public static function settings(?Tenant $tenant): array
    {
        $s = (array) ($tenant->settings ?? []);

        return [
            'enabled'   => (bool) ($s['welcome_enabled'] ?? false),
            'headline'  => trim((string) ($s['welcome_headline'] ?? '')) ?: 'Something good is coming.',
            'message'   => trim((string) ($s['welcome_message'] ?? '')),
            'cta_label' => trim((string) ($s['welcome_cta_label'] ?? '')),
            'cta_url'   => trim((string) ($s['welcome_cta_url'] ?? '')),
            // Default reflects what a not-yet-open shop still wants working.
            'allow'     => is_array($s['welcome_allow'] ?? null)
                            ? $s['welcome_allow']
                            : ['book', 'account'],
        ];
    }

    public static function enabled(?Tenant $tenant): bool
    {
        return $tenant && self::settings($tenant)['enabled'];
    }

    /** Is this request path allowed through? */
    public static function allows(?Tenant $tenant, string $path): bool
    {
        $path = '/' . ltrim($path, '/');
        foreach (self::settings($tenant)['allow'] as $key) {
            $allowed = self::ALLOWABLE[$key]['path'] ?? null;
            if (! $allowed) continue;
            if ($path === $allowed || str_starts_with($path, $allowed . '/')) {
                return true;
            }
        }
        return false;
    }
}
""", "WelcomePage support")

# ============================================================
# 2) Middleware
# ============================================================
newfile('app/Http/Middleware/ShowWelcomePage.php', """<?php

namespace App\\Http\\Middleware;

use App\\Support\\WelcomePage;
use Closure;
use Illuminate\\Http\\Request;
use Illuminate\\Support\\Facades\\Auth;

/**
 * MARKER-WELCOME — stand in front of the public site while a tenant is
 * still getting ready.
 *
 * Deliberate exemptions:
 *   - Signed-in staff always see the real site. Having to switch the
 *     welcome page off to check your own work is how it ends up left off.
 *   - Non-GET requests pass: a form post from an allowed page (booking,
 *     account login) must still complete.
 *   - The allow-list, so bookings and existing customers keep working.
 */
class ShowWelcomePage
{
    public function handle(Request $request, Closure $next)
    {
        $tenant = app()->bound('tenant') ? app('tenant') : null;

        if (! WelcomePage::enabled($tenant))              return $next($request);
        if (Auth::guard('tenant')->check())               return $next($request);
        if (! $request->isMethod('GET'))                  return $next($request);
        if ($request->ajax() || $request->expectsJson())  return $next($request);

        $path = '/' . ltrim($request->path(), '/');
        if ($path === '/welcome-preview')                 return $next($request);
        if (WelcomePage::allows($tenant, $path))          return $next($request);

        // 503 rather than 200: the site exists and is coming back, and we
        // would rather not have a holding page indexed as the real thing.
        return response()
            ->view('public.welcome', ['w' => WelcomePage::settings($tenant)], 503)
            ->header('Retry-After', '86400');
    }
}
""", "ShowWelcomePage middleware")

sub('routes/web.php',
    """Route::middleware(['App\\Http\\Middleware\\ResolveTenant'])
    ->group($tenantRoutes);""",
    """Route::middleware([
        'App\\Http\\Middleware\\ResolveTenant',
        // MARKER-WELCOME — after tenant resolution, before anything renders.
        'App\\Http\\Middleware\\ShowWelcomePage',
    ])
    ->group($tenantRoutes);""",
    "routes: middleware")

# ============================================================
# 3) The page itself
# ============================================================
newfile('resources/views/public/welcome.blade.php', """{{-- MARKER-WELCOME — the holding page. Uses the tenant's own logo,
     accent and contact details, so there is nothing to design. --}}
@php
  $t      = $currentTenant ?? tenant();
  $accent = $t->accent_color ?: '#BEF264';
  $loc    = $t->locations()->orderBy('is_default', 'desc')->first();
@endphp
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="robots" content="noindex">
  <title>{{ $t->name }}</title>
  @if($t->favicon_url)<link rel="icon" href="{{ $t->favicon_url }}">@endif
  <style>
    *{box-sizing:border-box;margin:0;padding:0}
    body{font-family:-apple-system,BlinkMacSystemFont,'Inter',sans-serif;
         background:#0d0d0d;color:#f2f2f2;min-height:100vh;display:flex;
         align-items:center;justify-content:center;text-align:center;padding:32px;
         background-image:radial-gradient(circle at 50% 0%,
           color-mix(in srgb, {{ $accent }} 10%, transparent), transparent 60%)}
    .w{max-width:460px}
    .logo{height:52px;margin:0 auto 26px;display:block}
    .mark{width:56px;height:56px;border-radius:14px;margin:0 auto 26px;
          display:flex;align-items:center;justify-content:center;
          background:color-mix(in srgb, {{ $accent }} 16%, transparent);
          color:{{ $accent }};font-size:20px;font-weight:800}
    h1{font-size:clamp(26px,5vw,40px);font-weight:700;letter-spacing:-.02em;line-height:1.15}
    p{font-size:16px;color:rgba(255,255,255,.55);margin:12px auto 0;line-height:1.65}
    .cta{display:inline-block;margin-top:26px;padding:12px 26px;border-radius:9px;
         background:{{ $accent }};color:#0a0a0a;font-size:15px;font-weight:700;text-decoration:none}
    .meta{margin-top:26px;font-size:13px;color:rgba(255,255,255,.35);line-height:1.7}
    .meta a{color:inherit}
  </style>
</head>
<body>
  <div class="w">
    @if($t->logo_url)
      <img class="logo" src="{{ $t->logo_url }}" alt="{{ $t->name }}">
    @else
      <div class="mark">{{ strtoupper(substr($t->name, 0, 2)) }}</div>
    @endif

    <h1>{{ $w['headline'] }}</h1>
    @if($w['message'])<p>{{ $w['message'] }}</p>@endif

    @if($w['cta_label'] && $w['cta_url'])
      <a class="cta" href="{{ $w['cta_url'] }}">{{ $w['cta_label'] }}</a>
    @endif

    <div class="meta">
      {{ $t->name }}
      @if($loc && $loc->phone) · <a href="tel:{{ preg_replace('/[^0-9+]/', '', $loc->phone) }}">{{ $loc->phone }}</a> @endif
      @if($loc && $loc->city) · {{ $loc->city }}@if($loc->state), {{ $loc->state }}@endif @endif
    </div>
  </div>
</body>
</html>
""", "welcome view")

# ============================================================
# 4) Controller — save + preview
# ============================================================
sub('app/Http/Controllers/Tenant/PageBuilderController.php',
    """    /**
     * MARKER-SPLASH-2 — save the pairing table.""",
    """    /**
     * MARKER-WELCOME — the site-wide holding page settings.
     */
    public function saveWelcome(Request $request)
    {
        $tenant = tenant();

        $data = $request->validate([
            'welcome_enabled'   => 'nullable|boolean',
            'welcome_headline'  => 'nullable|string|max:120',
            'welcome_message'   => 'nullable|string|max:400',
            'welcome_cta_label' => 'nullable|string|max:40',
            'welcome_cta_url'   => 'nullable|string|max:255',
            'welcome_allow'     => 'nullable|array',
            'welcome_allow.*'   => 'string|in:' . implode(',', array_keys(\\App\\Support\\WelcomePage::ALLOWABLE)),
        ]);

        $settings = (array) ($tenant->settings ?? []);
        $settings['welcome_enabled']   = (bool) ($data['welcome_enabled'] ?? false);
        $settings['welcome_headline']  = $data['welcome_headline'] ?? null;
        $settings['welcome_message']   = $data['welcome_message'] ?? null;
        $settings['welcome_cta_label'] = $data['welcome_cta_label'] ?? null;
        $settings['welcome_cta_url']   = $data['welcome_cta_url'] ?? null;
        $settings['welcome_allow']     = array_values($data['welcome_allow'] ?? []);
        $tenant->settings = $settings;
        $tenant->save();

        return back()->with('success', $settings['welcome_enabled']
            ? 'Welcome page is on — visitors see it instead of your site. You still see the real site while signed in.'
            : 'Welcome page is off — your published pages are reachable again.');
    }

    /** MARKER-WELCOME — see it as a visitor would, without switching it on. */
    public function previewWelcome()
    {
        $tenant = tenant();
        return view('public.welcome', ['w' => \\App\\Support\\WelcomePage::settings($tenant)]);
    }

    /**
     * MARKER-SPLASH-2 — save the pairing table.""",
    "controller: saveWelcome + preview")

sub('app/Http/Controllers/Tenant/PageBuilderController.php',
    """        // MARKER-SPLASH-2
        $splashEnabled = \\App\\Support\\SplashSettings::enabled($tenant);
        $splashRows = $pages->whereNotNull('splash_page_id')->values();

        return view('tenant.pages.index', compact('pages', 'splashEnabled', 'splashRows'));""",
    """        // MARKER-SPLASH-2
        $splashEnabled = \\App\\Support\\SplashSettings::enabled($tenant);
        $splashRows = $pages->whereNotNull('splash_page_id')->values();

        // MARKER-WELCOME
        $welcome = \\App\\Support\\WelcomePage::settings($tenant);

        return view('tenant.pages.index', compact('pages', 'splashEnabled', 'splashRows', 'welcome'));""",
    "controller: pass welcome")

sub('routes/web.php',
    """            Route::get('/pages/{id}/preview',   [TenantControllers\\PageBuilderController::class, 'preview'])->name('pages.preview'); // MARKER-PATCH-267""",
    """            Route::post('/pages/welcome',        [TenantControllers\\PageBuilderController::class, 'saveWelcome'])->name('pages.welcome'); // MARKER-WELCOME
            Route::get('/pages/welcome/preview', [TenantControllers\\PageBuilderController::class, 'previewWelcome'])->name('pages.welcome.preview');
            Route::get('/pages/{id}/preview',   [TenantControllers\\PageBuilderController::class, 'preview'])->name('pages.preview'); // MARKER-PATCH-267""",
    "routes: welcome endpoints")

print("\\nStep 1 of 2 complete — run apply-welcome-page-ui.py next for the settings card.")
