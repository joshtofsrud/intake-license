#!/bin/bash
# apply-splash-pairings.sh
#
# MARKER-SPLASH-2 — the splash becomes a PAIRING: "when someone visits THIS
# page, show them THAT splash". Replaces the single global splash from
# MARKER-SPLASH, which could not express Josh's case — a homepage splash
# plus a different one on the classes page for an upcoming event.
#
# MODEL CHANGE: v1 flagged one page as "the splash" and only home() looked
# for it. Now the VISITED page points at its splash, so each page answers
# for itself:
#     tenant_pages.splash_page_id  -> which splash appears before this page
#     splash_mode / splash_style / splash_frequency
#     splash_starts_at / splash_ends_at  (optional window)
# is_splash is kept, but now means "this page is used as somebody's splash",
# which is what keeps it out of the nav.
#
# THIS REVERSES ONE OF JOSH'S EARLIER RULES, deliberately and with his
# agreement: v1 never interrupted a direct link because only home()
# intercepted. Attaching a splash to /classes means someone arriving there
# from Instagram DOES see it. The difference is that the shop opted in for
# that page — and the settings UI says so at the point of decision, rather
# than surprising them later.
#
# THREE THINGS THAT HAD TO COME WITH IT, or the feature misbehaves:
#   1. The cookie is now per-splash (intake_splash_<id>). One shared cookie
#      would have meant dismissing the homepage splash silently suppressed
#      the classes one.
#   2. A date window, because "an event coming up" implies it stops on its
#      own. Without it somebody has to remember to switch it off, and
#      nobody does.
#   3. The splash page itself is never splashed. Visiting it directly still
#      writes its cookie, so clicking through is not bounced back — the same
#      trap v1 hit in page mode.
#
# EXISTING v1 CONFIG IS MIGRATED, not dropped: whatever page was flagged
# is_splash becomes the homepage's pairing, carrying the tenant's stored
# mode/style/frequency across.
set -e

MARKER="MARKER-SPLASH-2"
CTRL="app/Http/Controllers/Tenant/PublicController.php"

[ -f "$CTRL" ] || { echo "ERROR: run from the repo root"; exit 1; }
grep -q "MARKER-SPLASH" "$CTRL" || { echo "ERROR: requires apply-splash-page.sh (v1)"; exit 1; }
if grep -q "$MARKER" "$CTRL" 2>/dev/null; then
  echo "ok: already applied ($MARKER present) — no-op"
  exit 0
fi

# ---------------------------------------------------------------
# 1. Schema + carry v1 forward
# ---------------------------------------------------------------
cat > database/migrations/2026_08_15_090000_splash_pairings.php <<'EOF'
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * MARKER-SPLASH-2 — a splash is attached to the page it appears BEFORE.
 *
 * Columns live on tenant_pages rather than in a join table because a visited
 * page has at most one splash: the pairing is a property of that page, and
 * a table would only add a join for a strict one-to-one.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenant_pages', function (Blueprint $table) {
            $table->uuid('splash_page_id')->nullable()->after('is_splash');
            $table->string('splash_mode', 12)->default('overlay')->after('splash_page_id');
            $table->string('splash_style', 12)->default('full')->after('splash_mode');
            $table->string('splash_frequency', 12)->default('session')->after('splash_style');
            $table->date('splash_starts_at')->nullable()->after('splash_frequency');
            $table->date('splash_ends_at')->nullable()->after('splash_starts_at');
            $table->index(['tenant_id', 'splash_page_id']);
        });

        // Carry v1 across: the flagged page becomes the homepage's pairing,
        // with the tenant's stored settings. Anyone who configured a splash
        // yesterday keeps exactly what they configured.
        $tenants = DB::table('tenant_pages')
            ->where('is_splash', true)
            ->select('tenant_id', 'id')
            ->get();

        foreach ($tenants as $row) {
            $settings = DB::table('tenants')->where('id', $row->tenant_id)->value('settings');
            $s = is_string($settings) ? (json_decode($settings, true) ?: []) : (array) $settings;

            DB::table('tenant_pages')
                ->where('tenant_id', $row->tenant_id)
                ->where('is_home', true)
                ->update([
                    'splash_page_id'   => $row->id,
                    'splash_mode'      => in_array(($s['splash_mode'] ?? 'overlay'), ['overlay', 'page'], true) ? $s['splash_mode'] : 'overlay',
                    'splash_style'     => in_array(($s['splash_style'] ?? 'full'), ['full', 'sheet'], true) ? $s['splash_style'] : 'full',
                    'splash_frequency' => in_array((string) ($s['splash_frequency'] ?? 'session'), ['session', '7', '30', 'always'], true) ? (string) $s['splash_frequency'] : 'session',
                ]);
        }
    }

    public function down(): void
    {
        Schema::table('tenant_pages', function (Blueprint $table) {
            $table->dropIndex(['tenant_id', 'splash_page_id']);
            $table->dropColumn([
                'splash_page_id', 'splash_mode', 'splash_style',
                'splash_frequency', 'splash_starts_at', 'splash_ends_at',
            ]);
        });
    }
};
EOF
echo "ok: migration + v1 carry-forward"

