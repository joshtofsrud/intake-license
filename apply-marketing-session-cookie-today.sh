#!/bin/bash
# apply-marketing-session-cookie-today.sh
#
# MARKER-MKTSID — two changes to the marketing traffic reporting:
#
#  1. SESSION IDS BECOME COOKIE-BACKED, mirroring the tenant tracker.
#     The marketing id lived only in sessionStorage. That is fine for a
#     normal tab (it survives navigation, which is why real multi-page
#     visits already grouped), but when storage throws the tracker sent the
#     literal string 'nostore' — so EVERY storage-blocked visitor collapsed
#     into one shared session, which is worse than fragmenting. Now:
#       - client sends its id when it has one, no id at all when storage
#         is unavailable (the 'nostore' literal is gone);
#       - server prefers a valid client id (same precedent as the tenant
#         tracker's MARKER-FUNNEL-SESSION-FIX: simultaneous first-visit
#         beacons would otherwise race the cookie and each mint their own),
#         falls back to the mkt_sid cookie, and only then mints one;
#       - the resolved id is written back as a 90-day http-only same-site
#         cookie, so it now also survives a tab close and reopen.
#     NOTE the client id regex is {12,64}, not the tenant's {20,64}: the
#     marketing tracker mints base36 time + random, about 18 chars, so the
#     tenant regex would have rejected every real id. 'nostore' is 7 chars
#     and fails the check anyway — belt and braces.
#
#  2. TODAY WINDOW on the marketing traffic page. TrafficReportService
#     already supports '1d' (MARKER-TRAFFIC-TODAY) — hourly series, 'today'
#     range label — and the view already branches on $daily['hourly'].
#     The page just never allowed the value or offered the chip.
set -e

MARKER="MARKER-MKTSID"
CTL="app/Http/Controllers/Platform/MarketingFunnelController.php"
TRK="resources/views/marketing/_funnel_tracker.blade.php"
PAGE="app/Filament/Pages/MarketingTraffic.php"
VIEW="resources/views/filament/pages/marketing-traffic.blade.php"

for f in "$CTL" "$TRK" "$PAGE" "$VIEW"; do
  [ -f "$f" ] || { echo "ERROR: missing $f — run from the repo root"; exit 1; }
done
if ! grep -q "MARKER-MKTBOTFIX" "$CTL" 2>/dev/null; then
  echo "ERROR: run apply-marketing-sessions-duration-bot-fix.sh first"
  exit 1
fi
if grep -q "$MARKER" "$CTL" 2>/dev/null; then
  echo "ok: already applied ($MARKER present) — no-op"
  exit 0
fi

python3 - <<'PY'
import io

# ---------------------------------------------------------------
# 1a. Ingest: resolve + persist the session id
# ---------------------------------------------------------------
p = 'app/Http/Controllers/Platform/MarketingFunnelController.php'
src = io.open(p, encoding='utf-8').read()

a = "use Illuminate\\Support\\Facades\\DB;"
assert src.count(a) == 1
src = src.replace(a, """use Illuminate\\Support\\Facades\\DB;
use Illuminate\\Support\\Facades\\Cookie; // MARKER-MKTSID
use Illuminate\\Support\\Str;             // MARKER-MKTSID""", 1)

# session_id is no longer required: a storage-blocked client sends none and
# the cookie identifies it instead.
b = "            'session_id'   => ['required', 'string', 'max:64'],"
assert src.count(b) == 1
src = src.replace(b, "            'session_id'   => ['nullable', 'string', 'max:64'], // MARKER-MKTSID", 1)

c = """        $data['device'] = self::deviceFromUserAgent($request->userAgent() ?? '');

        if ($data['device'] === 'bot') {
            return response()->json(['ok' => true, 'skipped' => 'bot']);
        }

        self::record($data['event_type'], $data);

        return response()->json(['ok' => true]);
    }
"""
assert src.count(c) == 1
src = src.replace(c, """        $data['device'] = self::deviceFromUserAgent($request->userAgent() ?? '');

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
     * The client id wins when present and well-formed: several first-visit
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
        $fromPayload = (string) $request->input('session_id', '');
        if ($fromPayload !== '' && preg_match('/^[a-zA-Z0-9]{12,64}$/', $fromPayload)) {
            return $fromPayload;
        }

        $cookie = (string) $request->cookie('mkt_sid', '');
        if ($cookie !== '' && preg_match('/^[a-zA-Z0-9]{12,64}$/', $cookie)) {
            return $cookie;
        }

        return (string) Str::random(40);
    }
""", 1)

io.open(p, 'w', encoding='utf-8').write(src)
print('ok: ingest resolves + sets mkt_sid cookie')

# ---------------------------------------------------------------
# 1b. Client: drop the 'nostore' literal
# ---------------------------------------------------------------
p2 = 'resources/views/marketing/_funnel_tracker.blade.php'
s2 = io.open(p2, encoding='utf-8').read()

d = """  } catch (e) {
    sid = 'nostore';
  }"""
assert s2.count(d) == 1, "'nostore' fallback not found"
s2 = s2.replace(d, """  } catch (e) {
    // MARKER-MKTSID -- storage unavailable: send NO id and let the server's
    // mkt_sid cookie identify this visitor. The old 'nostore' literal made
    // every storage-blocked visitor share one session.
    sid = null;
  }""", 1)

e = "      session_id:   sid,"
assert s2.count(e) == 1
s2 = s2.replace(e, "      session_id:   sid || null, // MARKER-MKTSID", 1)

io.open(p2, 'w', encoding='utf-8').write(s2)
print("ok: client 'nostore' fallback removed")

# ---------------------------------------------------------------
# 2. Today window
# ---------------------------------------------------------------
p3 = 'app/Filament/Pages/MarketingTraffic.php'
s3 = io.open(p3, encoding='utf-8').read()

f = "        if (! in_array($this->window, ['7d', '30d', '90d'], true)) {"
assert s3.count(f) == 1
s3 = s3.replace(f, "        // MARKER-MKTSID -- '1d' is TrafficReportService's existing today window.\n        if (! in_array($this->window, ['1d', '7d', '30d', '90d'], true)) {", 1)
io.open(p3, 'w', encoding='utf-8').write(s3)

p4 = 'resources/views/filament/pages/marketing-traffic.blade.php'
s4 = io.open(p4, encoding='utf-8').read()
g = "  @foreach(['7d' => 'Last 7 days', '30d' => 'Last 30 days', '90d' => 'Last 90 days'] as $wKey => $wLabel)"
assert s4.count(g) == 1
s4 = s4.replace(g, "  {{-- MARKER-MKTSID --}}\n  @foreach(['1d' => 'Today', '7d' => 'Last 7 days', '30d' => 'Last 30 days', '90d' => 'Last 90 days'] as $wKey => $wLabel)", 1)
io.open(p4, 'w', encoding='utf-8').write(s4)
print('ok: Today window allowed + chip added')
PY

echo ""
echo "== marketing session cookie + Today window applied =="
echo "Post-deploy: php artisan optimize:clear"
