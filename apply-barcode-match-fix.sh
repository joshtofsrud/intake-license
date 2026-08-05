#!/usr/bin/env bash
# apply-barcode-match-fix.sh
# MARKER-BARCODE-TYPE — a UPC and an EAN are the same barcode.
#
# Proven on Maxxis Assegai 4717784034485:
#
#   BTI  ean 4717784034485      QBP  upc 4717784034485
#   BTI  mpn MAXXIS|TB00097400  QBP  mpn MAXXIS|TB00097400
#
# Identical number, identical MPN, and the matcher produced only `held mpn`
# because it joins on identifier_type = identifier_type. The barcode pair
# never met. Ground Control carries that tyre; the importer saw 417 QBP Maxxis
# items as brand new.
#
# TWO FIXES, and they are independent on purpose.
#
# 1. THE MATCHER. upc and ean are labels for the same 12/13-digit barcode —
#    a UPC-A is an EAN-13 with a leading zero, which barcodes() already
#    accounts for by emitting both forms. Treating the labels as
#    interchangeable fixes every distributor pair at once, retroactively, with
#    no re-index. mpn still has to match mpn.
#
# 2. QBP'S MAPPING, which is where the wrong label came from. I mapped every
#    barcode to `upc` regardless of type — having explicitly flagged Y1 vs Y3
#    as unknown, then assumed anyway. Routed by LENGTH now: 12 digits is a
#    UPC, 13 an EAN. That is the actual standard, and it does not depend on
#    decoding QBP's type codes, which nobody has told us the meaning of.
#
# Fix 1 alone would have made this pair match. Fix 2 stops QBP putting EANs in
# the UPC column for everything downstream that reads those columns directly.
set -e

python3 <<'PY'
import io

# ---------------------------------------------------------------- matcher
p = 'app/Console/Commands/MatchCatalogRows.php'
s = io.open(p, encoding='utf-8').read()

assert 'MARKER-BARCODE-TYPE' not in s, 'already applied'

old = """            from catalog_identifiers a
            join catalog_identifiers b
              on a.identifier_type = b.identifier_type
             and a.value_norm      = b.value_norm
             and a.distributor_code < b.distributor_code"""
assert s.count(old) == 1, 'M1 join anchor'
s = s.replace(old, """            from catalog_identifiers a
            join catalog_identifiers b
              on (
                   -- MARKER-BARCODE-TYPE: upc and ean label the SAME barcode.
                   -- One distributor files 4717784034485 as ean and another
                   -- as upc; joining on the label made identical products
                   -- invisible to each other. mpn must still meet mpn.
                   a.identifier_type = b.identifier_type
                   or (a.identifier_type in ('upc','ean')
                       and b.identifier_type in ('upc','ean'))
                 )
             and a.value_norm      = b.value_norm
             and a.distributor_code < b.distributor_code""")

io.open(p, 'w', encoding='utf-8').write(s)
print('patched', p)

# ---------------------------------------------------------------- adapter
p = 'app/Services/Distributors/QbpClient.php'
s = io.open(p, encoding='utf-8').read()

old = """        $row['BarcodeList']  = $codes;
        $row['FirstBarcode'] = $codes[0]['value'] ?? null;"""
assert s.count(old) == 1, 'Q1 barcode anchor'
s = s.replace(old, """        $row['BarcodeList']  = $codes;
        $row['FirstBarcode'] = $codes[0]['value'] ?? null;

        // MARKER-BARCODE-TYPE — route by LENGTH, not by QBP's type code.
        // 12 digits is a UPC-A, 13 an EAN-13. Y1 and Y3 both appear on real
        // rows and nobody has told us what they mean, so the standard is a
        // safer authority than a guess about a vendor's private codes.
        $row['UpcCode'] = null;
        $row['EanCode'] = null;
        foreach ($codes as $c) {
            $digits = preg_replace('/\\D+/', '', (string) $c['value']);
            if (strlen($digits) === 12 && $row['UpcCode'] === null) {
                $row['UpcCode'] = $digits;
            } elseif (strlen($digits) === 13 && $row['EanCode'] === null) {
                $row['EanCode'] = $digits;
            }
        }""")

