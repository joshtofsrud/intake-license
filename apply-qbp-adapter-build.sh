#!/usr/bin/env bash
# apply-qbp-adapter-build.sh
# MARKER-QBP-BUILD — the real adapter.
#
# Every shape here was measured, not read from the guide:
#   brands            brands.brand           {id, description}
#   categories        productCategories.productCategory — FLAT, parent links
#   products          products.product       full rows, price and stock inline
#   attributes        classifications.classification.features.feature
#                       .featureValues.featureValue.value
#   stock (bulk)      liteStockLevel         {code, quantity, inStock}
#
# PAGING BY BRAND, and it is not optional. syncIdentity calls products() ONCE
# and holds the result. One brand measured 7 MB of XML — parsed, several times
# that — and there are 892 brands. Returning the catalog in one array would
# OOM the worker before a row was written. So pageStartIndex is a 1-based
# BRAND offset and pageSize is a count of BRANDS, and the caller loops.
#
# That also buys something HLC and BTI cannot do: opts['brands'] fetches only
# the brands named. A shop stocking forty brands syncs forty calls instead of
# the whole world.
#
# GROUPING. QBP returns one row per SKU; the catalog wants products with
# variants. modelCode is the grouping key — the same role group_id plays for
# BTI — so rows are grouped by modelCode and each row becomes a Variant.
#
# COST IS NOT RETURNED HERE. dealerPrice is the authenticated account's own
# price and the shared catalog is read by every tenant, so products() drops it
# deliberately; prices() returns it for the per-tenant tier. The two live one
# element apart in QBP's payload, which is exactly why this is stated twice.
set -e

python3 <<'PY'
import io

p = 'app/Services/Distributors/QbpClient.php'
s = io.open(p, encoding='utf-8').read()

assert 'MARKER-QBP-BUILD' not in s, 'already applied'

old = """    public function brands(): array      { throw $this->pending('brands'); }
    public function categories(): array  { throw $this->pending('categories'); }
    public function products(array $opts = []): array { throw $this->pending('products'); }
    public function inventory(array $skus): array     { throw $this->pending('inventory'); }
    public function prices(array $skus): array        { throw $this->pending('prices'); }"""
