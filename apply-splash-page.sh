#!/bin/bash
# apply-splash-page.sh
#
# MARKER-SPLASH — an optional splash page shown before a shop's homepage,
# built from the normal page-builder sections. Matches the approved
# prototype (intake-splash-page-prototype.html).
#
# DECISIONS JOSH MADE, both honoured here:
#   - "every page load" IS an available frequency, even though it is the
#     setting most likely to annoy a shop's regulars. The UI says so plainly
#     rather than hiding the option.
#   - a DIRECT LINK IS NEVER INTERRUPTED. Only home() intercepts; page($slug)
#     is untouched, so someone arriving at /shop from Instagram goes straight
#     there and no cookie is set — if they later browse to the homepage they
#     still get the splash once.
#
# TWO MODES, and the difference is not cosmetic:
#   overlay (default) — the homepage renders normally and the splash draws
#     over it. The real HTML is served, so crawlers index the shop's content,
#     the URL never changes, and with JS off the visitor just sees the
#     homepage. Weaker as a hard gate.
#   page — redirects to the splash page's own slug. A true gate, but Google
#     then indexes the splash instead of the shop's content. The settings UI
#     warns about exactly that; there is deliberately NO crawler user-agent
#     bypass, because quietly serving Google something different from what
#     visitors get is a trick that ages badly.
#
# SCOPE CUTS for v1, stated rather than hidden: no scheduling window, no
# per-campaign targeting, no A/B. The splash page is a normal page, so a
# shop can already link a campaign straight at its slug.
set -e

MARKER="MARKER-SPLASH"
CTRL="app/Http/Controllers/Tenant/PublicController.php"

[ -f "$CTRL" ] || { echo "ERROR: run from the repo root"; exit 1; }
if grep -q "$MARKER" "$CTRL" 2>/dev/null; then
  echo "ok: already applied ($MARKER present) — no-op"
  exit 0
fi

# ---------------------------------------------------------------
# 1. Schema
# ---------------------------------------------------------------
cat > database/migrations/2026_08_14_120000_add_is_splash_to_tenant_pages.php <<'EOF'
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * MARKER-SPLASH — marks the one page a tenant uses as its splash.
 *
 * Deliberately a flag on pages rather than a separate table: a splash IS a
 * page — same sections, same builder, same revisions, and a shop can link a
 * campaign straight at its slug. The settings that govern WHEN it appears
 * live in tenants.settings alongside the other per-shop toggles.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenant_pages', function (Blueprint $table) {
            $table->boolean('is_splash')->default(false)->after('is_home');
            $table->index(['tenant_id', 'is_splash']);
        });
    }

    public function down(): void
    {
        Schema::table('tenant_pages', function (Blueprint $table) {
            $table->dropIndex(['tenant_id', 'is_splash']);
            $table->dropColumn('is_splash');
        });
    }
};
EOF
echo "ok: migration"

python3 - <<'PY'
import io
p = 'app/Models/Tenant/TenantPage.php'
src = io.open(p, encoding='utf-8').read()
a = "protected $fillable = ['tenant_id','title','slug','meta_title','meta_description','is_home','is_published','is_in_nav','nav_order'];"
assert src.count(a) == 1, 'fillable'
src = src.replace(a, "protected $fillable = ['tenant_id','title','slug','meta_title','meta_description','is_home','is_splash','is_published','is_in_nav','nav_order']; // MARKER-SPLASH", 1)
b = "protected $casts    = ['is_home' => 'boolean', 'is_published' => 'boolean', 'is_in_nav' => 'boolean'];"
assert src.count(b) == 1, 'casts'
src = src.replace(b, "protected $casts    = ['is_home' => 'boolean', 'is_splash' => 'boolean', 'is_published' => 'boolean', 'is_in_nav' => 'boolean']; // MARKER-SPLASH", 1)
io.open(p, 'w', encoding='utf-8').write(src)
print('ok: TenantPage')
PY

# ---------------------------------------------------------------
# 2. Settings resolver
# ---------------------------------------------------------------
cat > app/Support/SplashSettings.php <<'EOF'
<?php

namespace App\Support;

use App\Models\Tenant;
use App\Models\Tenant\TenantPage;
use Illuminate\Http\Request;

/**
 * MARKER-SPLASH — one place that answers "should this visitor see the
 * splash, and which page is it".
 */
class SplashSettings
{
    public const COOKIE = 'intake_splash';

