#!/usr/bin/env bash
# apply-qbp-probe-brand.sh
# MARKER-QBP-BRAND — products by brand, the documented bulk path.
#
# POST /1/model/id refused both body shapes with a generic 5100, and the
# request format only exists in a Swagger harness. But the guide already
# names another route and I read past it:
#
#   "obtaining and using a list of brand ID's to pull applicable product
#    lists from GET /1/product/brand/id/{id}"
#
# That is a plain GET on a path shape already proven to work. 892 brands is
# 892 calls for the entire catalog — a nightly job, not a week — and it comes
# with a property the model route lacks: a shop that stocks forty brands can
# sync forty calls instead of the world.
#
# What this measures:
#   - does the endpoint answer, and with the product shape we mapped
#   - how many products come back for one brand
#   - whether price and stock are inline, or need a second pass
#
# Read-only. Prints counts and one product's field names, not the payload —
# a large brand would be megabytes.
set -e

python3 <<'PY'
import io

p = 'app/Console/Commands/QbpProbe.php'
s = io.open(p, encoding='utf-8').read()

assert 'MARKER-QBP-BRAND' not in s, 'already applied'

old = """        {--bulk : Probe the bulk endpoints a real sync would have to use}';"""
assert s.count(old) == 1, 'R1 signature anchor'
s = s.replace(old, """        {--bulk : Probe the bulk endpoints a real sync would have to use}
        {--brand= : Probe products-by-brand, e.g. --brand=DRW (Wheels Manufacturing)}';""")

old = """        // MARKER-QBP-BULK — run this before designing the sync.
        if ($this->option('bulk')) {
            return $this->bulk();
        }"""
assert s.count(old) == 1, 'R2 dispatch anchor'
s = s.replace(old, """        // MARKER-QBP-BULK — run this before designing the sync.
        if ($this->option('bulk')) {
            return $this->bulk();
        }

        // MARKER-QBP-BRAND — the likely tier-1 path.
        if ($this->option('brand')) {
            return $this->byBrand((string) $this->option('brand'));
        }""")

old = """    /**
     * MARKER-QBP-BULK — measure the three endpoints a sync would live on."""
assert s.count(old) == 1, 'R3 method anchor'
s = s.replace(old, """    /**
     * MARKER-QBP-BRAND — one brand's products.
     *
     * Reports the shape rather than the payload: how many products, and what
     * fields the first one carries. A field list is what the map is written
     * from; the values are already known from the single-product probe.
     */
    private function byBrand(string $brandId): int
    {
        $brandId = trim($brandId);
        $this->line('Base URL: ' . $this->base);
        $this->line('Brand id: ' . $brandId);
        $this->newLine();

        $doc = $this->probeGet('1/product/brand/id/' . rawurlencode($brandId), 'Products for brand ' . $brandId);

        if (! is_array($doc)) {
            $this->error('No parseable response. If this 404s, the path shape differs from the guide.');
            return self::FAILURE;
        }

        $products = $this->asList($doc['products']['product'] ?? null);

        if (! $products) {
            $this->warn('Parsed, but no products.product list. Lists found in the response:');
            foreach ($this->collections($doc) as $path => $items) {
                $this->line('  ' . $path . ' -> ' . count($items));
            }
            return self::SUCCESS;
        }

        $this->line('  ' . count($products) . ' products for this brand.');
        $this->newLine();

        $first = $products[0];
        $this->line('--- fields on the first product ---');
        $this->line('  ' . implode(', ', array_keys(is_array($first) ? $first : [])));
        $this->newLine();

        // The two that decide whether this is one pass or three.
        $this->line('--- is it a full product, or a stub? ---');
        foreach (['dealerPrice', 'stockLevels', 'barcodes', 'productCategories', 'classifications', 'bulletPoints'] as $key) {
            $present = array_key_exists($key, is_array($first) ? $first : []);
            $value   = $present ? $first[$key] : null;
            $note    = ! $present ? 'ABSENT'
                : (($value === '' || $value === null) ? 'present but empty'
                : (is_array($value) ? 'present, ' . count($value) . ' key(s)' : 'present'));
            $this->line(sprintf('  %-18s %s', $key, $note));
        }

        // Sanity: does the dealer price actually carry a number here?
        $price = $first['dealerPrice']['value'] ?? null;
        $this->newLine();
        $this->line('  dealerPrice.value on the first product: ' . ($price === null ? 'MISSING' : $price));

        $this->newLine();
        $this->comment('Read it this way:');
        $this->line('  Full products with dealerPrice and stockLevels inline means the whole');
        $this->line('  catalog is ~892 calls, identity and price and stock in one pass.');
        $this->line('  Stubs mean a second call per product, which puts us back at 30,000.');
        $this->line('  Remember dealerPrice is THIS account\\'s price — it belongs on the');
        $this->line('  per-tenant sync, never in the shared catalog.');

        return self::SUCCESS;
    }

    /**
     * MARKER-QBP-BULK — measure the three endpoints a sync would live on.""")

io.open(p, 'w', encoding='utf-8').write(s)
print('patched', p)
PY

echo
echo "--- brand mode wired ---"
grep -n "MARKER-QBP-BRAND\|--brand\|private function byBrand" app/Console/Commands/QbpProbe.php | head

echo
echo "--- uses the confirmed collection path ---"
grep -n "products'\]\['product'\]" app/Console/Commands/QbpProbe.php

echo
echo "--- no Command method shadowed ---"
python3 - <<'PY'
import io, re
s = io.open('app/Console/Commands/QbpProbe.php', encoding='utf-8').read()
reserved = {'call','callSilent','handle','run','ask','confirm','choice','table','info','line',
            'comment','question','error','warn','alert','newLine','argument','arguments',
            'option','options','output','components','task'}
bad = [m for m in re.findall(r'(?:private|protected) function (\w+)\(', s) if m in reserved]
print('  clashes:', bad or 'none')
assert not bad
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
echo "apply-qbp-probe-brand: OK"