python3 - <<'PY'
import io
p = 'app/Models/Tenant/TenantPage.php'
src = io.open(p, encoding='utf-8').read()
a = "'is_home','is_splash','is_published','is_in_nav','nav_order']; // MARKER-SPLASH"
assert src.count(a) == 1
src = src.replace(a, "'is_home','is_splash','is_published','is_in_nav','nav_order',\n        'splash_page_id','splash_mode','splash_style','splash_frequency','splash_starts_at','splash_ends_at']; // MARKER-SPLASH-2", 1)
b = "'is_in_nav' => 'boolean']; // MARKER-SPLASH"
assert src.count(b) == 1
src = src.replace(b, "'is_in_nav' => 'boolean',\n        'splash_starts_at' => 'date', 'splash_ends_at' => 'date']; // MARKER-SPLASH-2", 1)
io.open(p, 'w', encoding='utf-8').write(src)
print('ok: TenantPage')
PY

# ---------------------------------------------------------------
# 2. Resolver
# ---------------------------------------------------------------
cat > app/Support/SplashSettings.php <<'EOF'
<?php

namespace App\Support;

use App\Models\Tenant;
use App\Models\Tenant\TenantPage;
use Illuminate\Http\Request;

/**
 * MARKER-SPLASH-2 — answers one question: does THIS visited page show a
 * splash to THIS visitor, and with what settings.
 *
 * Every value is clamped on the way out. A bad stored value must never be
 * able to gate a shop's site shut.
 */
class SplashSettings
{
    /** Per-splash so dismissing one never suppresses another. */
    public static function cookieName(string $splashPageId): string
    {
        return 'intake_splash_' . substr(preg_replace('/[^a-zA-Z0-9]/', '', $splashPageId), 0, 24);
    }

    /** Master switch, still tenant-wide: one toggle turns every splash off. */
    public static function enabled(Tenant $tenant): bool
    {
        return (bool) (((array) ($tenant->settings ?? []))['splash_enabled'] ?? false);
    }

    /**
     * The pairing for a visited page, or null. Returns the splash page plus
     * its normalized settings.
     */
    public static function forPage(Tenant $tenant, TenantPage $visited): ?array
    {
        if (! self::enabled($tenant) || ! $visited->splash_page_id) {
            return null;
        }

        // A splash never splashes itself, or the click-through loops.
        if ($visited->splash_page_id === $visited->id) {
            return null;
        }

        $splash = TenantPage::where('tenant_id', $tenant->id)
            ->where('id', $visited->splash_page_id)
            ->where('is_published', true)
            ->first();

        if (! $splash) {
            return null;
        }

        if (! self::withinWindow($tenant, $visited)) {
            return null;
        }

        return [
            'page'      => $splash,
            'mode'      => in_array($visited->splash_mode, ['overlay', 'page'], true) ? $visited->splash_mode : 'overlay',
            'style'     => in_array($visited->splash_style, ['full', 'sheet'], true) ? $visited->splash_style : 'full',
            'frequency' => in_array((string) $visited->splash_frequency, ['session', '7', '30', 'always'], true) ? (string) $visited->splash_frequency : 'session',
            'cookie'    => self::cookieName($splash->id),
        ];
    }

