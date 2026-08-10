#!/usr/bin/env bash
set -euo pipefail
# apply-marketing-sessions-explorer.sh — MARKER-MKTSESSIONS
# A per-session explorer for intake.works, mirroring the tenant admin one
# (MARKER-SESSIONS-EXPLORER / TrafficReportService::bookingSessions).
#
# Every field needed is ALREADY being recorded — session_id, path, created_at,
# device, referrer_domain — so this is pure derivation: no new tracking, no
# schema change, and it works retroactively on rows already collected.
#
#   duration     = last event − first event within a session
#   pages        = the ordered list of page_view paths
#   landing page = the first one
#   status       = converted (quiz/contact) | browsed (2+ pages) | bounced (1)
#
# Shaped for marketing rather than booking: the tenant version renders a fixed
# 6-step booking progress bar, which has no meaning here. Sessions are open-
# ended browsing, so this shows the real page path instead.
#
# TWO HONEST LIMITS, labelled in the UI rather than papered over:
#   - "pages" means pages VISITED. The tracker records page views and a couple
#     of named events; it does not capture in-page clicks. Real click tracking
#     needs new instrumentation and far more event volume.
#   - With no exit event, a single-page session has a duration of 0:00 no
#     matter how long it was read. The tenant explorer has the same limit.
#
# REQUIRES apply-marketing-traffic-report (MARKER-MKTTRAFFIC).

SVC=app/Services/Platform/MarketingSessionsService.php
PAGE=app/Filament/Pages/MarketingTraffic.php
VIEW=resources/views/filament/pages/marketing-traffic.blade.php

for f in "$PAGE" "$VIEW"; do
  [ -f "$f" ] || { echo "PRECONDITION FAILED: deploy apply-marketing-traffic-report.sh first ($f missing)"; exit 1; }
done

if grep -q "MARKER-MKTSESSIONS" "$VIEW"; then
  echo "Already applied (MARKER-MKTSESSIONS present) — no-op."
  exit 0
fi

# ================================================================ service
if [ -f "$SVC" ]; then echo "ok   sessions service already present"; else
cat <<'EOF' > "$SVC"
<?php

namespace App\Services\Platform;

// MARKER-MKTSESSIONS — per-session marketing activity, mirroring the tenant
// admin's booking sessions explorer. Derived entirely from events already
// being recorded, so it works on rows collected before this shipped.

use App\Models\Tenant;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

class MarketingSessionsService
{
    /** Hard cap so a busy window can't load the whole table into memory. */
    public const EVENT_LIMIT = 3000;

    public function __construct(
        private CarbonImmutable $start,
        private CarbonImmutable $end
    ) {}

    /** Friendly label for a named event; page views show their path instead. */
    private static function label(string $eventType, ?string $path): string
    {
        return match ($eventType) {
            'page_view'         => $path ?: '/',
            'pricing_viewed'    => 'Looked at pricing',
            'quiz_started'      => 'Started the plan quiz',
            'quiz_completed'    => 'Completed the plan quiz',
            'contact_submitted' => 'Sent a message',
            'signup_started'    => 'Started signup',
            'signup_completed'  => 'Finished signup',
            default             => $eventType,
        };
    }

    private static function duration(int $seconds): string
    {
        if ($seconds <= 0) {
            return '0:00';
        }
        if ($seconds < 3600) {
            return sprintf('%d:%02d', intdiv($seconds, 60), $seconds % 60);
        }

        return sprintf('%d:%02d:%02d', intdiv($seconds, 3600), intdiv($seconds % 3600, 60), $seconds % 60);
    }

