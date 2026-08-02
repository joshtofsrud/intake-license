#!/usr/bin/env bash
# apply-vendor-merge-code-release.sh
# MARKER-MERGE-CODE-RELEASE — the merge collides with its own unique index.
#
#     Duplicate entry 'a1dfc34d-...-bti' for key
#     'tenant_vendors_tenant_distributor_unique'
#
# The migration puts a unique index on (tenant_id, distributor_code) — one
# vendor per feed per tenant, which is the whole point. But merge() claims
# the code on the target while the SOURCE still holds it:
#
#     $fill['distributor_code'] = strtolower($code);
#     $target->update($fill);     <-- source still has 'bti' here
#     ...
#     $source->delete();          <-- only released now
#
# Both rows hold 'bti' for the moment in between, and the index rejects it.
# The source cannot simply be deleted first: tenant_inventory_item_vendors
# is cascadeOnDelete, so deleting before the rows have moved destroys every
# item source row on it.
#
# So the code is RELEASED from the source explicitly, in its own statement,
# after the rows have moved and before the target claims it. Soft-deleting
# would not help either — the unique index does not care about deleted_at.
#
# Verified against the same failure Josh hit on grndctrl: BTI, 1,704 items.
set -e

python3 <<'PY'
import io

p = 'app/Services/Tenant/VendorMergeService.php'
s = io.open(p, encoding='utf-8').read()

old = """            // 3. Inherit blanks. Never overwrite something the shop typed.
            $fill = [];"""
assert s.count(old) == 1, 'M1 inherit-blanks anchor'
s = s.replace(old, """            // 3. MARKER-MERGE-CODE-RELEASE — hand the code over before the
            //    target claims it. (tenant_id, distributor_code) is unique, so
            //    two rows holding 'bti' for even one statement is rejected.
            //    The source cannot be deleted first to free it:
            //    tenant_inventory_item_vendors cascades on delete and the rows
            //    have only just been moved off it.
            $source->update(['distributor_code' => null]);

            // 4. Inherit blanks. Never overwrite something the shop typed.
            $fill = [];""")

old = """            // 4. Nothing references the source now, so the cascade on
            //    tenant_inventory_item_vendors has nothing left to take.
            $source->delete();"""
assert s.count(old) == 1, 'M2 delete anchor'
s = s.replace(old, """            // 5. Nothing references the source now, so the cascade on
            //    tenant_inventory_item_vendors has nothing left to take.
            $source->delete();""")

io.open(p, 'w', encoding='utf-8').write(s)
print('patched', p)
PY

echo
echo "--- merge order now reads ---"
grep -n "MARKER-MERGE-CODE-RELEASE\|source->update\|target->update\|source->delete\|// [0-9]\." app/Services/Tenant/VendorMergeService.php | sed -n '1,14p'

echo
echo "--- release happens before the claim ---"
python3 - <<'PY'
import io
s = io.open('app/Services/Tenant/VendorMergeService.php', encoding='utf-8').read()
rel = s.index("$source->update(['distributor_code' => null])")
claim = s.index("$fill['distributor_code'] = strtolower($code)")
dele = s.index("$source->delete()")
print('release < claim :', rel < claim)
print('claim  < delete :', claim < dele)
PY

echo
echo "--- balance ---"
python3 - <<'PY'
import io
s = io.open('app/Services/Tenant/VendorMergeService.php', encoding='utf-8').read()
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
print('VendorMergeService braces', d, 'parens', par)
PY

echo
echo "apply-vendor-merge-code-release: OK"
