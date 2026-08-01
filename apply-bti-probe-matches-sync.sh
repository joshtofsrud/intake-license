#!/bin/bash
# bti-probe-matches-sync — test the endpoint we actually use.
#
#   The probe returns 503 with credentials that sync fine. Reason: it asks
#   for `?type=json`, the JSON light feed — and nothing else in the adapter
#   ever touches that. rows() fetches the light feed with NO query params at
#   all, which is the CSV variant, exactly as it does for the full feed.
#
#   So the probe has been testing an endpoint we don't use, and BTI is
#   currently 503ing that one. Confirmed from Josh's own machine: the same
#   URL returns 503 to curl, so it isn't our request shape.
#
#   A connection test whose request differs from the real one can fail while
#   the feature works, and pass while the feature is broken. It now makes the
#   same request rows() makes.
#
#   Detection changes with it: a CSV response is checked for the header row
#   rather than a JSON opening bracket.
# NO MIGRATION. Server: optimize:clear
set -e
if grep -q "MARKER-PROBE-MATCHES-SYNC" app/Services/Distributors/BtiClient.php; then
  echo "bti-probe-matches-sync already applied — aborting."; exit 1
fi

python3 - <<'PMS_0_EOF'
import io
p = 'app/Services/Distributors/BtiClient.php'
s = io.open(p, encoding='utf-8').read()

old = """            $res = Http::withBasicAuth($this->user, $this->pass)
                ->timeout(60)
                ->get($this->base . '/inventory', ['type' => 'json']);

            $body = substr((string) $res->body(), 0, 400);

            // BTI answers a bad login with an HTML page and a 200 in some
            // paths, so status alone is not proof.
            $looksLikeData = str_starts_with(ltrim($body), '[')
                || str_starts_with(ltrim($body), '{')
                || str_contains($body, 'vendor_item_id');"""
assert s.count(old) == 1, ('probe body', s.count(old))

new = """            // MARKER-PROBE-MATCHES-SYNC — the SAME request rows() makes.
            //
            // This asked for ?type=json, the JSON light feed, which nothing
            // else in this adapter uses — rows() fetches the light feed with
            // no query params, i.e. the CSV variant. BTI is currently 503ing
            // the JSON one, so the probe failed while the feature it was
            // meant to check was fine. A test whose request differs from the
            // real request can fail on a working integration and pass on a
            // broken one.
            $res = Http::withBasicAuth($this->user, $this->pass)
                ->timeout(60)
                ->get($this->base . '/inventory');

            $body = substr((string) $res->body(), 0, 400);

            // CSV now, so look for the header row. BTI answers a bad login
            // with an HTML page and sometimes a 200, so status alone is not
            // proof either way.
            $looksLikeData = str_contains($body, 'vendor_item_id')
                || str_contains($body, 'available_santa_fe')
                || str_starts_with(ltrim($body), '[');"""
s = s.replace(old, new)

io.open(p, 'w', encoding='utf-8').write(s)
print('probe ok')
PMS_0_EOF

echo
echo "bti-probe-matches-sync applied."