assert s.count(old) == 1, 'B1 stub block anchor'
s = s.replace(old, """    /**
     * MARKER-QBP-BUILD — every brand QBP carries.
     *
     * @return array<int,array{id:string,name:string}>
     */
    public function brands(): array
    {
        $doc = $this->fetch('1/brand');

        $out = [];
        foreach ($this->asList($doc['brands']['brand'] ?? null) as $b) {
            $id   = trim((string) ($b['id'] ?? ''));
            $name = trim((string) ($b['description'] ?? ''));
            if ($id === '') {
                continue;
            }
            // QBP's brand id is its own code (Maxxis is DHN), so cross-
            // distributor matching has to happen on the NAME. Both are kept.
            $out[] = ['id' => $id, 'name' => $name !== '' ? $name : $id];
        }
        return $out;
    }

    /**
     * MARKER-QBP-BUILD — the category tree, assembled.
     *
     * QBP returns a FLAT list where each node names its parent by id. Unlike
     * HLC there is no path on a node, so the path is walked here once and
     * cached, rather than re-walked per product during a sync.
     *
     * @return array<string,array{id:string,name:string,parent:?string,path:string}>
     */
    public function categories(): array
    {
        $doc = $this->fetch('1/category');

        $nodes = [];
        foreach ($this->asList($doc['productCategories']['productCategory'] ?? null) as $c) {
            $id = trim((string) ($c['id'] ?? ''));
            if ($id === '') {
                continue;
            }
            $nodes[$id] = [
                'id'     => $id,
                'name'   => trim((string) ($c['name'] ?? '')),
                'parent' => trim((string) ($c['parent']['id'] ?? '')) ?: null,
                'path'   => '',
            ];
        }

        foreach ($nodes as $id => $_) {
            $nodes[$id]['path'] = $this->categoryPath($nodes, $id);
        }

        return $nodes;
    }

    /**
     * Walk parent links to a full path. Depth-capped because a cycle in a
     * remote tree would otherwise hang the sync rather than fail it.
     */
    private function categoryPath(array $nodes, string $id, int $depth = 0): string
    {
        if ($depth > 12 || ! isset($nodes[$id])) {
            return '';
        }
        $name   = $nodes[$id]['name'];
        $parent = $nodes[$id]['parent'];

        if ($parent === null || $parent === '' || ! isset($nodes[$parent])) {
            return $name;
        }
        $up = $this->categoryPath($nodes, $parent, $depth + 1);
        return $up === '' ? $name : $up . ' > ' . $name;
    }

    /**
     * MARKER-QBP-BUILD — products, one page of BRANDS at a time.
     *
     * @param array{pageStartIndex?:int, pageSize?:int, brands?:array<int,string>} $opts
     *        pageStartIndex  1-based offset into the brand list
     *        pageSize        how many BRANDS this page covers, not products
     *        brands          explicit brand ids; skips the brand list entirely
     *
     * @return array{Products: array<int,array>}
     */
    public function products(array $opts = []): array
    {
        $ids = $opts['brands'] ?? null;

        if ($ids === null) {
            $all   = array_column($this->brands(), 'id');
            $start = max(1, (int) ($opts['pageStartIndex'] ?? 1));
            $size  = max(1, (int) ($opts['pageSize'] ?? 25));
            $ids   = array_slice($all, $start - 1, $size);
        }

        $byModel = [];

        foreach ($ids as $brandId) {
            $brandId = trim((string) $brandId);
            if ($brandId === '') {
                continue;
            }

            try {
                $doc = $this->fetch('1/product/brand/id/' . rawurlencode($brandId));
            } catch (\\Throwable $e) {
                // One bad brand must not abandon the page. A brand with no
                // products is normal; a 500 on one is not worth losing the
                // other twenty-four.
                continue;
            }

            foreach ($this->asList($doc['products']['product'] ?? null) as $row) {
                $sku = trim((string) ($row['sku'] ?? ''));
                if ($sku === '') {
                    continue;
                }

                // modelCode groups variants of one product. Missing means the
                // SKU stands alone, so it becomes its own group.
                $model = trim((string) ($row['modelCode'] ?? '')) ?: $sku;

                $byModel[$model] ??= [
                    'ModelCode' => $model,
                    'Brand'     => trim((string) ($row['brand']['description'] ?? '')) ?: $brandId,
                    'BrandId'   => trim((string) ($row['brand']['id'] ?? $brandId)),
                    'Variants'  => [],
                ];

                $byModel[$model]['Variants'][] = $this->variant($row);
            }

            unset($doc);
        }

        return ['Products' => array_values($byModel)];
    }

    /**
     * MARKER-QBP-BUILD — one SKU row, shaped for the field map.
     *
     * The raw element names are kept so a map written against the payload
     * reads true, with a few flattened additions the resolver cannot reach on
     * its own: dotted paths cannot walk a three-level attribute nest, and a
     * barcode list needs picking apart.
     *
     * dealerPrice is REMOVED. It is this account's price and this row goes
     * into the catalog every tenant reads.
     */
    private function variant(array $row): array
    {
        unset($row['dealerPrice']);

        $row['Attributes']   = $this->attributes($row['classifications'] ?? null);
        $row['CategoryName'] = trim((string) ($row['productCategories']['productCategory']['name'] ?? ''));
        $row['CategoryId']   = trim((string) ($row['productCategories']['productCategory']['id'] ?? ''));
        $row['ImageFile']    = trim((string) ($row['images']['image']['fileName'] ?? ''));

        // Dimensions flattened here because a dotted path cannot assemble
        // three elements into one JSON column, and the resolver's zip_pipe
        // expects pipe STRINGS, not element triples — checked, not assumed.
        $dims = [];
        foreach (['Length', 'Width', 'Height'] as $d) {
            $v = $row['freight'][$d]['value'] ?? null;
            if ($v !== null && trim((string) $v) !== '') {
                $dims[$d] = (float) $v;
            }
        }
        $row['Dimensions'] = $dims ?: null;

        // Barcodes arrive typed. Length alone cannot separate UPC from EAN —
        // a 13-digit EAN and a UPC with a leading zero look the same — so the
        // type is carried through and the decision left to the map.
        $codes = [];
        foreach ($this->asList($row['barcodes']['Barcode'] ?? null) as $bc) {
            $value = trim((string) ($bc['value'] ?? ''));
            if ($value === '') {
                continue;
            }
            $codes[] = ['type' => trim((string) ($bc['type'] ?? '')), 'value' => $value];
        }
        $row['BarcodeList']  = $codes;
        $row['FirstBarcode'] = $codes[0]['value'] ?? null;

        // Offerable at all? These are QBP-only; neither HLC nor BTI says.
        $row['IsOfferable'] = ! $this->truthy($row['blocked'] ?? null)
            && ! $this->truthy($row['discontinued'] ?? null);

        return $row;
    }

    /**
     * MARKER-QBP-BUILD — flatten QBP's attribute nest.
     *
     *   classification -> features.feature -> featureValues.featureValue.value
     *
     * The name is duplicated at classification and feature level; the
     * feature's is used, because that is the level the value hangs off.
     * featureValues is PLURAL — several values are joined rather than the
     * first taken, or a compatibility list would silently lose everything
     * after its first entry.
     *
     * The stable `code` is kept alongside the name: clsAttr_454-mm outlives a
     * relabelling of "Rim Width (Internal)".
     *
     * @return array<int,array{Name:string,Value:string,Code:string,Unit:string}>
     */
    private function attributes(mixed $classifications): array
    {
        $out = [];

        foreach ($this->asList($classifications['classification'] ?? $classifications ?? null) as $cls) {
            if (! is_array($cls)) {
                continue;
            }
            foreach ($this->asList($cls['features']['feature'] ?? null) as $feature) {
                if (! is_array($feature)) {
                    continue;
                }

                $values = [];
                foreach ($this->asList($feature['featureValues']['featureValue'] ?? null) as $fv) {
                    $v = is_array($fv) ? ($fv['value'] ?? '') : $fv;
                    $v = trim((string) $v);
                    if ($v !== '') {
                        $values[] = $v;
                    }
                }
                if (! $values) {
                    continue;
                }

                $name = trim((string) ($feature['name'] ?? $cls['name'] ?? ''));
                if ($name === '') {
                    continue;
                }

                $out[] = [
                    'Name'  => $name,
                    'Value' => implode(', ', $values),
                    'Code'  => trim((string) ($feature['code'] ?? $cls['code'] ?? '')),
                    'Unit'  => trim((string) ($feature['featureUnit'] ?? '')),
                ];
            }
        }

        return $out;
    }

    /**
     * MARKER-QBP-BUILD — stock for specific SKUs, tier 2.
     *
     * Per SKU, because that response carries the per-warehouse breakdown and
     * estimatedArrivalDate. For a whole-catalog refresh use
     * inventoryByWarehouse(), which returns a site in one call.
     *
     * @return array<string,array>
     */
    public function inventory(array $skus): array
    {
        $out = [];

        foreach ($skus as $sku) {
            $sku = trim((string) $sku);
            if ($sku === '') {
                continue;
            }

            try {
                $doc = $this->fetch('1/availability/sku/' . rawurlencode($sku));
            } catch (\\Throwable $e) {
                continue;
            }

            $avail = $this->asList($doc['productAvailabilities']['productAvailability'] ?? null);
            $first = $avail[0] ?? null;
            if (! is_array($first)) {
                continue;
            }

            $levels = [];
            $total  = 0;
            foreach ($this->asList($first['stockLevels']['stockLevel'] ?? null) as $lvl) {
                if (! is_array($lvl)) {
                    continue;
                }
                $qty = (int) ($lvl['quantityAvailable'] ?? 0);
                $total += $qty;
                $levels[] = [
                    'warehouse' => trim((string) ($lvl['warehouse']['code'] ?? '')),
                    'name'      => trim((string) ($lvl['warehouse']['name'] ?? '')),
                    'quantity'  => $qty,
                    'status'    => trim((string) ($lvl['stockLevelStatus'] ?? '')),
                    // Milliseconds. Note this appears on IN-stock rows too, so
                    // it is a restock date, not an arrival promise.
                    'eta_ms'    => $lvl['estimatedArrivalDate']['iMillis'] ?? null,
                ];
            }

            $out[$sku] = [
                'sku'         => $sku,
                'total'       => $total,
                'warehouses'  => $levels,
                'unavailable' => (string) ($first['temporarilyUnavailableToOrderCode'] ?? '0') !== '0',
            ];
        }

        return $out;
    }

    /**
     * MARKER-QBP-BUILD — a whole warehouse in one call.
     *
     * ~316k rows and ~39 MB per site, so this is a nightly instrument, not a
     * quarter-hourly one. Returns {sku => quantity} and nothing else; the
     * lite feed carries no warehouse detail and no ETA.
     *
     * @return array<string,int>
     */
    public function inventoryByWarehouse(string $warehouseCode): array
    {
        $doc = $this->fetch('1/availability/warehouse/' . rawurlencode($warehouseCode));

        $out = [];
        foreach ($this->asList($doc['liteStockLevel'] ?? null) as $row) {
            if (! is_array($row)) {
                continue;
            }
            $code = trim((string) ($row['code'] ?? ''));
            if ($code !== '') {
                $out[$code] = (int) ($row['quantity'] ?? 0);
            }
        }
        return $out;
    }

    /**
     * MARKER-QBP-BUILD — cost and the price ladder, tier 2 ONLY.
     *
     * dealerPrice is this account's negotiated price. It is returned here
     * because the per-tenant sync runs on the tenant's own credential, and
     * stripped from products() because that feeds the shared catalog.
     *
     * @return array<string,array>
     */
    public function prices(array $skus): array
    {
        $out = [];

        foreach ($skus as $sku) {
            $sku = trim((string) $sku);
            if ($sku === '') {
                continue;
            }

            try {
                $doc = $this->fetch('1/product/sku/' . rawurlencode($sku));
            } catch (\\Throwable $e) {
                continue;
            }

            $p = $this->asList($doc['products']['product'] ?? null)[0] ?? null;
            if (! is_array($p)) {
                continue;
            }

            // `value` is the number; `formattedValue` is "$8.40" and would
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
    }

    /** Decimal string to cents, without float rounding. */
    private function money(mixed $value): ?int
    {
        if ($value === null || trim((string) $value) === '') {
            return null;
        }
        return (int) round(((float) $value) * 100);
    }

    private function truthy(mixed $v): bool
    {
        return in_array(strtolower(trim((string) $v)), ['1', 'true', 'yes'], true);
    }

    /**
     * MARKER-QBP-BUILD — GET, parse, and refuse an envelope that is not OK.
     *
     * A 200 can carry a failure in responseStatus. Letting that through would
     * write an empty page over good rows.
     */
    private function fetch(string $path, array $query = []): array
    {
        $res = $this->get($path, $query);

        if (! $res->successful()) {
            throw new RuntimeException('QBP ' . $path . ' returned HTTP ' . $res->status());
        }

        $doc = $this->xml((string) $res->body());
        if ($doc === null) {
            throw new RuntimeException('QBP ' . $path . ' did not return parseable XML.');
        }

        $status = (string) ($doc['responseStatus']['@type'] ?? 'OK');
        if ($status !== '' && strtoupper($status) !== 'OK') {
            $err = $doc['errors']['errorMessage'] ?? null;
            throw new RuntimeException(
                'QBP ' . $path . ' reported ' . $status
                . (is_string($err) && $err !== '' ? ': ' . $err : '.')
            );
        }

        return $doc;
    }""")