    /** Normalized settings, clamped — a bad stored value can never gate a site shut. */
    public static function config(Tenant $tenant): array
    {
        $s = (array) ($tenant->settings ?? []);

        $mode = $s['splash_mode'] ?? 'overlay';
        $freq = (string) ($s['splash_frequency'] ?? 'session');
        $style = $s['splash_style'] ?? 'full';

        return [
            'enabled'   => (bool) ($s['splash_enabled'] ?? false),
            'mode'      => in_array($mode, ['overlay', 'page'], true) ? $mode : 'overlay',
            'frequency' => in_array($freq, ['session', '7', '30', 'always'], true) ? $freq : 'session',
            'style'     => in_array($style, ['full', 'sheet'], true) ? $style : 'full',
        ];
    }

    /** The published page flagged as the splash, if there is one. */
    public static function page(Tenant $tenant): ?TenantPage
    {
        return TenantPage::where('tenant_id', $tenant->id)
            ->where('is_splash', true)
            ->where('is_published', true)
            ->first();
    }

    /**
     * Whether THIS request should be shown the splash.
     *
     * Only ever called from the homepage route: a deep link is never
     * interrupted, by Josh's decision, so /shop and friends do not consult
     * this at all.
     */
    public static function shouldShow(Request $request, array $cfg): bool
    {
        if (! $cfg['enabled']) {
            return false;
        }
        if ($cfg['frequency'] === 'always') {
            return true; // no cookie is ever written in this mode
        }

        return ! $request->cookie(self::COOKIE);
    }

    /** Cookie lifetime in minutes; 0 means a session cookie. */
    public static function cookieMinutes(array $cfg): int
    {
        return match ($cfg['frequency']) {
            '7'  => 60 * 24 * 7,
            '30' => 60 * 24 * 30,
            default => 0,
        };
    }
}
EOF
echo "ok: SplashSettings"

# ---------------------------------------------------------------
# 3. Serving
# ---------------------------------------------------------------
python3 - <<'PY'
import io
p = 'app/Http/Controllers/Tenant/PublicController.php'
src = io.open(p, encoding='utf-8').read()

a = """        if (! $page) {
            return view('public.coming-soon');
        }

        return $this->renderPage($page);
    }"""
assert src.count(a) == 1, 'home tail'
src = src.replace(a, """        if (! $page) {
            return view('public.coming-soon');
        }

        // MARKER-SPLASH -- the homepage is the ONLY route that consults the
        // splash. page($slug) deliberately does not: a visitor arriving at
        // /shop from a campaign is never interrupted.
        $splashCfg  = \\App\\Support\\SplashSettings::config($tenant);
        $splashPage = \\App\\Support\\SplashSettings::shouldShow(request(), $splashCfg)
            ? \\App\\Support\\SplashSettings::page($tenant)
            : null;

        if ($splashPage && $splashCfg['mode'] === 'page') {
            // A true gate: the homepage is not served at all until they
            // click through. The shop was warned in settings that this is
            // what search engines will index.
            return redirect('/' . $splashPage->slug);
        }

        return $this->renderPage($page, $splashPage, $splashCfg);
    }""", 1)

# MARKER-SPLASH -- page mode would otherwise be a TRAP: the splash is served
# by page() as an ordinary page, so nothing ever writes the cookie, and the
# visitor who clicks through to / is redirected straight back. Seeing the
# splash has to count as having seen it.
pm = """        $page = TenantPage::where('tenant_id', $tenant->id)
            ->where('slug', $slug)
            ->where('is_published', true)
            ->firstOrFail();

        return $this->renderPage($page);"""
assert src.count(pm) == 1, 'page() body'
src = src.replace(pm, """        $page = TenantPage::where('tenant_id', $tenant->id)
            ->where('slug', $slug)
            ->where('is_published', true)
            ->firstOrFail();

        // MARKER-SPLASH -- viewing the splash directly counts as seeing it, so
        // clicking through to the homepage is not bounced straight back here.
        if ($page->is_splash) {
            $cfg = \\App\\Support\\SplashSettings::config($tenant);
            if ($cfg['frequency'] !== 'always') {
                return response($this->renderPage($page))->cookie(
                    \\App\\Support\\SplashSettings::COOKIE, '1',
                    \\App\\Support\\SplashSettings::cookieMinutes($cfg),
                    '/', null, request()->isSecure(), false, false, 'lax'
                );
            }
        }

        return $this->renderPage($page);""", 1)

b = """    private function renderPage(TenantPage $page)
    {"""
assert src.count(b) == 1, 'renderPage signature'
src = src.replace(b, """    private function renderPage(TenantPage $page, ?TenantPage $splashPage = null, array $splashCfg = [])
    {""", 1)

