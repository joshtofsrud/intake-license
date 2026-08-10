#!/usr/bin/env bash
set -euo pipefail
# apply-marketing-traffic-report.sh — MARKER-MKTTRAFFIC
# Traffic reporting for intake.works, on master admin, mirroring the tenant
# traffic report — plus signup and intent funnels.
#
# WHAT WAS ACTUALLY THERE (verified, not assumed):
#   - Master admin has NO traffic page at all.
#   - The marketing site is COMPLETELY BLIND: marketing/page.blade.php never
#     includes public/_funnel_tracker, and /funnel/track is declared inside the
#     TENANT host group, so it isn't even reachable from intake.works. The only
#     thing recorded today is quiz_completions.
#   - The tenant side has a full stack worth reusing: tenant_funnel_events
#     (session, path, referrer, utm, device, step) and TrafficReportService
#     (833 lines — windows, period-over-period, top stats, daily series).
#
# APPROACH — reuse, don't rebuild:
#   1. Marketing events land in tenant_funnel_events under the PLATFORM tenant
#      (Tenant::where('is_platform')). It is a real tenant row whose pages are
#      real TenantPages, so this is its traffic, not a hack — and it means
#      TrafficReportService works against it unchanged.
#   2. A platform-host POST /funnel/track (own controller, since the tenant one
#      resolves a tenant from the host and there is none on intake.works).
#   3. Marketing pages get the tracker.
#   4. Intent events recorded SERVER-side where that is the honest place:
#      quiz_completed on quiz submit, contact_submitted on the contact form.
#   5. New master admin page: traffic tiles + daily chart + referrers + UTM,
#      and a SIGNUP FUNNEL — visitors -> pricing viewed -> quiz completed ->
#      contact submitted -> tenants created.
#
# HONEST LIMITATION, surfaced in the UI rather than hidden: self-serve signup
# does not exist yet, so signup_started / signup_completed will read zero until
# onboarding ships. The funnel names them now so the wiring is already in place.

MIG=database/migrations/2026_08_09_150000_index_platform_funnel_events.php
CTRL=app/Http/Controllers/Platform/MarketingFunnelController.php
SVC=app/Services/Platform/SignupFunnelService.php
PAGE=app/Filament/Pages/MarketingTraffic.php
VIEW=resources/views/filament/pages/marketing-traffic.blade.php
TRACKER=resources/views/marketing/_funnel_tracker.blade.php
MPAGE=resources/views/marketing/page.blade.php
MCTRL=app/Http/Controllers/Platform/MarketingController.php
QCTRL=app/Http/Controllers/Platform/PlanQuizController.php
ROUTES=routes/web.php

for f in "$MPAGE" "$MCTRL" "$QCTRL" "$ROUTES"; do
  [ -f "$f" ] || { echo "MISSING $f — run from the repo root"; exit 1; }
done

if grep -q "MARKER-MKTTRAFFIC" "$ROUTES"; then
  echo "Already applied (MARKER-MKTTRAFFIC present) — no-op."
  exit 0
fi

# ================================================================ index
if [ -f "$MIG" ]; then echo "ok   index migration already present"; else
cat <<'EOF' > "$MIG"
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// MARKER-MKTTRAFFIC — marketing traffic lands in tenant_funnel_events under the
// platform tenant, so the report filters by (tenant_id, event_type, created_at)
// exactly like the tenant one. Add the index only if it isn't already there.
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('tenant_funnel_events')) {
            return;
        }

        $existing = collect(DB::select('SHOW INDEX FROM tenant_funnel_events'))
            ->pluck('Key_name')->unique();

        if (! $existing->contains('tfe_tenant_type_created_idx')) {
            DB::statement(
                'CREATE INDEX tfe_tenant_type_created_idx
                 ON tenant_funnel_events (tenant_id, event_type, created_at)'
            );
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('tenant_funnel_events')) {
            DB::statement('DROP INDEX tfe_tenant_type_created_idx ON tenant_funnel_events');
        }
    }
};
EOF
echo "ok   index migration created"; fi

