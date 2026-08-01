#!/usr/bin/env bash
# apply-bti-prices-keep-raw.sh
# MARKER-BTI-PRICES-RAW — BTI cost never loads because prices() renames the
# very fields the field map reads.
#
# The map is right:
#     cost_cents  <- your_price  [direct] {"cast":"cents"}
#     msrp_cents  <- msrp        [direct] {"cast":"cents"}
#     map_cents   <- map         [direct] {"cast":"cents_zero_null"}
#
# And tier 2 resolves cost through that map:
#     TenantDistributorSyncService::fetchCosts()
#       -> $adapter->prices($chunk)
#       -> $this->resolver->resolve($code, $row, $row)
#       -> $resolved['cost_cents']
#
# But BtiClient::prices() rebuilt each row into HLC's shape first —
# VariantNo / Prices[] / MSRP / MAP — and dropped the raw feed keys. So the
# resolver went looking for `your_price` in a row that no longer had it and
# got null. Every product, every sync. `msrp` and `map` broke the same way,
# since the reshape uppercased them.
#
# The comment there says the Prices[] array "is what fetchCosts() reads".
# That is the wrong model of the call: fetchCosts() reads whatever the
# field map names, and the map names raw feed columns. Reshaping to look
# like HLC is precisely what broke it — HLC works because ITS map points at
# ITS shape.
#
# Fix keeps both: spread the raw row first, then add the HLC-shaped keys on
# top. The map finds its columns, anything reading Prices[]/MSRP/MAP still
# works, and a future map row can reference any feed column without another
# code change. Raw keys are lowercase and the added ones are capitalised, so
# nothing collides.
#
# Tier 1 is unaffected — DistributorCatalogSyncService deliberately nulls
# cost_cents because cost is per-tenant.
#
# Service change: optimize:clear + fpm cycle, then re-run the tenant sync.
set -e

python3 <<'PY'
import io

p = 'app/Services/Distributors/BtiClient.php'
s = io.open(p, encoding='utf-8').read()

old = """            // MARKER-BTI-SYNC-SHAPES — VariantNo plus a Prices[] array, which
            // is what fetchCosts() reads. HLC sends tiers there, so BTI's
            // single dealer price is emitted as one tier rather than as a
            // differently-named field the service would ignore.
            $out[] = [
                'VariantNo' => $id,"""
assert s.count(old) == 1, 'B1 prices shape anchor'
s = s.replace(old, """            // MARKER-BTI-PRICES-RAW — keep the raw feed row underneath.
            //
            // fetchCosts() does NOT read Prices[] directly; it runs the row
            // through DistributorMapResolver against BTI's field map, and
            // that map points at raw feed columns:
            //     cost_cents <- your_price, msrp_cents <- msrp, map_cents <- map
            //
            // Emitting only the reshaped HLC-style keys meant the resolver
            // could never find them, so cost_cents came back null on every
            // product. Spreading $r first fixes cost, MSRP and MAP together,
            // and lets a new map row reference any feed column without
            // touching this file. The capitalised keys below are added on top
            // for anything that does read the HLC shape; no collision, since
            // BTI's own columns are lowercase.
            $out[] = array_merge($r, [
                'VariantNo' => $id,""")

old = """                'OnSale'     => (bool) ((int) ($r['is_on_sale'] ?? 0)),
                'OnCloseout' => (bool) ((int) ($r['is_on_closeout'] ?? 0)),
            ];"""
assert s.count(old) == 1, 'B2 prices close anchor'
s = s.replace(old, """                'OnSale'     => (bool) ((int) ($r['is_on_sale'] ?? 0)),
                'OnCloseout' => (bool) ((int) ($r['is_on_closeout'] ?? 0)),
            ]);""")

io.open(p, 'w', encoding='utf-8').write(s)
print('patched', p)
PY

echo "--- prices() now emits ---"
sed -n '/public function prices/,/^    }$/p' app/Services/Distributors/BtiClient.php | grep -n "array_merge\|VariantNo\|Prices\|MSRP\|MAP\|OnSale\|]);"

echo
echo "--- balance ---"
python3 - <<'PY'
import io
s = io.open('app/Services/Distributors/BtiClient.php', encoding='utf-8').read()
i, n, d, par, brk = 0, len(s), 0, 0, 0
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
        elif c == '[': brk += 1
        elif c == ']': brk -= 1
        i += 1
print('braces', d, 'parens', par, 'brackets', brk)
PY

echo
echo "apply-bti-prices-keep-raw: OK"
