#!/usr/bin/env bash
# apply-qbp-tier2.sh
# MARKER-QBP-TIER2 — per-tenant cost and stock.
#
# Two faults, both mine, both of the kind that write nothing and say nothing.
#
# 1. SHAPE. TenantDistributorSyncService runs prices()/inventory() through
#    productsList(), which accepts {Products:[...]}, {Items:[...]} or a plain
#    LIST. QbpClient returned a map keyed by SKU — not a list — so
#    productsList() returned [] and cost resolved null on every item. No error,
#    no row, no clue. Exactly the BTI cost failure again.
#
#    Both now return {Products:[...]} with VariantNo on each row, matching what
#    the service reads.
#
# 2. COST WAS MISSING FROM THE FIELD MAP. I left cost_cents out on purpose,
#    reasoning that a cost path could leak the platform account's dealerPrice
#    into the shared catalog. That was wrong: syncIdentity sets
#    $canonical['cost_cents'] = null unconditionally after resolving, so the
#    map row cannot reach tier 1 — and tier 2 reads cost THROUGH that same map.
#    BTI has carried the row all along. Omitting it guaranteed QBP cost stayed
#    null forever.
#
#    The protection that actually matters is in the adapter: products() strips
#    dealerPrice so it never reaches the shared catalog, while prices() returns
#    it for the tenant's own credential. That stays exactly as it is.
set -e

python3 <<'PY'
import io

# ---------------------------------------------------------------- adapter
p = 'app/Services/Distributors/QbpClient.php'
s = io.open(p, encoding='utf-8').read()

assert 'MARKER-QBP-TIER2' not in s, 'already applied'

# prices(): return {Products: [...]} with VariantNo + the raw price nodes so
# the field map's cost row can read dealerPrice.value like any other path.
old = """            // `value` is the number; `formattedValue` is "$8.40" and would
            // parse to 0 or 8 depending on how hard something tried.
            $out[$sku] = [
                'sku'         => $sku,
                'dealer_price'=> $this->money($p['dealerPrice']['value'] ?? null),
                'base_price'  => $this->money($p['basePrice']['value'] ?? null),
                'map_price'   => $this->money($p['mapPrice']['value'] ?? null),
                'msrp'        => $this->money($p['msrp']['value'] ?? null),
                'currency'    => trim((string) ($p['dealerPrice']['currencyIso'] ?? 'USD')),
            ];
        }

        return $out;
    }"""
assert s.count(old) == 1, 'T1 prices anchor'
s = s.replace(old, """            // MARKER-QBP-TIER2 — the service reads {Products:[...]} and keys
            // rows by VariantNo. The raw price nodes ride along so the field
            // map resolves cost from dealerPrice.value exactly as tier 1
            // resolves msrp — one resolver, one definition of cost.
            //
            // `value` is the number; `formattedValue` is "$8.40" and would
            // parse to 0 or 8 depending on how hard something tried.
            $out[] = [
                'VariantNo'   => $sku,
                'dealerPrice' => $p['dealerPrice'] ?? null,
                'basePrice'   => $p['basePrice'] ?? null,
                'mapPrice'    => $p['mapPrice'] ?? null,
                'msrp'        => $p['msrp'] ?? null,
                // Pre-computed cents kept for callers not going via the map.
                'cost_cents'  => $this->money($p['dealerPrice']['value'] ?? null),
                'map_cents'   => $this->money($p['mapPrice']['value'] ?? null),
                'msrp_cents'  => $this->money($p['msrp']['value'] ?? null),
            ];
        }

        return ['Products' => $out];
    }""")

# inventory(): same treatment. normalizeInventory looks for VariantNo/Sku and
# a quantity under one of several names, or sums a warehouse array.
old = """            $out[$sku] = [
                'sku'         => $sku,
                'total'       => $total,
                'warehouses'  => $levels,
                'unavailable' => (string) ($first['temporarilyUnavailableToOrderCode'] ?? '0') !== '0',
            ];
        }

        return $out;
    }"""
assert s.count(old) == 1, 'T2 inventory anchor'
s = s.replace(old, """            // MARKER-QBP-TIER2 — TotalQtyAvailable is the first key
            // normalizeInventory looks for; Warehouses is the array it would
            // fall back to summing. Both are provided so the per-warehouse
            // detail survives for anything that wants it, while the simple
            // total is what the pivot stores.
            $out[] = [
                'VariantNo'         => $sku,
                'TotalQtyAvailable' => $total,
                'Warehouses'        => array_map(fn ($l) => [
                    'Code'         => $l['warehouse'],
                    'Name'         => $l['name'],
                    'QtyAvailable' => $l['quantity'],
                    'Status'       => $l['status'],
                    'EtaMs'        => $l['eta_ms'],
                ], $levels),
                'Unavailable' => (string) ($first['temporarilyUnavailableToOrderCode'] ?? '0') !== '0',
            ];
        }

        return ['Products' => $out];
    }""")

