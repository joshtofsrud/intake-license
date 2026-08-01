#!/bin/bash
# bti-probe-and-status — 503 is not a rejected credential.
#
#   Testing BTI returns 503 with the SAME credentials that sync fine from
#   master admin. The only difference between the two paths is my probe:
#   testConnection() sends `Range: bytes=0-2047` so a connection check
#   wouldn't pull 43 MB. BTI doesn't serve that and answers 503. The
#   credentials were never the problem.
#
#   The probe now asks for the light feed with no Range header — the same
#   request the sync makes, just the small one — and reads only what the HTTP
#   client has already buffered. The light feed is stock and prices only, so
#   it is far smaller than the catalog, which is why it was chosen for the
#   probe in the first place.
#
#   Second fix, and the more important one: EVERY failure was recorded as
#   `auth_failed`, so a distributor being down, a DNS failure or a 503 all
#   displayed as "credentials rejected" — sending you to re-enter a password
#   that was correct. Status now distinguishes: auth_failed only for 401/403,
#   `unreachable` otherwise, and the message carries the real code.
# NO MIGRATION. Server: optimize:clear
set -e
if grep -q "MARKER-BTI-PROBE" app/Services/Distributors/BtiClient.php; then
  echo "bti-probe-and-status already applied — aborting."; exit 1
fi

# ------------------------------------------------------------------ probe
python3 - <<'BPS_0_EOF'
import io
p = 'app/Services/Distributors/BtiClient.php'
s = io.open(p, encoding='utf-8').read()

old = """            $res = Http::withBasicAuth($this->user, $this->pass)
                ->timeout(30)
                ->withHeaders(['Range' => 'bytes=0-2047'])
                ->get($this->base . '/inventory', ['type' => 'json']);"""
assert s.count(old) == 1, ('probe', s.count(old))
new = """            // MARKER-BTI-PROBE — no Range header.
            //
            // This sent `Range: bytes=0-2047` so a connection check wouldn't
            // pull the whole feed. BTI answers that with 503, which read as a
            // rejected credential — while the very same credentials synced
            // fine from master admin, because the sync sends no Range. The
            // light feed is stock and prices only and is far smaller than the
            // catalog, which is why it's the one used here.
            $res = Http::withBasicAuth($this->user, $this->pass)
                ->timeout(60)
                ->get($this->base . '/inventory', ['type' => 'json']);"""
s = s.replace(old, new)

old = """            return [
                'ok'     => $res->successful() && $looksLikeData,
                'status' => $res->status(),
                'body'   => $looksLikeData ? 'feed reachable' : $body,
            ];"""
assert s.count(old) == 1, ('probe return', s.count(old))
new = """            return [
                'ok'     => $res->successful() && $looksLikeData,
                'status' => $res->status(),
                // 401/403 means the credentials are wrong. Anything else
                // means BTI didn't serve us — a different problem with a
                // different fix.
                'auth'   => in_array($res->status(), [401, 403], true),
                'body'   => $looksLikeData ? 'feed reachable' : $body,
            ];"""
s = s.replace(old, new)

io.open(p, 'w', encoding='utf-8').write(s)
print('probe ok')
BPS_0_EOF

# ------------------------------------------------------- honest status write
python3 - <<'BPS_1_EOF'
import io
p = 'app/Http/Controllers/Tenant/DistributorController.php'
s = io.open(p, encoding='utf-8').read()

old = """            $sub->last_sync_status = $ok ? 'connected' : 'auth_failed';
            $sub->save();"""
assert s.count(old) == 1, ('status write', s.count(old))
new = """            // MARKER-BTI-PROBE — every failure used to be recorded as
            // auth_failed, so a 503, a timeout or DNS trouble all displayed as
            // "credentials rejected" and sent someone to re-enter a password
            // that was already correct.
            $status = (int) ($res['status'] ?? 0);
            $isAuth = $res['auth'] ?? in_array($status, [401, 403], true);

            $sub->last_sync_status = $ok
                ? 'connected'
                : ($isAuth ? 'auth_failed' : 'unreachable');
            $sub->save();"""
s = s.replace(old, new)

old = """                    : ($label . ' rejected the credentials (HTTP ' . ($res['status'] ?? '?') . ').'));"""
assert s.count(old) == 1, ('message', s.count(old))
new = """                    : ($isAuth
                        ? ($label . ' rejected the credentials (HTTP ' . $status . ').')
                        : ('Could not reach ' . $label . ' \u2014 it answered HTTP ' . $status
                           . '. Your credentials look fine; try again shortly.')));"""
s = s.replace(old, new)

io.open(p, 'w', encoding='utf-8').write(s)
print('status ok')
BPS_1_EOF

# ------------------------------------------------------------------ label
python3 - <<'BPS_2_EOF'
import io
p = 'resources/views/tenant/distributors/connection.blade.php'
s = io.open(p, encoding='utf-8').read()

old = """          @elseif ($st === 'auth_failed')
            <span style="color:#E24B4A">credentials rejected</span><br>"""
assert s.count(old) == 1, ('label', s.count(old))
new = """          @elseif ($st === 'auth_failed')
            <span style="color:#E24B4A">credentials rejected</span><br>
          @elseif ($st === 'unreachable')
            {{-- MARKER-BTI-PROBE — not a credential problem. --}}
            <span style="color:var(--ia-warn,#D9A441)">couldn't reach it</span><br>"""
s = s.replace(old, new)

io.open(p, 'w', encoding='utf-8').write(s)
print('label ok')
BPS_2_EOF

echo
echo "bti-probe-and-status applied."