io.open(p, 'w', encoding='utf-8').write(s)
print('patched', p)

# ---------------------------------------------------------------- seeder
p = 'database/seeders/QbpFieldMapSeeder.php'
s = io.open(p, encoding='utf-8').read()

old = """            ['upc', 'FirstBarcode', 'direct', null, null,
                'type Y3 observed = UPC; BarcodeList carries {type,value} if this needs refining'],"""
assert s.count(old) == 1, 'S1 upc anchor'
s = s.replace(old, """            // MARKER-BARCODE-TYPE — routed by length in the adapter. Mapping
            // every barcode to upc put 13-digit EANs in the UPC column, which
            // is how a Maxxis tyre BTI files as ean failed to match QBP's
            // identical number.
            ['upc', 'UpcCode', 'direct', null, null, '12-digit UPC-A only'],
            ['ean', 'EanCode', 'direct', null, null, '13-digit EAN-13 only'],""")

io.open(p, 'w', encoding='utf-8').write(s)
print('patched', p)
PY

echo
echo "--- matcher treats upc and ean as one barcode space ---"
grep -n "a.identifier_type in ('upc','ean')" app/Console/Commands/MatchCatalogRows.php

echo
echo "--- QBP routes by length ---"
grep -n "UpcCode\|EanCode" app/Services/Distributors/QbpClient.php | head -4
grep -n "'upc', 'UpcCode'\|'ean', 'EanCode'" database/seeders/QbpFieldMapSeeder.php

echo
echo "--- the real Assegai pair now joins ---"
python3 - <<'PY'
# Mirror the SQL join condition on the actual rows from the diagnostic.
rows = [
    ('BTI', 'ean', '4717784034485'),
    ('QBP', 'upc', '4717784034485'),
    ('BTI', 'mpn', 'MAXXIS|TB00097400'),
    ('QBP', 'mpn', 'MAXXIS|TB00097400'),
]

def joins(a, b):
    same_type = a[1] == b[1]
    both_bar  = a[1] in ('upc', 'ean') and b[1] in ('upc', 'ean')
    return (same_type or both_bar) and a[2] == b[2] and a[0] < b[0]

pairs = [(a, b) for a in rows for b in rows if joins(a, b)]
bar = [p for p in pairs if p[0][1] in ('upc','ean')]
print('  joining pairs :', len(pairs))
for a, b in pairs:
    print('    %s %s  <->  %s %s   %s' % (a[0], a[1], b[0], b[1], a[2]))
print('  barcode match :', 'YES — becomes auto' if bar else 'no — still mpn-only/held')
assert bar, 'the barcode pair must now join'
PY

echo
echo "--- mpn still requires mpn (no cross-type leakage) ---"
python3 - <<'PY'
def joins(at, bt):
    return at == bt or (at in ('upc','ean') and bt in ('upc','ean'))
for a, b, want in [('mpn','upc',False), ('mpn','ean',False), ('mpn','mpn',True),
                   ('upc','ean',True), ('upc','upc',True), ('ean','ean',True)]:
    got = joins(a, b)
    print('  %-4s vs %-4s -> %-5s %s' % (a, b, got, 'OK' if got == want else '*** WRONG ***'))
    assert got == want
PY

echo
echo "--- balance ---"
python3 - <<'PY'
import io
for p in ['app/Console/Commands/MatchCatalogRows.php',
          'app/Services/Distributors/QbpClient.php',
          'database/seeders/QbpFieldMapSeeder.php']:
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
    print('%-32s braces %d parens %d brackets %d' % (p.split('/')[-1], d, par, brk))
PY

echo
echo "apply-barcode-match-fix: OK"
