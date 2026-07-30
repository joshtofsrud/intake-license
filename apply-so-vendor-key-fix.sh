#!/bin/bash
# so-vendor-key-fix — adding an item to a special order from the register 500s.
#
#   SpecialOrderService::create() builds the row with
#       'vendor_id'            => $data['vendor_id'] ?? ($auto['vendor_id'] ?? null),
#       'vendor_assigned_rule' => $data['vendor_id'] ? 'manual' : ...
#   The first line is null-safe, the one directly under it is not. The
#   register's add-to-SO call omits vendor_id entirely — it has no vendor to
#   name, that's the whole point of the auto-vendor rule — so PHP raises
#   "Undefined array key vendor_id" and the request fails.
#
#   Every other read of this key in the method uses empty(), which tolerates
#   a missing key; this single bare read was the exception. Caught the night
#   before the first tenant goes live.
#
#   Behaviour is unchanged when a vendor IS named: a supplied vendor_id still
#   marks the rule 'manual'. A missing one now falls through to the auto rule
#   instead of throwing.
# NO MIGRATION. Server: optimize:clear.
set -e
if grep -q "MARKER-SO-VENDOR-KEY" app/Services/Tenant/SpecialOrderService.php; then
  echo "so-vendor-key-fix already applied — aborting."; exit 1
fi

python3 - <<'SVK_0_EOF'
import io
p = 'app/Services/Tenant/SpecialOrderService.php'
s = io.open(p, encoding='utf-8').read()

old = """                'vendor_assigned_rule'      => $data['vendor_id'] ? 'manual' : ($auto['rule'] ?? null),"""
assert s.count(old) == 1, s.count(old)

new = """                // MARKER-SO-VENDOR-KEY \u2014 null-coalesce. The register creates an
                // SO without naming a vendor (that is what the auto rule is
                // for), and a bare $data['vendor_id'] threw "Undefined array
                // key" on every one of those.
                'vendor_assigned_rule'      => ($data['vendor_id'] ?? null) ? 'manual' : ($auto['rule'] ?? null),"""

io.open(p, 'w', encoding='utf-8').write(s.replace(old, new))
print('vendor_id key fix ok')
SVK_0_EOF

# Any other bare reads of the same key in this file?
echo "--- remaining bare \$data['vendor_id'] reads (expect none) ---"
grep -n "\$data\['vendor_id'\]" app/Services/Tenant/SpecialOrderService.php \
  | grep -v "??" | grep -v "empty(" || echo "none"

php -l app/Services/Tenant/SpecialOrderService.php

echo
echo "so-vendor-key-fix applied."