    /**
     * Date window, in the SHOP's timezone — an event that runs "through
     * Saturday" should end when it is Sunday where the shop is, not in UTC.
     * Both bounds are inclusive.
     */
    public static function withinWindow(Tenant $tenant, TenantPage $visited): bool
    {
        $today = $tenant->localToday()->startOfDay();

        if ($visited->splash_starts_at && $today->lt($visited->splash_starts_at->startOfDay())) {
            return false;
        }
        if ($visited->splash_ends_at && $today->gt($visited->splash_ends_at->startOfDay())) {
            return false;
        }

        return true;
    }

    /** Has this visitor already dismissed THIS splash? */
    public static function alreadySeen(Request $request, array $pairing): bool
    {
        if ($pairing['frequency'] === 'always') {
            return false; // deliberately never remembered
        }

        return (bool) $request->cookie($pairing['cookie']);
    }

    /** Cookie lifetime in minutes; 0 means a session cookie. */
    public static function cookieMinutes(array $pairing): int
    {
        return match ($pairing['frequency']) {
            '7'  => 60 * 24 * 7,
            '30' => 60 * 24 * 30,
            default => 0,
        };
    }
}
EOF
echo "ok: SplashSettings rewritten"

# ---------------------------------------------------------------
# 3. Serving — every page consults its own pairing
# ---------------------------------------------------------------
python3 - <<'PY'
import io
p = 'app/Http/Controllers/Tenant/PublicController.php'
src = io.open(p, encoding='utf-8').read()

# --- home(): replace the v1 block ---------------------------------------
start = src.index("        // MARKER-SPLASH -- the homepage is the ONLY route that consults the")
end = src.index("        return $this->renderPage($page, $splashPage, $splashCfg);", start) \
      + len("        return $this->renderPage($page, $splashPage, $splashCfg);")
src = src[:start] + """        // MARKER-SPLASH-2 -- the homepage asks for its OWN pairing, exactly
        // like every other page now does.
        return $this->renderWithSplash($page);""" + src[end:]

# --- page(): the v1 cookie special-case becomes the shared path ---------
old_page = src[src.index("        // MARKER-SPLASH -- viewing the splash directly counts as seeing it, so"):]
old_page = old_page[:old_page.index("        return $this->renderPage($page);") + len("        return $this->renderPage($page);")]
src = src.replace(old_page, """        // MARKER-SPLASH-2 -- any page can carry a splash now, so this route
        // resolves one too. Direct links to a page WITH a pairing are
        // interrupted on purpose: the shop opted that page in.
        return $this->renderWithSplash($page);""", 1)

# --- the shared path ----------------------------------------------------
anchor = "    private function renderPage(TenantPage $page, ?TenantPage $splashPage = null, array $splashCfg = [])"
assert src.count(anchor) == 1, 'renderPage anchor'
src = src.replace(anchor, """    /**
     * MARKER-SPLASH-2 -- resolve this page's pairing and render accordingly.
     *
     * Viewing a page that IS somebody's splash writes that splash's cookie,
     * so clicking through to the page behind it is not bounced straight back
     * -- the trap v1 hit in page mode.
     */
    private function renderWithSplash(TenantPage $page)
    {
        $tenant = tenant();

        // Seeing a splash counts as seeing it, whichever route served it.
        $servedAsSplash = (bool) $page->is_splash;

        $pairing = \\App\\Support\\SplashSettings::forPage($tenant, $page);
        $show    = $pairing && ! \\App\\Support\\SplashSettings::alreadySeen(request(), $pairing);

        if ($show && $pairing['mode'] === 'page') {
            return redirect('/' . $pairing['page']->slug);
        }

        $response = response($this->renderPage(
            $page,
            $show ? $pairing['page'] : null,
            $show ? $pairing : []
        ));

        if ($servedAsSplash) {
            $ownCookie = \\App\\Support\\SplashSettings::cookieName($page->id);
            // Mirror the frequency of whichever pairing points at this page.
            $owner = TenantPage::where('tenant_id', $tenant->id)
                ->where('splash_page_id', $page->id)
                ->first();
            $freq = $owner?->splash_frequency ?? 'session';

            if ($freq !== 'always') {
                $minutes = \\App\\Support\\SplashSettings::cookieMinutes(['frequency' => (string) $freq]);
                $response = $response->cookie(
                    $ownCookie, '1', $minutes, '/', null, request()->isSecure(), false, false, 'lax'
                );
            }
        }

        return $response;
    }

""" + anchor, 1)