# ================================================================ controller
if [ -f "$CTRL" ]; then echo "ok   funnel controller already present"; else
cat <<'EOF' > "$CTRL"
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

        self::record($data['event_type'], $data);

        return response()->json(['ok' => true]);
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
                'created_at'      => now(),
                'updated_at'      => now(),
            ]);
        } catch (\Throwable $e) {
            \Log::warning('marketing funnel event failed', [
                'event' => $eventType, 'error' => $e->getMessage(),
            ]);
        }
    }
}
EOF
echo "ok   funnel controller created"; fi

# ================================================================ signup funnel
if [ -f "$SVC" ]; then echo "ok   signup funnel service already present"; else
cat <<'EOF' > "$SVC"
<?php

namespace App\Services\Platform;

// MARKER-MKTTRAFFIC — the signup/intent funnel.
//
// Deliberately NOT modelled on the tenant booking funnel, because the shape is
// different: a booking is one session end to end, whereas signup spans anonymous
// browsing and a tenant record created later, possibly on another day. So the
// later stages count REAL OBJECTS (quiz rows, tenants) rather than sessions,
// and each stage says which it is instead of implying a single-session journey.

use App\Models\Tenant;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class SignupFunnelService
{
    public function __construct(
        private CarbonImmutable $start,
        private CarbonImmutable $end
    ) {}

    private function platformTenantId(): ?string
    {
        return Tenant::where('is_platform', true)->value('id');
    }

    private function sessions(string $eventType): int
    {
        $id = $this->platformTenantId();
        if (! $id) {
            return 0;
        }

        return (int) DB::table('tenant_funnel_events')
            ->where('tenant_id', $id)
            ->where('event_type', $eventType)
            ->whereBetween('created_at', [$this->start, $this->end])
            ->distinct()->count('session_id');
    }

    /** @return array<int, array{label:string, count:int, unit:string, note:?string}> */
    public function stages(): array
    {
        $visitors = $this->sessions('page_view');
        $pricing  = $this->sessions('pricing_viewed');
        $quiz     = $this->sessions('quiz_completed');
        $contact  = $this->sessions('contact_submitted');

        // Tenants created in the window — the only stage that is a real outcome.
        $tenants = (int) Tenant::query()
            ->where('is_platform', false)
            ->whereBetween('created_at', [$this->start, $this->end])
            ->count();

        $signupStarted = $this->sessions('signup_started');

        return [
            ['label' => 'Visited the site',   'count' => $visitors, 'unit' => 'sessions', 'note' => null],
            ['label' => 'Viewed pricing',     'count' => $pricing,  'unit' => 'sessions', 'note' => null],
            ['label' => 'Completed the quiz', 'count' => $quiz,     'unit' => 'sessions', 'note' => null],
            ['label' => 'Got in touch',       'count' => $contact,  'unit' => 'sessions', 'note' => null],
            ['label' => 'Started signup',     'count' => $signupStarted, 'unit' => 'sessions',
             'note'  => 'Self-serve signup isn\'t built yet — this stays at zero until it ships.'],
            ['label' => 'Became a tenant',    'count' => $tenants,  'unit' => 'accounts',
             'note'  => 'Counts tenant records created in this window, however they arrived.'],
        ];
    }

    /** Intent signals that don't sit on one funnel line. */
    public function intent(): array
    {
        $out = [
            'quiz_completions'    => 0,
            'quiz_recommendation' => [],
            'industry_pages'      => [],
        ];

        if (Schema::hasTable('quiz_completions')) {
            $rows = DB::table('quiz_completions')
                ->whereBetween('created_at', [$this->start, $this->end])
                ->get(['recommendation']);

            $out['quiz_completions'] = $rows->count();
            $out['quiz_recommendation'] = $rows->groupBy('recommendation')
                ->map->count()->sortDesc()->all();
        }

        $id = $this->platformTenantId();
        if ($id) {
            $out['industry_pages'] = DB::table('tenant_funnel_events')
                ->where('tenant_id', $id)
                ->where('event_type', 'page_view')
                ->where('path', 'like', '/for/%')
                ->whereBetween('created_at', [$this->start, $this->end])
                ->select('path', DB::raw('COUNT(DISTINCT session_id) as sessions'))
                ->groupBy('path')->orderByDesc('sessions')->limit(10)
                ->pluck('sessions', 'path')->all();
        }

        return $out;
    }
}
EOF
echo "ok   signup funnel service created"; fi

