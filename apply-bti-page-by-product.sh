#!/usr/bin/env bash
# apply-bti-page-by-product.sh
# MARKER-BTI-PAGE-BY-PRODUCT — the BTI catalog has been importing ~1/3 of
# itself, silently.
#
# DistributorCatalogSyncService::syncIdentity() asks the adapter for
# pageSize=8000 and treats the answer as PRODUCTS — that's HLC's contract,
# where 8000 products carry roughly 48k variants and nothing is lost.
#
# BtiClient::products() applied that number to ROWS instead: it appended
# feed rows, broke at 8000, and only then grouped them. The full feed is
# 24,685 rows, so every catalog sync imported the first ~32% and stopped.
#
# It stayed invisible because of two things that mask each other:
#
#   * the truncation guard reads `count($products) >= $pageSize`. BTI's
#     8000 rows collapse into far fewer products, so the "may be truncated"
#     warning could never fire — the check is correct for HLC and
#     meaningless for a row-capped adapter.
#
#   * platform_distributor_catalogs uses updateOrCreate, so it accumulates
#     the union of every sync ever run, while distributor_brand_sync_status
#     is deleted and reseeded from the current run's slice. Coverage and the
#     tier-2 picker therefore keep showing brands the latest run never
#     reached. That is exactly how Maxxis went missing from the brand list
#     while still appearing everywhere else.
#
# The fix is to honour the contract the service actually has: count
# PRODUCTS, not rows. pageStartIndex offsets by product too, which BTI
# already supports (unlike HLC, where offset paging is dead).
#
# Deliberately NOT breaking out of the loop on the upper bound. Rows for one
# group are not guaranteed contiguous in the feed, and an early break would
# silently drop later variants of an included product — the same class of
# bug this patch exists to remove. The feed is a local cached file and 24k
# rows, so reading it through costs nothing worth saving.
#
# Service change: optimize:clear + fpm cycle, then re-run the BTI catalog
# sync. Expect the brand list to grow and stay stable across runs.
set -e

python3 <<'PY'
import io

p = 'app/Services/Distributors/BtiClient.php'
s = io.open(p, encoding='utf-8').read()

old = """        $start = max(0, (int) ($opts['pageStartIndex'] ?? 0));"""
assert s.count(old) == 1, 'B0 pageStartIndex anchor'
s = s.replace(old, """        // MARKER-BTI-PAGE-BY-PRODUCT — pageStartIndex is 1-BASED. That's HLC's
        // API convention and it is literally what syncIdentity() sends
        // (['pageStartIndex' => 1]). Read as a 0-based offset it would skip
        // the first product on every sync — harmless when the window was
        // rows, quietly lossy now that it's products. 0 and 1 both mean
        // "start at the beginning".
        $start = max(0, ((int) ($opts['pageStartIndex'] ?? 1)) - 1);""")

old = """        $out = [];
        $i = 0;

        foreach ($this->rows(true) as $r) {
            if ($filtered) {
                $hitUpc  = $upcs  && isset($upcs[$r['upc'] ?? '']);
                $hitPart = $parts && isset($parts[strtoupper(trim((string) ($r['vendor_item_id'] ?? '')))]);
                if (! $hitUpc && ! $hitPart) {
                    continue;
                }
            }

            if ($i++ < $start) {
                continue;
            }
            $out[] = $r;
            if (count($out) >= $size) {
                break;
            }
        }

        return $this->groupIntoProducts($out);"""
assert s.count(old) == 1, 'B1 products paging anchor'
s = s.replace(old, """        // MARKER-BTI-PAGE-BY-PRODUCT — page by PRODUCT, not by row.
        //
        // The caller's pageSize is HLC's contract: a count of products, each
        // carrying its own variants. Applying it to raw feed rows capped the
        // catalog at 8000 variants out of 24,685 and truncated every sync.
        $out = [];
        $groupPos = [];   // group id => its ordinal position in the feed
        $nextPos  = 0;

        foreach ($this->rows(true) as $r) {
            if ($filtered) {
                $hitUpc  = $upcs  && isset($upcs[$r['upc'] ?? '']);
                $hitPart = $parts && isset($parts[strtoupper(trim((string) ($r['vendor_item_id'] ?? '')))]);
                if (! $hitUpc && ! $hitPart) {
                    continue;
                }
            }

            // Same grouping key groupIntoProducts() uses, so the window here
            // and the products it returns can't disagree.
            $gid = ($r['group_id'] ?? '') !== '' ? $r['group_id'] : ($r['id'] ?? '');
            if ($gid === '') {
                continue; // groupIntoProducts drops these anyway
            }

            if (! array_key_exists($gid, $groupPos)) {
                $groupPos[$gid] = $nextPos++;
            }
            $pos = $groupPos[$gid];

            if ($pos < $start || $pos >= $start + $size) {
                // No break: a group's rows are not guaranteed contiguous, and
                // stopping early would drop later variants of a product that
                // IS in the window. The feed is a local cached file.
                continue;
            }

            $out[] = $r;
        }

        return $this->groupIntoProducts($out);""")

io.open(p, 'w', encoding='utf-8').write(s)
print('patched', p)
PY

echo "--- products() paging ---"
sed -n '/public function products/,/^    }$/p' app/Services/Distributors/BtiClient.php | grep -n "groupPos\|nextPos\|start\|size\|break\|continue\|out\[\]"

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
echo "apply-bti-page-by-product: OK"
