#!/bin/bash
# draft-discard-tenant-scope — fixes every draft/quote discard 500ing.
#   SaleService::discardDraft opens DB::transaction(function () use ($sale)).
#   The special-orders sale-link patch (MARKER-SO-SALE-LINK, July 23) added a
#   retraction block inside that closure which queries on $tenantId — a
#   method-scope variable the closure never imported. PHP evaluates the
#   undefined variable as null, Laravel escalates the warning, and the
#   endpoint 500s. Discard has been broken for every tenant since that patch.
#   Fixed with $sale->tenant_id rather than adding $tenantId to the use list:
#   the row being deleted is the authority on its own tenant, so the scope
#   can't drift from the record even if the method signature changes later.
# NO MIGRATION. Server: optimize:clear.
set -e
if grep -q "MARKER-DISCARD-TENANT-SCOPE" app/Services/Tenant/SaleService.php; then
  echo "draft-discard-tenant-scope already applied — aborting."; exit 1
fi

python3 - <<'DDTS_0_EOF'
import io
p = 'app/Services/Tenant/SaleService.php'
s = io.open(p, encoding='utf-8').read()

old = """            $orphans = \\App\\Models\\Tenant\\TenantSpecialOrder::where('tenant_id', $tenantId)"""
assert s.count(old) == 1, s.count(old)
new = """            // MARKER-DISCARD-TENANT-SCOPE \u2014 was $tenantId, which is method
            // scope and was never imported into this closure; the row itself
            // carries the tenant, so read it from there.
            $orphans = \\App\\Models\\Tenant\\TenantSpecialOrder::where('tenant_id', $sale->tenant_id)"""
io.open(p, 'w', encoding='utf-8').write(s.replace(old, new))
print('discardDraft scope ok')
DDTS_0_EOF

php -l app/Services/Tenant/SaleService.php

echo
echo "draft-discard-tenant-scope applied."