# ================================================================ tracker
if [ -f "$TRACKER" ]; then echo "ok   marketing tracker already present"; else
cat <<'EOF' > "$TRACKER"
{{-- MARKER-MKTTRAFFIC — marketing-site funnel tracking. Mirrors the tenant
     tracker's contract but posts to the platform endpoint, since /funnel/track
     is tenant-host only. Anonymous: a random session id in sessionStorage, no
     third party, no fingerprinting. --}}
<script>
(function () {
  if (window.__intakeMktFunnel) { return; }
  window.__intakeMktFunnel = true;

  var KEY = 'intake_mkt_sid';
  var sid;
  try {
    sid = sessionStorage.getItem(KEY);
    if (!sid) {
      sid = (Date.now().toString(36) + Math.random().toString(36).slice(2, 12));
      sessionStorage.setItem(KEY, sid);
    }
  } catch (e) {
    sid = 'nostore';
  }

  var params = new URLSearchParams(window.location.search);

  function device() {
    var ua = navigator.userAgent || '';
    if (/bot|crawl|spider|slurp/i.test(ua)) { return 'bot'; }
    if (/tablet|ipad/i.test(ua)) { return 'tablet'; }
    if (/mobi|android|iphone/i.test(ua)) { return 'mobile'; }
    return 'desktop';
  }

  function send(eventType, step) {
    var payload = JSON.stringify({
      session_id:   sid,
      event_type:   eventType,
      path:         window.location.pathname,
      referrer_url: document.referrer || null,
      utm_source:   params.get('utm_source') || null,
      utm_medium:   params.get('utm_medium') || null,
      utm_campaign: params.get('utm_campaign') || null,
      device:       device(),
      step:         step || null
    });

    try {
      if (navigator.sendBeacon) {
        navigator.sendBeacon('/mkt/track', new Blob([payload], { type: 'application/json' }));
        return;
      }
    } catch (e) { /* fall through */ }

    fetch('/mkt/track', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: payload,
      keepalive: true
    }).catch(function () {});
  }

  window.__intakeMktTrack = send;

  send('page_view');

  // Pricing is the clearest intent signal the marketing site has today.
  if (/^\/pricing/.test(window.location.pathname)) {
    send('pricing_viewed');
  }
})();
</script>
EOF
echo "ok   marketing tracker created"; fi

# ================================================================ include it
python3 - "$MPAGE" <<'PY'
import sys
path = sys.argv[1]
src = open(path).read()

old = """@include('marketing._plan-quiz')"""
new = """@include('marketing._plan-quiz')
@include('marketing._funnel_tracker') {{-- MARKER-MKTTRAFFIC --}}"""
n = src.count(old)
if n != 1:
    print(f"FAIL tracker include: anchor found {n} times"); sys.exit(1)
src = src.replace(old, new, 1)
print("ok   tracker included on marketing pages")

open(path, 'w').write(src)
PY

# ================================================================ server events
python3 - "$QCTRL" <<'PY'
import sys, re
path = sys.argv[1]
src = open(path).read()

m = re.search(r"public function complete\(Request \$request\)\s*\n\s*\{\n", src)
if not m:
    print("FAIL quiz hook: complete() not found"); sys.exit(1)

