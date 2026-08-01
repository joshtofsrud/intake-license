#!/usr/bin/env bash
# apply-bti-stream-probe.sh
# MARKER-BTI-STREAM-PROBE — stop downloading the whole feed to read 400 bytes.
#
# testConnection() calls Http::get() and then inspects substr($body, 0, 400).
# Laravel buffers the entire response before body() returns, so the button
# pulls the complete light feed every press. That is the wait.
#
# NOT going back to Range. The comment already in this method records why:
# BTI answers `Range: bytes=0-2047` with a 503, which surfaced as a rejected
# credential while the very same credentials synced fine. A slow button is a
# far better failure than one that lies about your login.
#
# Streaming asks for the same ordinary request and simply stops reading once
# it has enough to judge — BTI never sees anything unusual.
#
# FALLBACK IS THE POINT: if the stream yields nothing (a proxy or BTI itself
# buffering the body before the first byte reaches us) the old buffered read
# runs instead. This must not trade a slow-but-honest probe for a fast one
# that reports failures that aren't real. The fallback logs, so if it starts
# firing every time you'll know streaming isn't working in your environment
# rather than wondering why it's still slow.
#
# HLC is unaffected — it probes System/Echo, which is already cheap. BTI is
# the only adapter without a lightweight endpoint.
#
# ONE THING TO WATCH: hanging up mid-response can show up in a distributor's
# logs as an aborted request. Worth a glance at the BTI portal after a few
# presses if they surface that sort of thing.
#
# Service change: optimize:clear + fpm cycle.
set -e

python3 <<'PY'
import io

p = 'app/Services/Distributors/BtiClient.php'
s = io.open(p, encoding='utf-8').read()

old = """            $res = Http::withBasicAuth($this->user, $this->pass)
                ->timeout(60)
                ->get($this->base . '/inventory', ['type' => 'json']);

            $body = substr((string) $res->body(), 0, 400);"""
assert s.count(old) == 1, 'B1 probe request anchor'
s = s.replace(old, """            // MARKER-BTI-STREAM-PROBE — same request, we just stop reading
            // once we have enough to judge. body() would buffer the whole
            // feed first, which is what made this button take so long.
            $res = Http::withBasicAuth($this->user, $this->pass)
                ->withOptions(['stream' => true])
                ->timeout(30)
                ->get($this->base . '/inventory', ['type' => 'json']);

            $head = $this->readHead($res, 2048, 10);

            // Empty head on a good status means something between us and BTI
            // buffered the body. Fall back rather than call a working
            // connection broken.
            if ($head === '' && $res->successful()) {
                Log::info('bti.probe_stream_empty_fallback');
                $res  = Http::withBasicAuth($this->user, $this->pass)
                    ->timeout($this->timeout > 0 ? $this->timeout : 60)
                    ->get($this->base . '/inventory', ['type' => 'json']);
                $head = (string) $res->body();
            }

            $body = substr($head, 0, 400);""")

# helper, placed just before the feed section
old = """    // ---------------------------------------------------------------- feed"""
assert s.count(old) == 1, 'B2 feed section anchor'
s = s.replace(old, """    /**
     * MARKER-BTI-STREAM-PROBE — read the first bytes off a streamed response
     * and hang up.
     *
     * Returns '' on any trouble so the caller can fall back to a buffered
     * read; a probe that reports a false failure is worse than a slow one.
     */
    private function readHead(\\Illuminate\\Http\\Client\\Response $res, int $bytes, int $seconds): string
    {
        try {
            $stream   = $res->toPsrResponse()->getBody();
            $head     = '';
            $deadline = microtime(true) + $seconds;

            while (strlen($head) < $bytes && ! $stream->eof() && microtime(true) < $deadline) {
                $chunk = $stream->read($bytes - strlen($head));
                if ($chunk === '') {
                    break; // nothing more coming right now
                }
                $head .= $chunk;
            }

            // We have what we need — drop the connection instead of pulling
            // the remaining megabytes.
            $stream->close();

            return $head;
        } catch (\\Throwable $e) {
            Log::info('bti.probe_stream_failed', ['error' => $e->getMessage()]);

            return '';
        }
    }

    // ---------------------------------------------------------------- feed""")

io.open(p, 'w', encoding='utf-8').write(s)
print('patched', p)
PY

echo "--- probe now reads ---"
sed -n '/public function testConnection/,/^    }$/p' app/Services/Distributors/BtiClient.php | grep -n "stream\|readHead\|timeout\|body()\|looksLikeData\|fallback"

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
echo "apply-bti-stream-probe: OK"