io.open(p, 'w', encoding='utf-8').write(src)
print('ok: PublicController')
PY

# ---------------------------------------------------------------
# 4. Overlay: per-splash cookie
# ---------------------------------------------------------------
python3 - <<'PY'
import io
p = 'resources/views/public/_splash-overlay.blade.php'
src = io.open(p, encoding='utf-8').read()

a = "  var FREQ = @json($spFreq);"
assert src.count(a) == 1, 'freq var'
src = src.replace(a, """  var FREQ   = @json($spFreq);
  var COOKIE = @json($splashCfg['cookie'] ?? 'intake_splash'); // MARKER-SPLASH-2 — per splash""", 1)

b = "    var bits = '{{ \\App\\Support\\SplashSettings::COOKIE }}=1; path=/; samesite=lax';"
assert src.count(b) == 1, 'cookie write'
src = src.replace(b, "    var bits = COOKIE + '=1; path=/; samesite=lax';", 1)

io.open(p, 'w', encoding='utf-8').write(src)
print('ok: overlay cookie is per-splash')
PY

# ---------------------------------------------------------------
# 5. Preview: render a visited page WITH its splash over it
# ---------------------------------------------------------------
python3 - <<'PY'
import io
p = 'app/Http/Controllers/Tenant/PageBuilderController.php'
src = io.open(p, encoding='utf-8').read()

a = """        return view('public.page', compact('page', 'sections', 'navItems', 'catalog'));
    }

    public function store(Request $request)"""
assert src.count(a) == 1, 'preview return'
src = src.replace(a, """        // MARKER-SPLASH-2 -- ?over=1 composites this page's splash on top, so
        // the settings screen previews what a visitor actually gets rather
        // than the splash page in isolation. Ignores the cookie and the date
        // window on purpose: the shop is asking to see it.
        $splashPage = null; $splashSections = null; $splashCfg = [];
        if ($request->boolean('over') && $page->splash_page_id) {
            $splashPage = TenantPage::where('tenant_id', $tenant->id)
                ->where('id', $page->splash_page_id)->first();

            if ($splashPage) {
                $splashSections = $splashPage->sections()->where('is_visible', true)->get();
                $splashSections = TenantPageSection::withInheritedChrome(
                    $splashSections, $splashPage->tenant_id, $splashPage->id
                );
                $splashCfg = [
                    'style'     => in_array($page->splash_style, ['full','sheet'], true) ? $page->splash_style : 'full',
                    'frequency' => 'always',   // never let a preview write a cookie
                    'cookie'    => 'intake_splash_preview',
                ];

                // In page mode the visitor never sees this page, so neither
                // should the preview.
                if ($page->splash_mode === 'page') {
                    $sections = $splashSections;
                    $splashPage = null; $splashSections = null; $splashCfg = [];
                }
            }
        }

        return view('public.page', compact(
            'page', 'sections', 'navItems', 'catalog',
            'splashPage', 'splashSections', 'splashCfg'
        ));
    }

    public function store(Request $request)""", 1)

io.open(p, 'w', encoding='utf-8').write(src)
print('ok: preview supports ?over=1')
PY

# ---------------------------------------------------------------
# 6. Save handler for the pairing rows
# ---------------------------------------------------------------
python3 - <<'PY'
import io
p = 'app/Http/Controllers/Tenant/PageBuilderController.php'
src = io.open(p, encoding='utf-8').read()

start = src.index("    /**\n     * MARKER-SPLASH — save the splash settings and which page serves as it.")
end = src.index("    private function editPage($tenant, string $id)", start)

