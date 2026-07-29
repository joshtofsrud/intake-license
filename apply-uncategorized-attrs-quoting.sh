#!/bin/bash
# uncategorized-attrs-quoting — row data survives values containing apostrophes.
#
#   Rows carried their attribute values as data-attrs='@json($it->_attrs)' —
#   single-quoted. HLC writes inches as two apostrophes, so a tire value like
#   26''x2.30 closes the attribute early and the rest of the JSON spills out
#   as garbage attributes on the tag. Every row with an inch measurement was
#   malformed, which is why the value column came back empty and the chip row
#   never drew.
#
#   Blade's @json escapes for a JS context, not for an HTML attribute
#   delimited by single quotes. {{ }} runs htmlspecialchars with ENT_QUOTES,
#   which turns both ' and " into entities, so the value can contain either
#   without breaking the tag. Double-quoted attribute plus {{ }} is the
#   combination that holds.
#
#   Same treatment for the two script-scope blobs. @json is already correct
#   there — a <script> body is not an HTML attribute — so those are left
#   alone deliberately rather than changed for symmetry.
# NO MIGRATION. Server: view:clear.
set -e
if grep -q "MARKER-ATTRS-QUOTING" resources/views/tenant/inventory/uncategorized.blade.php; then
  echo "uncategorized-attrs-quoting already applied — aborting."; exit 1
fi

python3 - <<'UAQ_0_EOF'
import io
p = 'resources/views/tenant/inventory/uncategorized.blade.php'
s = io.open(p, encoding='utf-8').read()

old = """                <tr class="uc-row" data-attrs='@json($it->_attrs)' style="border-top:.5px solid var(--ia-border)">"""
assert s.count(old) == 1, s.count(old)

new = """                {{-- MARKER-ATTRS-QUOTING — double-quoted with {{ }} escaping. This was
                     single-quoted @json, and HLC writes inches as two apostrophes
                     (26''x2.30), which closed the attribute early and mangled the
                     tag on every tire row. --}}
                <tr class="uc-row" data-attrs="{{ json_encode($it->_attrs, JSON_UNESCAPED_SLASHES) }}" style="border-top:.5px solid var(--ia-border)">"""

io.open(p, 'w', encoding='utf-8').write(s.replace(old, new))
print('data-attrs quoting ok')
UAQ_0_EOF

echo
echo "uncategorized-attrs-quoting applied."