c = """        return view('public.page', compact('page', 'sections', 'navItems', 'catalog'));"""
assert src.count(c) == 1, 'renderPage return'
src = src.replace(c, """        // MARKER-SPLASH -- overlay mode: the homepage renders exactly as it
        // always did and the splash draws on top, so the real HTML is still
        // served to crawlers and to anyone with JS off.
        $splashSections = null;
        if ($splashPage) {
            $splashSections = $splashPage->sections()->where('is_visible', true)->get();
            $splashSections = \\App\\Models\\Tenant\\TenantPageSection::withInheritedChrome(
                $splashSections, $splashPage->tenant_id, $splashPage->id
            );
        }

        return view('public.page', compact(
            'page', 'sections', 'navItems', 'catalog',
            'splashPage', 'splashSections', 'splashCfg'
        ));""", 1)

io.open(p, 'w', encoding='utf-8').write(src)
print('ok: PublicController')
PY

# ---------------------------------------------------------------
# 4. The overlay itself
# ---------------------------------------------------------------
cat > resources/views/public/_splash-overlay.blade.php <<'EOF'
{{-- MARKER-SPLASH — overlay rendering of the splash page's own sections.

     The homepage is already in the DOM underneath this; that is the whole
     point of overlay mode. Everything here is inert to crawlers (they do not
     run the dismiss script and they have already read the real page).

     Accessibility: the dismiss control is a real <button>, focus moves to it
     on load, Esc closes, and the overlay is aria-modal so a screen reader
     does not wander into the page behind it. --}}
@php
  $spStyle = $splashCfg['style'] ?? 'full';
  $spFreq  = $splashCfg['frequency'] ?? 'session';
@endphp

<div id="p-splash" class="p-splash p-splash--{{ $spStyle }}" role="dialog" aria-modal="true"
     aria-label="Welcome">
  <div class="p-splash-inner">
    @foreach($splashSections as $section)
      @php $partial = 'public.sections._' . $section->section_type; @endphp
      @if(view()->exists($partial))
        @php
          $sc = $section->content ?? [];
          $sc['bg_color'] = \App\Support\DesignTokens::sectionBg(
              $sc['bg_color'] ?? null, $section->section_type, $dt
          );
        @endphp
        @include($partial, [
          'c'        => $sc,
          'section'  => $section,
          'navItems' => $navItems,
          'catalog'  => $catalog,
          'tenant'   => $currentTenant,
        ])
      @endif
    @endforeach

    <div class="p-splash-actions">
      <button type="button" id="p-splash-enter" class="p-btn p-btn--primary">Enter site</button>
    </div>
  </div>
</div>

