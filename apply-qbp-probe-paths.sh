#!/usr/bin/env bash
# apply-qbp-probe-paths.sh
# MARKER-QBP-PATHS — read QBP's collections by name, not by guessing.
#
# The probe carried listish(), written for JSON, which hunts a payload for
# whichever key holds a list. QBP nests one level deeper than that assumes:
#
#   <brandResponse><brands><brand>…       ->  brands.brand
#   <productCategories><productCategory>… ->  productCategories.productCategory
#   <skuListResponse><skus><sku>…         ->  skus.sku
#
# `brands` is an object containing a list, not a list, so the hunt fell
# through and reported "1 brands" for 800 of them. Worse, it then handed the
# whole document to the SKU extractor, which called reset() on it and got an
# array where a string was expected — the fatal that stopped the run before
# product detail, which is the payload actually needed.
#
# XML names its collections, so the paths are known. Guessing was a JSON habit
# and it earned nothing.
set -e

python3 <<'PY'
import io

p = 'app/Console/Commands/QbpProbe.php'
s = io.open(p, encoding='utf-8').read()

assert 'MARKER-QBP-PATHS' not in s, 'already applied'

# ---------------------------------------------------------------- brands
old = """        $brands = $this->probeGet('1/brand', 'Brands');
        if (is_array($brands)) {
            $list = $this->listish($brands);
            $this->line('  ' . count($list) . ' brands. First three:');
            foreach (array_slice($list, 0, 3) as $b) {
                $this->line('    ' . json_encode($b));
            }
        }"""
assert s.count(old) == 1, 'P1 brands anchor'
s = s.replace(old, """        // MARKER-QBP-PATHS — brands.brand, by name.
        $brands = $this->probeGet('1/brand', 'Brands');
        if (is_array($brands)) {
            $list = $this->asList($brands['brands']['brand'] ?? null);
            $this->line('  ' . count($list) . ' brands. First three:');
            foreach (array_slice($list, 0, 3) as $b) {
                $this->line('    ' . json_encode($b));
            }
        }""")

# ---------------------------------------------------------------- categories
old = """        $cats = $this->probeGet('1/category', 'Categories');
        if (is_array($cats)) {
            $list = $this->listish($cats);
            $this->line('  ' . count($list) . ' top-level nodes. First one:');
            $this->line('    ' . substr((string) json_encode($list[0] ?? null), 0, 600));
        }"""
assert s.count(old) == 1, 'P2 categories anchor'
s = s.replace(old, """        // MARKER-QBP-PATHS — a FLAT list of nodes, each naming its parent and
        // its children by id. Not a nested tree, so category_path has to be
        // assembled by walking parent links rather than read off a node.
        $cats = $this->probeGet('1/category', 'Categories');
        if (is_array($cats)) {
            $list = $this->asList($cats['productCategories']['productCategory'] ?? null);
            $this->line('  ' . count($list) . ' category nodes (flat; parent links, not nesting).');
            $this->line('  First one:');
            $this->line('    ' . substr((string) json_encode($list[0] ?? null), 0, 500));
            $roots = array_values(array_filter($list, fn ($c) => ($c['parent'] ?? '') === ''));
            $this->line('  ' . count($roots) . ' root node(s).');
        }""")

# ---------------------------------------------------------------- skus
old = """        $sku  = (string) $this->argument('sku');
        $skus = $this->probeGet('1/product/skulist', 'SKU list');
        if (is_array($skus)) {
            $list = $this->listish($skus);
            $this->line('  ' . count($list) . ' SKUs.');
            $this->line('  First five: ' . json_encode(array_slice($list, 0, 5)));
            if ($sku === '' && $list) {
                $first = $list[0];
                $sku = is_array($first)
                    ? (string) ($first['sku'] ?? $first['Sku'] ?? reset($first))
                    : (string) $first;
            }
        }"""
assert s.count(old) == 1, 'P3 skulist anchor'
s = s.replace(old, """        // MARKER-QBP-PATHS — skus.sku is a list of plain strings.
        $sku  = (string) $this->argument('sku');
        $skus = $this->probeGet('1/product/skulist', 'SKU list');
        if (is_array($skus)) {
            $list = $this->asList($skus['skus']['sku'] ?? null);
            $this->line('  ' . count($list) . ' SKUs.');
            $this->line('  First five: ' . json_encode(array_slice($list, 0, 5)));

            // Take the first entry that is genuinely a string. Guarding here
            // because handing an array to a string cast is exactly what
            // killed the previous run before it reached product detail.
            if ($sku === '') {
                foreach ($list as $candidate) {
                    if (is_string($candidate) && trim($candidate) !== '') {
                        $sku = trim($candidate);
                        break;
                    }
                }
            }
        }""")

# ---------------------------------------------------------------- helpers
old = """    private function listish(array $payload): array
    {
        if (array_is_list($payload)) {
            return $payload;
        }
        foreach ($payload as $v) {
            if (is_array($v) && array_is_list($v)) {
                return $v;
            }
        }
        return [$payload];
    }"""
assert s.count(old) == 1, 'P4 listish anchor'
s = s.replace(old, """    /**
     * MARKER-QBP-PATHS — one child or many.
     *
     * SimpleXML hands back an object for a single child and a list for two,
     * so every collection read goes through this. Replaces listish(), which
     * searched a payload for any list it could find — a JSON habit that has
     * no place against XML, where the collection is named.
     *
     * @return array<int,mixed>
     */
    private function asList(mixed $value): array
    {
        if ($value === null || $value === '') {
            return [];
        }
        if (is_array($value) && array_is_list($value)) {
            return $value;
        }
        return [$value];
    }""")

io.open(p, 'w', encoding='utf-8').write(s)
print('patched', p)
PY

echo
echo "--- collections read by name ---"
grep -n "brands'\]\['brand'\]\|productCategories'\]\['productCategory'\]\|skus'\]\['sku'\]" app/Console/Commands/QbpProbe.php

echo
echo "--- listish is gone; asList replaces it ---"
grep -c "listish" app/Console/Commands/QbpProbe.php
grep -c "asList" app/Console/Commands/QbpProbe.php

echo
echo "--- the SKU pick can no longer cast an array to string ---"
python3 - <<'PY'
import io, re
s = io.open('app/Console/Commands/QbpProbe.php', encoding='utf-8').read()
block = re.search(r"if \(\$sku === ''\) \{.*?\n            \}", s, re.S).group(0)
print('  guards on is_string:', 'is_string( $candidate )'.replace(' ','') in block.replace(' ',''))
print('  no reset() cast    :', 'reset(' not in block)
PY

echo
echo "--- balance ---"
python3 - <<'PY'
import io
s = io.open('app/Console/Commands/QbpProbe.php', encoding='utf-8').read()
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
print('QbpProbe braces', d, 'parens', par, 'brackets', brk)
PY

echo
echo "apply-qbp-probe-paths: OK"