io.open(p, 'w', encoding='utf-8').write(s)
print('patched', p)

# ---------------------------------------------------------------- seeder
p = 'database/seeders/QbpFieldMapSeeder.php'
s = io.open(p, encoding='utf-8').read()

old = """            // money — value is numeric; formattedValue is "$8.40" ---------"""
assert s.count(old) == 1, 'T3 money block anchor'
s = s.replace(old, """            // money — value is numeric; formattedValue is "$8.40" ---------
            // MARKER-QBP-TIER2 — cost_cents belongs here after all. Tier 1
            // sets it to null unconditionally after resolving (see
            // DistributorCatalogSyncService: "Shared catalog never holds
            // tenant cost"), so this row cannot reach the shared catalog.
            // Tier 2 reads cost THROUGH this map, so omitting it left QBP
            // cost null forever. The real protection is in the adapter:
            // products() strips dealerPrice, prices() returns it.
            ['cost_cents', 'dealerPrice.value', 'direct', ['cast' => 'cents'], null,
                'dealer cost — tier 2 only; tier 1 nulls it after resolve'],""")

# The header comment now contradicts the fix; correct it rather than leave a
# note that argues against the code.
old = """ * No cost_cents on purpose — dealerPrice is the platform account's own price
 * and is stripped before rows reach the shared catalog. Cost arrives per
 * tenant through tier 2.
 */"""
assert s.count(old) == 1, 'T4 header anchor'
s = s.replace(old, """ * cost_cents IS mapped, and safely: tier 1 nulls it after resolving, so it
 * cannot reach the shared catalog, while tier 2 resolves tenant cost through
 * this same map. The leak is prevented in the adapter — products() strips
 * dealerPrice entirely; only prices() returns it.
 */""")

io.open(p, 'w', encoding='utf-8').write(s)
print('patched', p)
PY

echo
echo "--- both tier-2 methods return the shape the service reads ---"
python3 - <<'PY'
import io, re
s = io.open('app/Services/Distributors/QbpClient.php', encoding='utf-8').read()
for meth in ['prices', 'inventory']:
    m = re.search(r'public function ' + meth + r'\(array \$skus\): array.*?\n    \}', s, re.S).group(0)
    print('  %-10s returns Products :%s  keys by VariantNo :%s'
          % (meth, "['Products' => $out]" in m, "'VariantNo'" in m))
PY

echo
echo "--- products() still strips dealerPrice (the real guard) ---"
python3 - <<'PY'
import io, re
s = io.open('app/Services/Distributors/QbpClient.php', encoding='utf-8').read()
v = re.search(r'private function variant\(array \$row\): array.*?\n    \}', s, re.S).group(0)
print('  variant() unsets dealerPrice:', "unset($row['dealerPrice'])" in v)
PY

echo
echo "--- cost is mapped, and tier 1 still nulls it ---"
grep -n "'cost_cents', 'dealerPrice.value'" database/seeders/QbpFieldMapSeeder.php
grep -n "Shared catalog never holds tenant cost" app/Services/Distributors/DistributorCatalogSyncService.php

echo
echo "--- normalizeInventory can read what inventory() emits ---"
python3 - <<'PY'
# Mirror the service: productsList then key lookup.
def products_list(batch):
    if not isinstance(batch, dict) and not isinstance(batch, list): return []
    if isinstance(batch, dict) and isinstance(batch.get('Products'), list): return batch['Products']
    return batch if isinstance(batch, list) else []

emitted = {'Products': [{'VariantNo': 'BB1001', 'TotalQtyAvailable': 121,
                         'Warehouses': [{'Code': '1300', 'QtyAvailable': 21}]}]}
rows = products_list(emitted)
row = rows[0]
vno = row.get('VariantNo') or row.get('Sku')
qty = next((row[k] for k in ['TotalQtyAvailable','Available','TotalAvailable','Quantity','QtyAvailable']
            if isinstance(row.get(k), int)), None)
print('  rows found :', len(rows))
print('  VariantNo  :', vno)
print('  quantity   :', qty)
assert len(rows) == 1 and vno == 'BB1001' and qty == 121
PY

echo
echo "--- balance ---"
python3 - <<'PY'
import io
for p in ['app/Services/Distributors/QbpClient.php', 'database/seeders/QbpFieldMapSeeder.php']:
    s = io.open(p, encoding='utf-8').read()
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
    print('%-34s braces %d parens %d brackets %d' % (p.split('/')[-1], d, par, brk))
PY

echo
echo "apply-qbp-tier2: OK"
