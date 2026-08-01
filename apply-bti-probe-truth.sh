#!/usr/bin/env bash
# apply-bti-probe-truth.sh
# MARKER-BTI-PROBE-TRUTH — correct the record, and fix a timeout that's too
# tight to be safe.
#
# Adapts to whichever state BtiClient is in:
#
#   * If MARKER-BTI-STREAM-PROBE shipped, ->timeout(30) is raised to 90.
#     The full request measures 28.4s, so 30 leaves four seconds of
#     headroom — one slow day at BTI and the probe throws, gets swallowed
#     by the catch, and reports a failed connection on good credentials.
#     The code it replaced allowed 60. Making a probe LESS reliable to save
#     3.6 seconds is a bad trade, and that was my error when writing it.
#
#   * Either way, the MARKER-BTI-PROBE comment is rewritten. It claims BTI
#     answers `Range: bytes=0-2047` with a 503. Measured on the production
#     server, that is false:
#
#         range      200, 3,848,122 bytes, ~22s
#         no range   200, 3,848,122 bytes, ~22s
#         HEAD       200, ~19.5s
#         ttfb       24.8s of a 28.4s total
#
#     BTI ignores Range rather than rejecting it, and renders the whole
#     feed before sending a byte. The original 503 was almost certainly an
#     outage misread as a Range rejection — which then justified a comment
#     that sent the next reader (me) down the wrong path entirely.
#
# Service change: optimize:clear + fpm cycle.
set -e

python3 <<'PY'
import io

p = 'app/Services/Distributors/BtiClient.php'
s = io.open(p, encoding='utf-8').read()

streaming = 'MARKER-BTI-STREAM-PROBE' in s
print('streaming probe present:', streaming)

# ---- 1. the comment, present in both states
old = """            // MARKER-BTI-PROBE — no Range header.
            //
            // This sent `Range: bytes=0-2047` so a connection check wouldn't
            // pull the whole feed. BTI answers that with 503, which read as a
            // rejected credential — while the very same credentials synced
            // fine from master admin, because the sync sends no Range. The
            // light feed is stock and prices only and is far smaller than the
            // catalog, which is why it's the one used here."""
assert s.count(old) == 1, 'P1 range comment anchor'
s = s.replace(old, """            // MARKER-BTI-PROBE-TRUTH — measured, not inferred (Aug 1, from the
            // production server):
            //
            //     range      200, 3,848,122 bytes, ~22s
            //     no range   200, 3,848,122 bytes, ~22s
            //     HEAD       200, ~19.5s
            //     ttfb       24.8s of a 28.4s total
            //
            // The previous note here said BTI answers Range with a 503. It
            // does not — it IGNORES Range and sends the whole body. That 503
            // was almost certainly an outage misread as a Range rejection.
            //
            // What the ttfb line means: BTI renders the entire feed before
            // sending a byte, so NOTHING client-side makes this fast. Range,
            // HEAD and streaming all still wait ~25s for generation. An
            // authenticated request to a nonexistent path returns in 0.49s
            // but 404s for bad credentials too, so it proves nothing.
            //
            // There is no cheap authenticated endpoint. Don't go looking
            // again — set expectations in the UI instead. The light feed
            // (stock and prices) is what's used here; the full catalog is
            // an order of magnitude bigger.""")

# ---- 2. the timeout, only if the streaming probe shipped
if streaming:
    old = """                ->withOptions(['stream' => true])
                ->timeout(30)"""
    assert s.count(old) == 1, 'P2 stream timeout anchor'
    s = s.replace(old, """                ->withOptions(['stream' => true])
                // MARKER-BTI-PROBE-TRUTH — was 30, against a measured 28.4s
                // round trip. Four seconds of headroom meant a slow day at
                // BTI surfaced as a failed connection test on good
                // credentials. Streaming saves ~3.6s here; it is not worth
                // buying that with false negatives.
                ->timeout(90)""")
    print('  timeout raised 30 -> 90')
else:
    print('  no streaming probe — comment corrected only')

io.open(p, 'w', encoding='utf-8').write(s)
print('patched', p)
PY

echo
echo "--- timeouts in the probe ---"
sed -n '/public function testConnection/,/^    }$/p' app/Services/Distributors/BtiClient.php | grep -n "timeout\|MARKER"

echo
echo "--- balance ---"
python3 - <<'PY'
import io
s = io.open('app/Services/Distributors/BtiClient.php', encoding='utf-8').read()
i, n, d, par = 0, len(s), 0, 0
while i < n:
    c = s[i]
    if c == '#' or (c == '/' and i+1 < n and s[i+1] == '/'):
        while i < n and s[i] != '\n': i += 1
    elif c == '/' and i+1 < n and s[i+1] == '*':
        i += 2
        while i+1 < n and not (s[i] == '*' and s[i+1] == '/'): i += 1
        i += 2
    elif c in '"\'':
        q = c; i += 1
        while i < n and s[i] != q:
            if s[i] == '\\': i += 1
            i += 1
        i += 1
    else:
        if c == '{': d += 1
        elif c == '}': d -= 1
        elif c == '(': par += 1
        elif c == ')': par -= 1
        i += 1
print('braces', d, 'parens', par)
PY

echo
echo "apply-bti-probe-truth: OK"