# Insert the record AFTER validation so we only log real completions. Find the
# end of the validate([...]); call.
vstart = src.index('$data = $request->validate([', m.end())
depth = 0
i = src.index('[', vstart)
while i < len(src):
    if src[i] == '[':
        depth += 1
    elif src[i] == ']':
        depth -= 1
        if depth == 0:
            break
    i += 1
end = src.index(';', i) + 1

hook = """

        // MARKER-MKTTRAFFIC — the server is the honest place to record this:
        // the row is about to be written, so the completion definitely happened.
        \\App\\Http\\Controllers\\Platform\\MarketingFunnelController::record('quiz_completed', [
            'session_id' => $data['session_id'],
            'path'       => '/pricing',
            'step'       => $data['recommendation'] ?? null,
        ]);"""

src = src[:end] + hook + src[end:]
print("ok   quiz_completed event")

open(path, 'w').write(src)
PY

python3 - "$MCTRL" <<'PY'
import sys, re
path = sys.argv[1]
src = open(path).read()

m = re.search(r"public function contact\(Request \$request\)[^\n]*\n\s*\{\n", src)
if not m:
    print("FAIL contact hook: contact() not found"); sys.exit(1)

hook = """        // MARKER-MKTTRAFFIC — only a real POST is a submission.
        if ($request->isMethod('post')) {
            \\App\\Http\\Controllers\\Platform\\MarketingFunnelController::record('contact_submitted', [
                'session_id' => (string) $request->input('session_id', 'server'),
                'path'       => '/contact',
            ]);
        }

"""
src = src[:m.end()] + hook + src[m.end():]
print("ok   contact_submitted event")

open(path, 'w').write(src)
PY

# ================================================================ route
python3 - "$ROUTES" <<'PY'
import sys
path = sys.argv[1]
src = open(path).read()

old = """    // --- Plan quiz analytics (marketing funnel) ---"""
new = """    // --- Marketing funnel tracking (MARKER-MKTTRAFFIC) ---
    // The tenant tracker's /funnel/track lives in the TENANT host group and
    // resolves a tenant from the host, so intake.works could never reach it.
    Route::post('/mkt/track',
        [Platform\\MarketingFunnelController::class, 'store']
    )->name('platform.mkt.track');

    // --- Plan quiz analytics (marketing funnel) ---"""
n = src.count(old)
if n != 1:
    print(f"FAIL route: anchor found {n} times"); sys.exit(1)
src = src.replace(old, new, 1)
print("ok   route /mkt/track")

open(path, 'w').write(src)
PY

# CSRF exemption — the tracker fires from cached pages, same reasoning as the quiz
python3 - bootstrap/app.php <<'PY'
import sys
path = sys.argv[1]
src = open(path).read()

old = """            'webhooks/stripe',"""
new = """            'mkt/track', // MARKER-MKTTRAFFIC — beacon from cached marketing pages
            'webhooks/stripe',"""
n = src.count(old)
if n != 1:
    print(f"FAIL csrf: anchor found {n} times"); sys.exit(1)
src = src.replace(old, new, 1)
print("ok   csrf exemption")

open(path, 'w').write(src)
PY

# ================================================================ filament page
if [ -f "$PAGE" ]; then echo "ok   filament page already present"; else
cat <<'EOF' > "$PAGE"
<?php

namespace App\Filament\Pages;

// MARKER-MKTTRAFFIC — intake.works traffic + signup funnel, master admin.
// Reuses the tenant TrafficReportService against the platform tenant rather
// than reimplementing windows, comparisons and daily series.

use App\Models\Tenant;
use App\Services\Platform\SignupFunnelService;
use App\Services\Tenant\TrafficReportService;
use Carbon\CarbonImmutable;
use Filament\Pages\Page;

class MarketingTraffic extends Page
{
    protected static ?string $title           = 'Marketing Traffic';
    protected static ?string $slug            = 'marketing-traffic';
    protected static ?string $navigationIcon  = 'heroicon-o-chart-bar';
    protected static ?string $navigationLabel = 'Marketing Traffic';
    protected static ?int    $navigationSort  = -80;

