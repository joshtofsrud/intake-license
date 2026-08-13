#!/bin/bash
# apply-marketing-sessions-duration-bot-fix.sh
#
# MARKER-MKTBOTFIX — two defects in the master-admin marketing sessions
# tracker (MARKER-MKTSESSIONS), both mine:
#
#  1. DURATION ALWAYS READ 0:00. recent() computed
#         $s['last_at']->diffInSeconds($s['first_at'])
#     with the arguments backwards. Carbon 3 (this app runs nesbot/carbon
#     3.11.4) returns a SIGNED diff, so last->first is negative, and the
#     formatter floors anything <= 0 to '0:00'. Every session on the page
#     showed 0:00 — including genuine multi-page visits, which is why a
#     12-page browse looked like a one-second bot hit. The tenant-side
#     explorer got this right (first_at->diffForHumans(last_at, ABSOLUTE)),
#     which is why only the master page looked broken. Fixed to
#     first->last, cast to int (Carbon 3 returns a float).
#
#  2. BOT ROWS WERE BEING STORED. The tenant tracker classifies the device
#     server-side and returns early on 'bot' (FunnelTrackController), so
#     crawler hits never reach tenant_funnel_events. The marketing endpoint
#     had no such skip and trusted the client's own device string, so every
#     crawler pass wrote a row per page — and because the marketing session
#     id lives in sessionStorage, each of those pages got a fresh id and
#     surfaced as its own 1-page session. Now classified server-side from
#     the User-Agent and skipped, same as the tenant side. Rows already in
#     the table are excluded from the sessions list by a query filter.
#
# NOT in this patch (called out rather than silently bundled):
#   - The stat tiles and daily-visitors chart on the same page come from
#     TrafficReportService, which counts distinct sessions without a bot
#     filter. That is harmless for real tenants (bots never got written)
#     but means historical bot rows still inflate the platform's visitor
#     numbers. Fixing that touches a shared tenant service, so it needs
#     its own decision.
#   - The marketing session id still lives in sessionStorage rather than a
#     server cookie like the tenant tracker's. Non-bot clients that block
#     storage still fragment (they land as the literal id 'nostore').
set -e

MARKER="MARKER-MKTBOTFIX"
SVC="app/Services/Platform/MarketingSessionsService.php"
CTL="app/Http/Controllers/Platform/MarketingFunnelController.php"

for f in "$SVC" "$CTL"; do
  [ -f "$f" ] || { echo "ERROR: missing $f — run from the repo root"; exit 1; }
done
if grep -q "$MARKER" "$SVC" 2>/dev/null; then
  echo "ok: already applied ($MARKER present) — no-op"
  exit 0
fi

python3 - <<'PY'
import io

# ---------------------------------------------------------------
# 1. Sessions service: duration direction + bot exclusion
# ---------------------------------------------------------------
p = 'app/Services/Platform/MarketingSessionsService.php'
src = io.open(p, encoding='utf-8').read()

a = "            $seconds   = $s['last_at']->diffInSeconds($s['first_at']);"
assert src.count(a) == 1, 'duration line not found'
src = src.replace(a, """            // MARKER-MKTBOTFIX -- first -> last. Carbon 3 diffs are SIGNED,
            // so the old last -> first returned a negative and every session
            // rendered as 0:00. Cast: Carbon 3 returns a float.
            $seconds   = (int) $s['first_at']->diffInSeconds($s['last_at']);""", 1)

b = """            ->where('created_at', '>=', $this->start)
            ->where('created_at', '<',  $this->end)
            ->orderBy('created_at')"""
assert src.count(b) == 1, 'event query not found'
src = src.replace(b, """            ->where('created_at', '>=', $this->start)
            ->where('created_at', '<',  $this->end)
            // MARKER-MKTBOTFIX -- rows written before the ingest-side skip
            // existed. Filtering here (not in PHP) also means EVENT_LIMIT is
            // spent on real traffic instead of crawler noise.
            ->where(function ($w) {
                $w->whereNull('device')->orWhere('device', '!=', 'bot');
            })
            ->orderBy('created_at')""", 1)

io.open(p, 'w', encoding='utf-8').write(src)
print('ok: duration fixed + bot rows excluded from the sessions list')

# ---------------------------------------------------------------
# 2. Ingest: classify device server-side, skip bots
# ---------------------------------------------------------------
p2 = 'app/Http/Controllers/Platform/MarketingFunnelController.php'
s2 = io.open(p2, encoding='utf-8').read()

c = """        self::record($data['event_type'], $data);

        return response()->json(['ok' => true]);
    }
"""
assert s2.count(c) == 1, 'store() tail not found'
s2 = s2.replace(c, """        // MARKER-MKTBOTFIX -- classify from the User-Agent server-side (the
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
""", 1)

io.open(p2, 'w', encoding='utf-8').write(s2)
print('ok: bot traffic skipped at the marketing ingest endpoint')
PY

echo ""
echo "== marketing sessions duration + bot fix applied =="
echo "Post-deploy: php artisan optimize:clear"