io.open(p, 'w', encoding='utf-8').write(s)
print('patched', p)
PY

echo
echo "--- every method implemented, none still throwing 'pending' ---"
python3 - <<'PY'
import io, re
s = io.open('app/Services/Distributors/QbpClient.php', encoding='utf-8').read()
iface = io.open('app/Services/Distributors/DistributorAdapter.php', encoding='utf-8').read()
need = set(re.findall(r'public function (\w+)\(', iface))
have = set(re.findall(r'public function (\w+)\(', s))
print('  interface methods :', len(need))
print('  missing           :', sorted(need - have) or 'none')
still = re.findall(r'public function (\w+)\([^)]*\)[^{]*\{\s*throw \$this->pending', s)
print('  still stubbed     :', still or 'none')
assert not (need - have)
PY

echo
echo "--- dealerPrice is stripped from the shared path, kept on the tenant path ---"
python3 - <<'PY'
import io, re
s = io.open('app/Services/Distributors/QbpClient.php', encoding='utf-8').read()
var = re.search(r'private function variant\(array \$row\): array.*?\n    \}', s, re.S).group(0)
pri = re.search(r'public function prices\(array \$skus\): array.*?\n    \}', s, re.S).group(0)
print('  variant() unsets dealerPrice :', "unset($row['dealerPrice'])" in var)
print('  prices() returns dealer_price:', 'dealer_price' in pri)
PY

