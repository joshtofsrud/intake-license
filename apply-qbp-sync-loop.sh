#!/usr/bin/env bash
# apply-qbp-sync-loop.sh
# MARKER-QBP-SYNC — tier-1 sync pages QBP by brand.
#
# syncIdentity was written for HLC's shape: fetch the whole catalog in ONE
# products() call, group by brand in memory, then write. QBP measured 7 MB of
# XML for ONE brand across 892 brands — a single-call fetch would hold
# gigabytes and the worker dies before writing a row.
#
# QbpClient::products() already pages by brand (pageStartIndex = brand
# offset, pageSize = brand count, opts['brands'] = explicit list). This patch
# teaches syncIdentity to use that: adapters declaring brand paging get a
# fetch-write-release loop, a few brands at a time; HLC and BTI keep their
# existing single-fetch path byte-for-byte.
#
# Detection is an explicit method on the adapter, not a code check on 'QBP' —
# the next distributor with a big catalog opts in by declaring it.
set -e

python3 <<'PY'
import io

# ---------------------------------------------------------------- adapter
p = 'app/Services/Distributors/QbpClient.php'
s = io.open(p, encoding='utf-8').read()

assert 'MARKER-QBP-SYNC' not in s, 'already applied'

old = """    public function code(): string
    {
        return 'QBP';
    }"""
assert s.count(old) == 1, 'A1 code anchor'
s = s.replace(old, """    public function code(): string
    {
        return 'QBP';
    }

    /**
     * MARKER-QBP-SYNC — tells syncIdentity to page products() by brand
     * rather than fetching the catalog in one call. One brand measured 7 MB
     * of XML; 892 brands in one array is an OOM, not a sync.
     */
    public function pagesByBrand(): bool
    {
        return true;
    }""")

io.open(p, 'w', encoding='utf-8').write(s)
print('patched', p)

# ---------------------------------------------------------------- sync
p = 'app/Services/Distributors/DistributorCatalogSyncService.php'
s = io.open(p, encoding='utf-8').read()

old = """        // HLC's Catalog/Products ignores pageStartIndex on the public API (every
        // offset returns the first page), but it honours pageSize: a pageSize at
        // or above the catalog total returns the whole catalog in one response.
        // So pull once and process in chunks, checkpointing every 200 rows so the
        // live counter still climbs. ($maxPages is unused — offset paging is dead.)
        try {"""
assert s.count(old) == 1, 'S1 fetch anchor'
s = s.replace(old, """        // MARKER-QBP-SYNC — adapters with big catalogs page by brand instead
        // of one giant fetch. The adapter declares it; nothing here names a
        // distributor.
        if (method_exists($adapter, 'pagesByBrand') && $adapter->pagesByBrand()) {
            return $this->syncIdentityByBrand($adapter, $since, $res);
        }

        // HLC's Catalog/Products ignores pageStartIndex on the public API (every
        // offset returns the first page), but it honours pageSize: a pageSize at
        // or above the catalog total returns the whole catalog in one response.
        // So pull once and process in chunks, checkpointing every 200 rows so the
        // live counter still climbs. ($maxPages is unused — offset paging is dead.)
        try {""")