    protected static string $view = 'filament.pages.marketing-traffic';

    public string $window = '30d';

    public function mount(): void
    {
        $this->window = request()->query('window', '30d');
        if (! in_array($this->window, ['7d', '30d', '90d'], true)) {
            $this->window = '30d';
        }
    }

    protected function getViewData(): array
    {
        $platform = Tenant::where('is_platform', true)->first();

        if (! $platform) {
            return ['platform' => null, 'window' => $this->window];
        }

        $report = new TrafficReportService($platform, $this->window);
        $funnel = new SignupFunnelService(
            CarbonImmutable::instance($report->curStart()),
            CarbonImmutable::instance($report->curEnd())
        );

        return [
            'platform'   => $platform,
            'window'     => $this->window,
            'rangeLabel' => $report->rangeLabel(),
            'stats'      => $report->topStats(),
            'daily'      => $report->dailyVisitors(),
            'stages'     => $funnel->stages(),
            'intent'     => $funnel->intent(),
        ];
    }
}
EOF
echo "ok   filament page created"; fi

if [ -f "$VIEW" ]; then echo "ok   filament view already present"; else
cat <<'EOF' > "$VIEW"
{{-- MARKER-MKTTRAFFIC --}}
<x-filament-panels::page>
@if(! $platform)
  <div style="padding:20px;border-radius:12px;background:rgba(255,255,255,.04)">
    No platform tenant found (<code>tenants.is_platform</code>), so there is nothing to report on yet.
  </div>
@else

<style>
.mt-bar{display:flex;gap:6px;margin-bottom:18px}
.mt-bar a{padding:6px 13px;border-radius:99px;font-size:12.5px;text-decoration:none;
  background:rgba(255,255,255,.06);color:inherit;opacity:.6}
.mt-bar a.on{opacity:1;font-weight:600;box-shadow:inset 0 0 0 1px currentColor}
.mt-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(160px,1fr));gap:12px;margin-bottom:22px}
.mt-tile{padding:14px 16px;border-radius:12px;background:rgba(255,255,255,.04)}
.mt-tile-k{font-size:11px;opacity:.55}
.mt-tile-v{font-size:24px;font-weight:700;margin-top:2px}
.mt-tile-d{font-size:11.5px;margin-top:2px;opacity:.6}
.mt-sec{font-size:11px;font-weight:600;text-transform:uppercase;letter-spacing:.07em;opacity:.5;margin:24px 0 10px}
.mt-funnel{display:flex;flex-direction:column;gap:8px}
.mt-step{padding:11px 14px;border-radius:10px;background:rgba(255,255,255,.04);
  display:flex;align-items:center;gap:12px}
.mt-step-l{flex:1;font-size:13.5px}
.mt-step-n{font-size:18px;font-weight:700;font-variant-numeric:tabular-nums}
.mt-step-u{font-size:11px;opacity:.45;width:64px;text-align:right}
.mt-step-note{font-size:11px;opacity:.5;margin-top:3px}
.mt-track{height:4px;border-radius:3px;background:rgba(255,255,255,.10);margin-top:7px;overflow:hidden}
.mt-track i{display:block;height:100%;background:currentColor;opacity:.75}
.mt-two{display:grid;grid-template-columns:1fr 1fr;gap:16px}
@media(max-width:820px){.mt-two{grid-template-columns:1fr}}
.mt-row{display:flex;justify-content:space-between;gap:12px;padding:7px 0;font-size:13px;
  border-bottom:.5px solid rgba(255,255,255,.07)}
.mt-row:last-child{border-bottom:0}
.mt-empty{padding:18px;text-align:center;font-size:12.5px;opacity:.4}
</style>

