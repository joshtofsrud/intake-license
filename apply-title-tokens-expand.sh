#!/usr/bin/env bash
# apply-title-tokens-expand.sh
# MARKER-TITLE-TOKENS — most of the catalog row has no token.
#
# The composer only ever receives eight things: brand, model, mpn,
# description, attributes, category, category_path, unit. Everything else on
# a catalog row is unreachable from a title rule — the description itself,
# the full category path, the item group, the distributor's own size and
# colour codes, case quantity, weight, dimensions and the barcodes.
#
# That is a limit of what gets PASSED, not a limit of the idea, so this
# widens the parts array and exposes the new fields as tokens.
#
# BOTH CALL SITES MOVE TOGETHER. The sync builds its own parts array while
# the editor preview and the recompose command use partsFromRow(). There is
# already a marker in this file warning that two copies of the token-building
# block let the preview and the real sync drift; the same hazard applies to
# the parts arrays, so both are extended identically here.
#
# FALLBACK CHAINS are the second half. Write {size|attr:Labeled Size|attr:Width}
# and the first token that resolves to something wins. This is the direct
# answer to the class of problem behind "size looks like a thread count": one
# rule can try the named attribute, then a different attribute, then the
# scraped size, without needing a per-category attribute priority for every
# category. An empty chain collapses to nothing, exactly as a single empty
# token already does.
set -e

python3 <<'PY'
import io

# ---------------------------------------------------------------- composer
p = 'app/Services/Distributors/CatalogTitleComposer.php'
s = io.open(p, encoding='utf-8').read()

# 1. new tokens in the resolver
old = """        $tokens = [
            'brand' => $brand, 'model' => $model, 'size' => $size,
            'color' => $color, 'mpn' => $mpn,
            'type'  => $type,  'type0' => $type0, 'unit' => $unit,
        ];"""
assert s.count(old) == 1, 'T1 tokens array anchor'
s = s.replace(old, """        // MARKER-TITLE-TOKENS — everything the parts array carries is
        // reachable. Anything added to $parts must be added here too, or it
        // silently resolves to empty.
        $tokens = [
            'brand' => $brand, 'model' => $model, 'size' => $size,
            'color' => $color, 'mpn' => $mpn,
            'type'  => $type,  'type0' => $type0, 'unit' => $unit,

            'desc'          => trim((string) ($parts['description'] ?? '')),
            'category_path' => trim((string) ($parts['category_path'] ?? '')),
            'group'         => trim((string) ($parts['item_group'] ?? '')),
            'size_code'     => trim((string) ($parts['size_id'] ?? '')),
            'color_code'    => trim((string) ($parts['color_id'] ?? '')),
            'case_qty'      => trim((string) ($parts['case_quantity'] ?? '')),
            'weight'        => trim((string) ($parts['weight'] ?? '')),
            'dimensions'    => trim((string) ($parts['dimensions'] ?? '')),
            'upc'           => trim((string) ($parts['upc'] ?? '')),
            'ean'           => trim((string) ($parts['ean'] ?? '')),
            'variant_no'    => trim((string) ($parts['variant_no'] ?? '')),
            'product_no'    => trim((string) ($parts['product_no'] ?? '')),
        ];""")

# 2. fallback chains
old = """        $out = preg_replace_callback('/\\{([^}]+)\\}/', fn ($m) => $resolve($m[1]), $template);"""
assert s.count(old) == 1, 'T2 render anchor'
s = s.replace(old, """        // MARKER-TITLE-TOKENS — a token may be a chain: {size|attr:Width|desc}
        // takes the first part that resolves to something. Lets one rule cope
        // with a distributor that files the same fact under different names,
        // instead of needing a category rule for each.
        $out = preg_replace_callback('/\\{([^}]+)\\}/', function ($m) use ($resolve) {
            foreach (explode('|', $m[1]) as $candidate) {
                $v = trim((string) $resolve(trim($candidate)));
                if ($v !== '') {
                    return $v;
                }
            }
            return '';
        }, $template);""")

# 3. partsFromRow — used by recompose and the editor preview
old = """            'category'      => $row->category,
            'category_path' => $row->category_path,
            'unit'          => $row->uom,
        ];"""
assert s.count(old) == 1, 'T3 partsFromRow anchor'
s = s.replace(old, """            'category'      => $row->category,
            'category_path' => $row->category_path,
            'unit'          => $row->uom,
            // MARKER-TITLE-TOKENS — must mirror the sync's parts array below,
            // or the preview shows something the sync will not produce.
            'item_group'    => $row->item_group,
            'size_id'       => $row->size_id,
            'color_id'      => $row->color_id,
            'case_quantity' => $row->case_quantity,
            'weight'        => $row->weight,
            'dimensions'    => is_array($row->dimensions) ? implode(' x ', $row->dimensions) : $row->dimensions,
            'upc'           => $row->upc,
            'ean'           => $row->ean,
            'variant_no'    => $row->distributor_variant_no,
            'product_no'    => $row->distributor_product_no,
        ];""")