    /** @return array<int, array<string, mixed>> newest session first */
    public function recent(int $limit = 60): array
    {
        $tenant = Tenant::where('is_platform', true)->first();
        if (! $tenant) {
            return [];
        }

        $tz = $tenant->timezone ?? config('app.timezone', 'UTC');

        $events = DB::table('tenant_funnel_events')
            ->where('tenant_id', $tenant->id)
            ->where('created_at', '>=', $this->start)
            ->where('created_at', '<',  $this->end)
            ->orderBy('created_at')
            ->limit(self::EVENT_LIMIT)
            ->get(['session_id', 'event_type', 'path', 'device', 'referrer_domain', 'utm_source', 'created_at']);

        $sessions = [];

        foreach ($events as $e) {
            $sid = $e->session_id ?: 'unknown';
            $at  = CarbonImmutable::parse($e->created_at);

            if (! isset($sessions[$sid])) {
                $sessions[$sid] = [
                    'id'        => $sid,
                    'session'   => strlen($sid) > 8 ? substr($sid, 0, 4) . '…' . substr($sid, -3) : $sid,
                    'first_at'  => $at,
                    'last_at'   => $at,
                    'device'    => $e->device,
                    'referrer'  => $e->referrer_domain,
                    'utm'       => $e->utm_source,
                    'landing'   => null,
                    'pages'     => [],
                    'converted' => false,
                    'timeline'  => [],
                ];
            }

            $s = &$sessions[$sid];

            if ($at->greaterThan($s['last_at'])) {
                $s['last_at'] = $at;
            }
            // First non-empty wins — a later event may not carry these.
            if ($e->device && ! $s['device'])                   { $s['device']   = $e->device; }
            if ($e->referrer_domain && ! $s['referrer'])        { $s['referrer'] = $e->referrer_domain; }
            if ($e->utm_source && ! $s['utm'])                  { $s['utm']      = $e->utm_source; }

            if ($e->event_type === 'page_view') {
                $path = $e->path ?: '/';
                $s['landing'] ??= $path;
                $s['pages'][] = $path;
            }

            if (in_array($e->event_type, ['quiz_completed', 'contact_submitted', 'signup_completed'], true)) {
                $s['converted'] = true;
            }

            $s['timeline'][] = [
                'at'   => $at->setTimezone($tz)->format('g:i:s A'),
                'what' => self::label($e->event_type, $e->path),
            ];

            unset($s);
        }

        $out = [];
        foreach ($sessions as $s) {
            $pageCount = count($s['pages']);
            $seconds   = $s['last_at']->diffInSeconds($s['first_at']);

            $out[] = [
                'session'    => $s['session'],
                'time'       => $s['first_at']->setTimezone($tz)->format('g:i A'),
                'day'        => $s['first_at']->setTimezone($tz)->format('M j'),
                'sort'       => $s['first_at']->getTimestamp(),
                'landing'    => $s['landing'] ?? '—',
                'page_count' => $pageCount,
                'pages'      => $s['pages'],
                'duration'   => self::duration($seconds),
                'seconds'    => $seconds,
                'device'     => $s['device'],
                'referrer'   => $s['referrer'],
                'utm'        => $s['utm'],
                'status'     => $s['converted']
                    ? 'converted'
                    : ($pageCount > 1 ? 'browsed' : 'bounced'),
                'timeline'   => $s['timeline'],
            ];
        }

        usort($out, fn ($a, $b) => $b['sort'] <=> $a['sort']);

        return array_slice($out, 0, $limit);
    }
}
EOF
echo "ok   sessions service created"; fi

# ================================================================ page data
python3 - "$PAGE" <<'PY'
import sys
path = sys.argv[1]
src = open(path).read()

def edit(old, new, label):
    global src
    n = src.count(old)
    if n != 1:
        print(f"FAIL {label}: anchor found {n} times"); sys.exit(1)
    src = src.replace(old, new, 1)
    print(f"ok   {label}")

edit("""use App\\Services\\Platform\\SignupFunnelService;""",
"""use App\\Services\\Platform\\MarketingSessionsService; // MARKER-MKTSESSIONS
use App\\Services\\Platform\\SignupFunnelService;""",
"page import")

edit("""            'stages'     => $funnel->stages(),
            'intent'     => $funnel->intent(),""",
"""            'stages'     => $funnel->stages(),
            'intent'     => $funnel->intent(),
            'sessions'   => (new MarketingSessionsService( // MARKER-MKTSESSIONS
                CarbonImmutable::instance($report->curStart()),
                CarbonImmutable::instance($report->curEnd())
            ))->recent(),""",
"page sessions data")

open(path, 'w').write(src)
PY