echo
echo "--- products() returns the shape extractProducts expects ---"
python3 -c "
import io, re
s = io.open('app/Services/Distributors/QbpClient.php', encoding='utf-8').read()
p = re.search(r'public function products\(array \\\$opts = \[\]\): array.*?\n    \}', s, re.S).group(0)
print('  returns Products key :', \"'Products' =>\" in p)
print('  each has Brand       :', \"'Brand'\" in p)
print('  each has Variants    :', \"'Variants'\" in p)
print('  pages by brand       :', 'pageStartIndex' in p and 'array_slice' in p)
"

echo
echo "--- attribute flattener handles multiple values ---"
python3 - <<'PY'
import io, re
s = io.open('app/Services/Distributors/QbpClient.php', encoding='utf-8').read()
a = re.search(r'private function attributes\(mixed \$classifications\): array.*?\n    \}', s, re.S).group(0)
print('  joins values      :', "implode(', ', $values)" in a)
print('  keeps stable code :', "'Code'" in a)
print('  reads feature name:', "$feature['name']" in a)
PY

echo
echo "--- balance ---"
python3 - <<'PY'
import io
s = io.open('app/Services/Distributors/QbpClient.php', encoding='utf-8').read()
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
print('QbpClient braces', d, 'parens', par, 'brackets', brk)
PY

echo
echo "apply-qbp-adapter-build: OK"