old = """        $this->recordState($code, $res);
        return $res;
    }
"""
# This tail appears in more than one method; anchor to the one that ends
# syncIdentity by including the preceding brand-loop close.
old = """            $this->setBrandStatus($code, $brandName, 'done', $brandWritten);
            $this->markProgress($code, $res['written']);
            unset($brandProducts);
            gc_collect_cycles();
        }

        $this->recordState($code, $res);
        return $res;
    }
"""
assert s.count(old) == 1, 'S2 method tail anchor'
s = s.replace(old, """            $this->setBrandStatus($code, $brandName, 'done', $brandWritten);
            $this->markProgress($code, $res['written']);
            unset($brandProducts);
            gc_collect_cycles();
        }

        $this->recordState($code, $res);
        return $res;
    }

    /**
     * MARKER-QBP-SYNC — fetch, write, release, one small page of brands at a
     * time. Memory stays flat at a few brands' worth no matter how large the
     * catalog is; progress and per-brand status behave exactly like the
     * single-fetch path.
     *
     * Chunk size 10: DRW measured 7 MB / ~1,000 products, so a page tops out
     * around 70 MB of XML before parsing — well inside a worker.
     */
    private function syncIdentityByBrand(DistributorAdapter $adapter, ?Carbon $since, array $res): array
    {
        $code = $res['code'];

        try {
            $brandList = $adapter->brands();
        } catch (\\Throwable $e) {
            $res['errors'][] = 'brand list: ' . $e->getMessage();
            $this->recordState($code, $res);
            return $res;
        }

        // Seed every brand as pending up front so the progress panel shows
        // the full run's shape immediately, matching the existing path.
        $pendingNames = [];
        foreach ($brandList as $b) {
            $pendingNames[$b['name']] = [];
        }
        ksort($pendingNames);
        $this->seedBrandStatuses($code, $pendingNames);
        unset($pendingNames);

        foreach (array_chunk($brandList, 10) as $chunk) {
            $ids = array_column($chunk, 'id');

            try {
                $batch = $adapter->products(['brands' => $ids]);
            } catch (\\Throwable $e) {
                $res['errors'][] = 'brands ' . implode(',', $ids) . ': ' . $e->getMessage();
                continue;
            }

            $products = $this->extractProducts($batch);
            unset($batch);
            $res['pages']++;

            $byBrand = [];
            foreach ($products as $product) {
                $byBrand[$product['Brand'] ?? 'Unknown'][] = $product;
            }
            unset($products);

            foreach ($byBrand as $brandName => $brandProducts) {
                $this->setBrandStatus($code, $brandName, 'syncing', null);
                $brandWritten = 0;

                foreach ($brandProducts as $product) {
                    foreach (($product['Variants'] ?? []) as $variant) {
                        $res['seen']++;
                        if ($since !== null && $this->isUnchanged($variant, $product, $since)) {
                            $res['skipped_delta']++;
                            continue;
                        }
                        try {
                            $this->upsertVariant($code, $adapter->name(), $variant, $product, $res);
                            $res['written']++;
                            $brandWritten++;
                        } catch (\\Throwable $e) {
                            $res['errors'][] = ($variant['sku'] ?? $variant['VariantNo'] ?? '?') . ': ' . $e->getMessage();
                        }
                        if ($brandWritten % 100 === 0) {
                            $this->setBrandStatus($code, $brandName, 'syncing', $brandWritten);
                            $this->markProgress($code, $res['written']);
                        }
                    }
                }

                $this->setBrandStatus($code, $brandName, 'done', $brandWritten);
                $this->markProgress($code, $res['written']);
            }

            unset($byBrand);
            gc_collect_cycles();
        }

        $this->recordState($code, $res);
        return $res;
    }
""")

io.open(p, 'w', encoding='utf-8').write(s)
print('patched', p)
PY

echo
echo "--- adapter declares, sync detects, nothing names QBP in the service ---"
grep -n "pagesByBrand" app/Services/Distributors/QbpClient.php app/Services/Distributors/DistributorCatalogSyncService.php | head -4
grep -c "'QBP'" app/Services/Distributors/DistributorCatalogSyncService.php

echo
echo "--- HLC/BTI path untouched: single fetch still present ---"
grep -n "pull once and process in chunks" app/Services/Distributors/DistributorCatalogSyncService.php

echo
echo "--- brand-paged loop structure ---"
python3 - <<'PY'
import io, re
s = io.open('app/Services/Distributors/DistributorCatalogSyncService.php', encoding='utf-8').read()
m = re.search(r'private function syncIdentityByBrand.*?\n    \}\n', s, re.S).group(0)
print('  chunks of 10 brands   :', 'array_chunk($brandList, 10)' in m)
print('  fetch inside the loop :', "products(['brands' => $ids])" in m)
print('  releases per chunk    :', m.count('gc_collect_cycles()') == 1 and 'unset($byBrand)' in m)
print('  seeds statuses upfront:', 'seedBrandStatuses' in m)
print('  uses same upsert      :', 'upsertVariant' in m)
print('  delta check kept      :', 'isUnchanged' in m)
PY

echo
echo "--- balance ---"
python3 - <<'PY'
import io
for p in ['app/Services/Distributors/QbpClient.php',
          'app/Services/Distributors/DistributorCatalogSyncService.php']:
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
    print('%-44s braces %d parens %d brackets %d' % (p.split('/')[-1], d, par, brk))
PY

echo
echo "apply-qbp-sync-loop: OK"
