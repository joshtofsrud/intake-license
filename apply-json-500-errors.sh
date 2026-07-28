#!/bin/bash
# json-500-errors — stop server faults from presenting as "network error".
#   The 500 handler in bootstrap/app.php always returned the errors.500 HTML
#   view, including for AJAX. The register's fetch calls then choke on
#   res.json(), fall into catch, and report "Network error ..." — which is
#   how a hard 500 in discardDraft sat unnoticed for five days.
#   Requests that expect JSON now get a JSON body carrying the same ref id
#   that goes into the log:
#     {"ok":false,"error":"Something went wrong on our end (ERR-XXXXXXXX)...",
#      "ref_id":"ERR-XXXXXXXX"}
#   The `ok:false` shape is what the register already checks, so five of the
#   seven "Network error" sites start showing the real message and ref id
#   with no client change. Browser requests still get the HTML page.
#   Deliberately unchanged: the message stays generic. The ref id is the
#   handle for the log; exception text does not go to a shop's screen.
# NO MIGRATION. Server: optimize:clear.
set -e
if grep -q "MARKER-JSON-500" bootstrap/app.php; then
  echo "json-500-errors already applied — aborting."; exit 1
fi

python3 - <<'J500_0_EOF'
import io
p = 'bootstrap/app.php'
s = io.open(p, encoding='utf-8').read()

old = """            return response()->view('errors.500', [
                'errorRefId' => $refId,
                'exception'  => $e,
            ], 500);"""
assert s.count(old) == 1, s.count(old)

new = """            // MARKER-JSON-500 \u2014 AJAX callers get JSON, not the HTML page.
            // Without this every fetch() in the app treats a server fault as
            // a network failure, because res.json() throws on an HTML body.
            // Shape matches what the register already reads: ok:false + error.
            if ($request->expectsJson() || $request->ajax()) {
                return response()->json([
                    'ok'     => false,
                    'error'  => \"Something went wrong on our end ({$refId}). Nothing was saved \u2014 try again, and quote that code if it keeps happening.\",
                    'ref_id' => $refId,
                ], 500);
            }

            return response()->view('errors.500', [
                'errorRefId' => $refId,
                'exception'  => $e,
            ], 500);"""

io.open(p, 'w', encoding='utf-8').write(s.replace(old, new))
print('json 500 handler ok')
J500_0_EOF

php -l bootstrap/app.php

echo
echo "json-500-errors applied."