<div class="mt-bar">
  @foreach(['7d' => 'Last 7 days', '30d' => 'Last 30 days', '90d' => 'Last 90 days'] as $wKey => $wLabel)
    <a href="?window={{ $wKey }}" class="{{ $window === $wKey ? 'on' : '' }}">{{ $wLabel }}</a>
  @endforeach
  <span style="margin-left:auto;font-size:12px;opacity:.45;align-self:center">{{ $rangeLabel }}</span>
</div>

<div class="mt-grid">
  @foreach($stats as $tile)
    <div class="mt-tile">
      <div class="mt-tile-k">{{ $tile['label'] ?? '' }}</div>
      <div class="mt-tile-v">{{ number_format((float) ($tile['value'] ?? 0)) }}</div>
      @if(isset($tile['delta']) && $tile['delta'] !== null)
        <div class="mt-tile-d">{{ $tile['delta'] > 0 ? '+' : '' }}{{ $tile['delta'] }}% vs previous</div>
      @endif
    </div>
  @endforeach
</div>

<div class="mt-sec">Signup funnel</div>
@php $mtTop = collect($stages)->max('count') ?: 1; @endphp
<div class="mt-funnel">
  @foreach($stages as $stage)
    <div>
      <div class="mt-step">
        <div class="mt-step-l">{{ $stage['label'] }}
          @if($stage['note'])<div class="mt-step-note">{{ $stage['note'] }}</div>@endif
        </div>
        <div class="mt-step-n">{{ number_format($stage['count']) }}</div>
        <div class="mt-step-u">{{ $stage['unit'] }}</div>
      </div>
      <div class="mt-track"><i style="width:{{ $mtTop > 0 ? round(($stage['count'] / $mtTop) * 100) : 0 }}%"></i></div>
    </div>
  @endforeach
</div>

<div class="mt-two" style="margin-top:24px">
  <div>
    <div class="mt-sec" style="margin-top:0">Quiz recommendations</div>
    @forelse($intent['quiz_recommendation'] as $rec => $count)
      <div class="mt-row"><span style="text-transform:capitalize">{{ $rec }}</span><b>{{ number_format($count) }}</b></div>
    @empty
      <div class="mt-empty">No quiz completions in this window</div>
    @endforelse
  </div>

  <div>
    <div class="mt-sec" style="margin-top:0">Industry landing pages</div>
    @forelse($intent['industry_pages'] as $path => $sessions)
      <div class="mt-row"><span>{{ $path }}</span><b>{{ number_format($sessions) }}</b></div>
    @empty
      <div class="mt-empty">No industry page visits in this window</div>
    @endforelse
  </div>
</div>

<div class="mt-sec">Daily visitors</div>
{{-- dailyVisitors() returns ['current' => int[], 'prior' => int[], 'hourly' => bool]
     — a flat series of counts, one per bucket, NOT a list of rows. --}}
@php $mtSeries = $daily['current'] ?? []; @endphp
@if(count($mtSeries))
  @php $mtMax = max($mtSeries) ?: 1; @endphp
  <div style="display:flex;align-items:flex-end;gap:2px;height:110px">
    @foreach($mtSeries as $mtV)
      <div title="{{ (int) $mtV }} {{ ($daily['hourly'] ?? false) ? 'this hour' : 'this day' }}"
           style="flex:1;min-width:2px;height:{{ max(2, round(((int) $mtV / $mtMax) * 100)) }}%;
                  background:currentColor;opacity:.55;border-radius:2px 2px 0 0"></div>
    @endforeach
  </div>
@else
  <div class="mt-empty">No traffic recorded yet — data starts accumulating once this deploys.</div>
@endif

@endif
</x-filament-panels::page>
EOF
echo "ok   filament view created"; fi

php -l "$CTRL"
php -l "$SVC"
php -l "$PAGE"
php -l "$QCTRL"
php -l "$MCTRL"

echo ""
echo "SUCCESS — apply-marketing-traffic-report applied."
echo "Marketing Traffic appears in master admin. It starts EMPTY — events only"
echo "begin accumulating after deploy, since nothing was ever recorded before."