# ================================================================ view
python3 - "$VIEW" <<'PY'
import sys
path = sys.argv[1]
src = open(path).read()

anchor = "@endif\n</x-filament-panels::page>"
n = src.count(anchor)
if n != 1:
    print(f"FAIL sessions panel: anchor found {n} times"); sys.exit(1)

panel = '''{{-- MARKER-MKTSESSIONS — per-session explorer. Click a row to expand. --}}
<style>
.ms-row{border-radius:10px;background:rgba(255,255,255,.04);margin-bottom:6px;overflow:hidden}
.ms-row summary{display:flex;align-items:center;gap:12px;padding:10px 14px;cursor:pointer;list-style:none}
.ms-row summary::-webkit-details-marker{display:none}
.ms-time{font-size:12px;opacity:.6;width:78px;flex:0 0 auto}
.ms-time span{display:block;font-size:10.5px;opacity:.6}
.ms-land{flex:1;min-width:0;font-size:13px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.ms-meta{font-size:11.5px;opacity:.5;flex:0 0 auto}
.ms-dur{font-variant-numeric:tabular-nums;font-size:12.5px;opacity:.7;width:60px;text-align:right;flex:0 0 auto}
.ms-badge{font-size:10.5px;font-weight:700;letter-spacing:.04em;text-transform:uppercase;
  padding:3px 8px;border-radius:99px;flex:0 0 auto}
.ms-badge.converted{background:rgba(120,200,110,.18);color:#8fd082}
.ms-badge.browsed{background:rgba(255,255,255,.10);opacity:.8}
.ms-badge.bounced{background:rgba(255,255,255,.06);opacity:.45}
.ms-detail{padding:4px 14px 14px 92px;font-size:12.5px}
.ms-dmeta{display:flex;gap:14px;flex-wrap:wrap;opacity:.5;font-size:11.5px;margin-bottom:8px}
.ms-ev{display:flex;gap:12px;padding:3px 0}
.ms-ev .t{opacity:.45;font-variant-numeric:tabular-nums;width:82px;flex:0 0 auto}
.ms-note{font-size:11.5px;opacity:.4;margin:8px 0 14px;line-height:1.5}
</style>

<div class="mt-sec">Sessions</div>
<div class="ms-note">
  Newest first. Duration is first event to last, so a single-page visit reads 0:00 however long it was read &mdash;
  and &ldquo;pages&rdquo; means pages visited, not in-page clicks.
</div>

@forelse($sessions as $sess)
  <details class="ms-row">
    <summary>
      <span class="ms-time">{{ $sess['time'] }}<span>{{ $sess['day'] }}</span></span>
      <span class="ms-land">{{ $sess['landing'] }}</span>
      <span class="ms-meta">{{ $sess['page_count'] }} {{ \\Illuminate\\Support\\Str::plural('page', $sess['page_count']) }}</span>
      @if($sess['device'])<span class="ms-meta">{{ $sess['device'] }}</span>@endif
      <span class="ms-dur">{{ $sess['duration'] }}</span>
      <span class="ms-badge {{ $sess['status'] }}">{{ $sess['status'] }}</span>
    </summary>
    <div class="ms-detail">
      <div class="ms-dmeta">
        <span>Session {{ $sess['session'] }}</span>
        @if($sess['referrer'])<span>From {{ $sess['referrer'] }}</span>@endif
        @if($sess['utm'])<span>utm_source {{ $sess['utm'] }}</span>@endif
      </div>
      @foreach($sess['timeline'] as $ev)
        <div class="ms-ev"><span class="t">{{ $ev['at'] }}</span><span>{{ $ev['what'] }}</span></div>
      @endforeach
    </div>
  </details>
@empty
  <div class="mt-empty">No sessions in this window yet.</div>
@endforelse

@endif
</x-filament-panels::page>'''

src = src.replace(anchor, panel, 1)
print("ok   sessions panel")

open(path, 'w').write(src)
PY

php -l "$SVC"
php -l "$PAGE"

echo ""
echo "SUCCESS — apply-marketing-sessions-explorer applied."
echo "Works on rows already collected — no new tracking needed."