<style>
  .p-splash{
    position:fixed;inset:0;z-index:9000;overflow-y:auto;
    background:var(--p-bg,#111);
    display:flex;flex-direction:column;
  }
  .p-splash--full .p-splash-inner{margin:auto;width:100%}
  .p-splash--sheet{background:rgba(0,0,0,.55);justify-content:flex-end}
  .p-splash--sheet .p-splash-inner{
    background:var(--p-bg,#111);border-radius:18px 18px 0 0;
    max-height:86vh;overflow-y:auto;box-shadow:0 -12px 40px rgba(0,0,0,.35)
  }
  .p-splash-actions{display:flex;justify-content:center;padding:22px 20px 30px}
  .p-splash-actions .p-btn{min-width:180px;justify-content:center}
  /* No JS, no overlay: the homepage underneath is the honest fallback. */
  .p-splash{display:none}
</style>
<script>
(function () {
  var el = document.getElementById('p-splash');
  if (!el) return;
  el.style.display = 'flex';                 // only ever shown with JS available
  document.body.style.overflow = 'hidden';

  var FREQ = @json($spFreq);

  function remember() {
    if (FREQ === 'always') return;           // deliberately never remembered
    var days = FREQ === '7' ? 7 : (FREQ === '30' ? 30 : 0);
    var bits = '{{ \App\Support\SplashSettings::COOKIE }}=1; path=/; samesite=lax';
    if (days) bits += '; max-age=' + (days * 86400);
    if (location.protocol === 'https:') bits += '; secure';
    document.cookie = bits;
  }

  function dismiss() {
    remember();
    el.style.display = 'none';
    document.body.style.overflow = '';
    var h = document.querySelector('h1, [role=main], main');
    if (h && h.focus) { h.setAttribute('tabindex', '-1'); h.focus(); }
  }

  document.getElementById('p-splash-enter').addEventListener('click', dismiss);
  document.addEventListener('keydown', function (e) { if (e.key === 'Escape') dismiss(); });

  // Any link inside the splash (Book now, Shop) should also count as entering,
  // or the visitor gets the splash again the moment they arrive.
  el.querySelectorAll('a[href]').forEach(function (a) { a.addEventListener('click', remember); });

  document.getElementById('p-splash-enter').focus();
})();
</script>
EOF

python3 - <<'PY'
import io
p = 'resources/views/public/layout.blade.php'
src = io.open(p, encoding='utf-8').read()
a = """{{-- Page sections --}}
@foreach($sections as $section)"""
assert src.count(a) == 1, 'sections loop'
src = src.replace(a, """{{-- MARKER-SPLASH — drawn OVER the homepage, which is fully rendered below.
     Included before the sections so it exists even if a section throws. --}}
@if(!empty($splashPage) && !empty($splashSections) && count($splashSections))
  @include('public._splash-overlay')
@endif

""" + a, 1)
io.open(p, 'w', encoding='utf-8').write(src)
print('ok: layout include')
PY

# ---------------------------------------------------------------
# 5. Admin: settings panel + save
# ---------------------------------------------------------------
python3 - <<'PY'
import io

# 5a. controller: pass config + handle save
p = 'app/Http/Controllers/Tenant/PageBuilderController.php'
src = io.open(p, encoding='utf-8').read()

a = """        return view('tenant.pages.index', compact('pages'));"""
assert src.count(a) == 1, 'index return'
src = src.replace(a, """        // MARKER-SPLASH
        $splashCfg = \\App\\Support\\SplashSettings::config($tenant);

        return view('tenant.pages.index', compact('pages', 'splashCfg'));""", 1)

b = """    private function editPage($tenant, string $id)"""
assert src.count(b) == 1, 'editPage anchor'
src = src.replace(b, """    /**
     * MARKER-SPLASH — save the splash settings and which page serves as it.
     * Marking a page as the splash clears the flag from every other page and
     * pulls it out of the nav: a page that interrupts visitors should not
     * also sit in the menu.
     */
    public function saveSplash(Request $request)
    {
        $tenant = tenant();

        $data = $request->validate([
            'splash_enabled'   => 'nullable|boolean',
            'splash_page_id'   => 'nullable|string',
            'splash_mode'      => 'required|in:overlay,page',
            'splash_frequency' => 'required|in:session,7,30,always',
            'splash_style'     => 'required|in:full,sheet',
        ]);

        $pageId = $data['splash_page_id'] ?? null;

        if ($pageId) {
            $page = TenantPage::where('tenant_id', $tenant->id)->where('id', $pageId)->first();
            if (! $page) {
                return back()->with('flash_error', 'That page no longer exists.');
            }
            if ($page->is_home) {
                return back()->with('flash_error', 'Your homepage cannot also be the splash — the splash is what appears before it.');
            }
        }

        TenantPage::where('tenant_id', $tenant->id)->where('is_splash', true)
            ->update(['is_splash' => false]);

        if ($pageId) {
            TenantPage::where('tenant_id', $tenant->id)->where('id', $pageId)
                ->update(['is_splash' => true, 'is_in_nav' => false]);
        }

        $settings = (array) ($tenant->settings ?? []);
        $settings['splash_enabled']   = (bool) ($data['splash_enabled'] ?? false);
        $settings['splash_mode']      = $data['splash_mode'];
        $settings['splash_frequency'] = $data['splash_frequency'];
        $settings['splash_style']     = $data['splash_style'];
        $tenant->settings = $settings;
        $tenant->save();

        return back()->with('flash', 'Splash settings saved.');
    }

    private function editPage($tenant, string $id)""", 1)

io.open(p, 'w', encoding='utf-8').write(src)
print('ok: PageBuilderController::saveSplash')

# 5b. route
p2 = 'routes/web.php'
s2 = io.open(p2, encoding='utf-8').read()
c = """            Route::post('/pages/brand-kit',     [TenantControllers\\PageBuilderController::class, 'saveBrandKit'])->name('pages.brand-kit.save'); // MARKER-PATCH-302"""
assert s2.count(c) == 1, 'brand-kit route'
s2 = s2.replace(c, c + """
            Route::post('/pages/splash',        [TenantControllers\\PageBuilderController::class, 'saveSplash'])->name('pages.splash.save'); // MARKER-SPLASH""", 1)
io.open(p2, 'w', encoding='utf-8').write(s2)
print('ok: route')

# 5c. the panel
p3 = 'resources/views/tenant/pages/index.blade.php'
s3 = io.open(p3, encoding='utf-8').read()
d = """  </table>
</div>

@endsection"""
assert s3.count(d) == 1, 'index tail'
s3 = s3.replace(d, """  </table>
</div>

{{-- MARKER-SPLASH --}}
@php
  $splashPageId = optional($pages->firstWhere('is_splash', true))->id;
@endphp
<div class="ia-card" style="margin-top:22px;max-width:760px">
  <div class="ia-card-head">
    <div class="ia-card-title">Splash page</div>
  </div>
  <div style="padding:16px">
    <p style="font-size:12.5px;color:var(--ia-text-dim);margin:0 0 16px;line-height:1.6">
      Shows before your homepage. Build it from sections like any other page.
      Visitors arriving on a direct link &mdash; a shop or booking URL you shared &mdash;
      are never interrupted.
    </p>

    <form method="POST" action="{{ route('tenant.pages.splash.save') }}">
      @csrf

      <label style="display:flex;align-items:center;gap:9px;font-size:13px;margin-bottom:16px">
        <input type="hidden" name="splash_enabled" value="0">
        <input type="checkbox" name="splash_enabled" value="1" @checked($splashCfg['enabled'])>
        Enable splash page
      </label>

      <div style="display:grid;grid-template-columns:150px 1fr;gap:12px;align-items:start;margin-bottom:14px">
        <label style="font-size:12.5px;font-weight:600;padding-top:9px">Which page</label>
        <div>
          <select name="splash_page_id" class="ia-input" style="max-width:320px">
            <option value="">&mdash; none selected &mdash;</option>
            @foreach($pages->where('is_home', false) as $p)
              <option value="{{ $p->id }}" @selected($splashPageId === $p->id)>
                {{ $p->title }}{{ $p->is_published ? '' : ' (draft)' }}
              </option>
            @endforeach
          </select>
          <div style="font-size:11.5px;color:var(--ia-text-dim);margin-top:6px;line-height:1.5">
            It must be published to appear, and it will be removed from your navigation.
          </div>
        </div>
      </div>

      <div style="display:grid;grid-template-columns:150px 1fr;gap:12px;align-items:start;margin-bottom:14px">
        <label style="font-size:12.5px;font-weight:600;padding-top:9px">How it appears</label>
        <div>
          <select name="splash_mode" class="ia-input" style="max-width:320px">
            <option value="overlay" @selected($splashCfg['mode'] === 'overlay')>Overlay &mdash; on top of your homepage</option>
            <option value="page" @selected($splashCfg['mode'] === 'page')>Separate page &mdash; before your homepage</option>
          </select>
          <div style="font-size:11.5px;color:var(--ia-text-dim);margin-top:6px;line-height:1.55">
            <strong>Overlay</strong> keeps your homepage in place underneath, so Google still
            reads your real content and the page works without JavaScript.
            <strong>Separate page</strong> is a firmer gate, but search engines will index the
            splash instead of your homepage &mdash; which can cost you traffic.
          </div>
        </div>
      </div>

      <div style="display:grid;grid-template-columns:150px 1fr;gap:12px;align-items:start;margin-bottom:14px">
        <label style="font-size:12.5px;font-weight:600;padding-top:9px">Show it</label>
        <div>
          <select name="splash_frequency" class="ia-input" style="max-width:320px">
            <option value="session" @selected($splashCfg['frequency'] === 'session')>Once per visit</option>
            <option value="7"       @selected($splashCfg['frequency'] === '7')>Once every 7 days</option>
            <option value="30"      @selected($splashCfg['frequency'] === '30')>Once every 30 days</option>
            <option value="always"  @selected($splashCfg['frequency'] === 'always')>Every page load</option>
          </select>
          <div style="font-size:11.5px;color:var(--ia-text-dim);margin-top:6px;line-height:1.5">
            &ldquo;Every page load&rdquo; shows it to the same person again and again, including your regulars.
          </div>
        </div>
      </div>

      <div style="display:grid;grid-template-columns:150px 1fr;gap:12px;align-items:start;margin-bottom:18px">
        <label style="font-size:12.5px;font-weight:600;padding-top:9px">Style</label>
        <select name="splash_style" class="ia-input" style="max-width:320px">
          <option value="full"  @selected($splashCfg['style'] === 'full')>Full screen</option>
          <option value="sheet" @selected($splashCfg['style'] === 'sheet')>Bottom sheet</option>
        </select>
      </div>

      <button type="submit" class="ia-btn ia-btn--primary">Save splash settings</button>
    </form>
  </div>
</div>

@endsection""", 1)
io.open(p3, 'w', encoding='utf-8').write(s3)
print('ok: settings panel')
PY

echo ""
echo "== splash page applied =="
echo "Post-deploy: migrations run in deploy; php artisan optimize:clear"