io.open(p, 'w', encoding='utf-8').write(s)
print('patched', p)

# ---------------------------------------------------------------- sync call site
p = 'app/Services/Distributors/DistributorCatalogSyncService.php'
s = io.open(p, encoding='utf-8').read()

old = """            'category'      => $canonical['category'] ?? null,
            'category_path' => $canonical['category_path'] ?? null,
            'unit'          => $canonical['uom'] ?? null,
        ]);"""
assert s.count(old) == 1, 'S1 sync compose parts anchor'
s = s.replace(old, """            'category'      => $canonical['category'] ?? null,
            'category_path' => $canonical['category_path'] ?? null,
            'unit'          => $canonical['uom'] ?? null,
            // MARKER-TITLE-TOKENS — mirrors CatalogTitleComposer::partsFromRow().
            // If these two drift, the editor preview and the real title differ.
            'item_group'    => $canonical['item_group'] ?? null,
            'size_id'       => $canonical['size_id'] ?? null,
            'color_id'      => $canonical['color_id'] ?? null,
            'case_quantity' => $canonical['case_quantity'] ?? null,
            'weight'        => $canonical['weight'] ?? null,
            'dimensions'    => is_array($canonical['dimensions'] ?? null)
                ? implode(' x ', $canonical['dimensions'])
                : ($canonical['dimensions'] ?? null),
            'upc'           => $canonical['upc'] ?? null,
            'ean'           => $canonical['ean'] ?? null,
            'variant_no'    => $canonical['distributor_variant_no'] ?? null,
            'product_no'    => $canonical['distributor_product_no'] ?? null,
        ]);""")

io.open(p, 'w', encoding='utf-8').write(s)
print('patched', p)

# ---------------------------------------------------------------- chips
p = 'app/Filament/Pages/CatalogTitles.php'
s = io.open(p, encoding='utf-8').read()

old = """        '{allattr}'    => 'Every attribute, \"Name Value\" — exhaustive, for search',
    ];"""
assert s.count(old) == 1, 'K1 tokens constant anchor'
s = s.replace(old, """        '{allattr}'    => 'Every attribute, \"Name Value\" — exhaustive, for search',

        // MARKER-TITLE-TOKENS
        '{desc}'          => 'Full description text — usually only for search',
        '{category_path}' => 'Whole category path — \"Tires > Mountain Tires\"',
        '{group}'         => 'Item group — ties variants of one product together',
        '{size_code}'     => 'Size code the distributor uses (when there is no size attribute)',
        '{color_code}'    => 'Colour code the distributor uses',
        '{case_qty}'      => 'Units per case',
        '{weight}'        => 'Weight',
        '{dimensions}'    => 'Dimensions',
        '{upc}'           => 'UPC barcode — for search',
        '{ean}'           => 'EAN barcode — for search',
        '{variant_no}'    => 'Distributor variant number',
        '{product_no}'    => 'Distributor product number',
        '{a|b|c}'         => 'FALLBACK: first one that has a value wins, e.g. {size|attr:Labeled Size|size_code}',
    ];""")

io.open(p, 'w', encoding='utf-8').write(s)
print('patched', p)
PY

echo
echo "--- parts arrays match on both sides ---"
python3 - <<'PY'
import io, re
comp = io.open('app/Services/Distributors/CatalogTitleComposer.php', encoding='utf-8').read()
sync = io.open('app/Services/Distributors/DistributorCatalogSyncService.php', encoding='utf-8').read()

pfr = re.search(r"public function partsFromRow.*?\n    \}", comp, re.S).group(0)
a = set(re.findall(r"'(\w+)'\s*=>", pfr))

sc = re.search(r"\$composed = \$this->composer->compose\(.*?\]\);", sync, re.S).group(0)
b = set(re.findall(r"'(\w+)'\s*=>", sc))

print('partsFromRow only :', sorted(a - b) or 'none')
print('sync only         :', sorted(b - a) or 'none')
print('MATCH' if a == b else '*** DRIFT ***')
PY

echo
echo "--- every token has a parts key ---"
python3 - <<'PY'
import io, re
comp = io.open('app/Services/Distributors/CatalogTitleComposer.php', encoding='utf-8').read()
tok = re.search(r"\$tokens = \[.*?\n        \];", comp, re.S).group(0)
names = re.findall(r"'(\w+)'\s*=>", tok)
print(len(names), 'tokens:', ', '.join(names))
PY

echo "--- balance ---"
python3 - <<'PY'
import io
for p in ['app/Services/Distributors/CatalogTitleComposer.php',
          'app/Services/Distributors/DistributorCatalogSyncService.php',
          'app/Filament/Pages/CatalogTitles.php']:
    s = io.open(p, encoding='utf-8').read()
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
    print(p.split('/')[-1], 'braces', d, 'parens', par)
PY

echo
echo "apply-title-tokens-expand: OK"