src = src[:start] + """    /**
     * MARKER-SPLASH-2 — save the pairing table.
     *
     * Rows are "when someone visits page A, show them splash B". The whole
     * set is replaced on save: anything not submitted is cleared, which is
     * what makes removing a row in the UI actually remove it.
     */
    public function saveSplash(Request $request)
    {
        $tenant = tenant();

        $data = $request->validate([
            'splash_enabled'          => 'nullable|boolean',
            'rows'                    => 'nullable|array|max:20',
            'rows.*.visit_page_id'    => 'required|string',
            'rows.*.splash_page_id'   => 'required|string',
            'rows.*.mode'             => 'required|in:overlay,page',
            'rows.*.style'            => 'required|in:full,sheet',
            'rows.*.frequency'        => 'required|in:session,7,30,always',
            'rows.*.starts_at'        => 'nullable|date',
            'rows.*.ends_at'          => 'nullable|date|after_or_equal:rows.*.starts_at',
        ]);

        $rows  = $data['rows'] ?? [];
        $pages = TenantPage::where('tenant_id', $tenant->id)->get()->keyBy('id');

        // Validate before writing anything, so a bad row cannot leave the
        // table half-saved.
        $seen = [];
        foreach ($rows as $r) {
            $visit  = $pages[$r['visit_page_id']]  ?? null;
            $splash = $pages[$r['splash_page_id']] ?? null;

            if (! $visit || ! $splash) {
                return back()->with('flash_error', 'One of those pages no longer exists — reload and try again.');
            }
            if ($visit->id === $splash->id) {
                return back()->with('flash_error', 'A page cannot be its own splash: "' . $visit->title . '".');
            }
            if (isset($seen[$visit->id])) {
                return back()->with('flash_error', '"' . $visit->title . '" is listed twice. Each page can have one splash.');
            }
            $seen[$visit->id] = true;
        }

        DB::transaction(function () use ($tenant, $rows, $data) {
            // Clear every pairing and every is_splash flag, then rewrite from
            // the submitted set.
            TenantPage::where('tenant_id', $tenant->id)->update([
                'splash_page_id'   => null,
                'is_splash'        => false,
                'splash_starts_at' => null,
                'splash_ends_at'   => null,
            ]);

            foreach ($rows as $r) {
                TenantPage::where('tenant_id', $tenant->id)->where('id', $r['visit_page_id'])->update([
                    'splash_page_id'   => $r['splash_page_id'],
                    'splash_mode'      => $r['mode'],
                    'splash_style'     => $r['style'],
                    'splash_frequency' => $r['frequency'],
                    'splash_starts_at' => $r['starts_at'] ?: null,
                    'splash_ends_at'   => $r['ends_at'] ?: null,
                ]);

                // Being used as a splash keeps a page out of the navigation.
                TenantPage::where('tenant_id', $tenant->id)->where('id', $r['splash_page_id'])->update([
                    'is_splash'  => true,
                    'is_in_nav'  => false,
                ]);
            }

            $settings = (array) ($tenant->settings ?? []);
            $settings['splash_enabled'] = (bool) ($data['splash_enabled'] ?? false);
            $tenant->settings = $settings;
            $tenant->save();
        });

        return back()->with('flash', 'Splash settings saved.');
    }

""" + src[end:]

# index() feeds the table
b = """        // MARKER-SPLASH
        $splashCfg = \\App\\Support\\SplashSettings::config($tenant);

        return view('tenant.pages.index', compact('pages', 'splashCfg'));"""
assert src.count(b) == 1, 'index return'
src = src.replace(b, """        // MARKER-SPLASH-2
        $splashEnabled = \\App\\Support\\SplashSettings::enabled($tenant);
        $splashRows = $pages->whereNotNull('splash_page_id')->values();

        return view('tenant.pages.index', compact('pages', 'splashEnabled', 'splashRows'));""", 1)

if 'use Illuminate\\Support\\Facades\\DB;' not in src:
    src = src.replace("use Illuminate\\Http\\Request;", "use Illuminate\\Http\\Request;\nuse Illuminate\\Support\\Facades\\DB; // MARKER-SPLASH-2", 1)

io.open(p, 'w', encoding='utf-8').write(src)
print('ok: saveSplash rewritten for pairings')
PY

echo ""
echo "== splash pairings (server) applied =="
