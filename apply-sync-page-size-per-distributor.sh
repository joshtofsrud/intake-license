#!/usr/bin/env bash
# apply-sync-page-size-per-distributor.sh
# MARKER-SYNC-PAGE-SIZE — give BTI real headroom, and make the truncation
# warning name the distributor that tripped it.
#
# 8000 was never a deliberate cap. It is HLC's shorthand for "everything":
# HLC ignores pageStartIndex but honours pageSize, so a number above their
# product count returns the whole catalog in one pull.
#
# BTI now windows by product too, and its feed is 7,746 groups — 254 short
# of the cap. A few hundred new products and it truncates again, and the
# only sign would be an error message telling you to raise an env var that
# has nothing to do with BTI.
#
# NOT raising the shared default. Sending HLC a much larger pageSize is an
# untested change against a live third-party API, and there is no reason to
# risk their sync to fix BTI's headroom. Instead the size becomes
# per-distributor config, defaulting to the current 8000 — so HLC's
# behaviour is byte-for-byte unchanged — and BTI gets its own value.
#
# The guard message now names the distributor and the actual env var to
# raise. The old text sent you to HLC_API_PAGE_SIZE no matter who tripped
# it, which is precisely the kind of confidently-wrong signpost that cost
# hours on the Range comment.
#
# Service + config: deploy with /root/intake-deploy.sh as usual.
set -e

python3 <<'PY'
import io

# ---------------------------------------------------------------- config
p = 'config/distributors.php'
s = io.open(p, encoding='utf-8').read()

old = """        'page_size'      => (int) env('BTI_PAGE_SIZE', 2000),"""
assert s.count(old) == 1, 'C1 bti page_size anchor'
s = s.replace(old, """        'page_size'      => (int) env('BTI_PAGE_SIZE', 2000),
        // MARKER-SYNC-PAGE-SIZE — how many PRODUCTS one tier-1 pull may
        // return. Distinct from page_size above, which is the feed reader's
        // chunk hint. The feed is ~7,750 product groups; 25000 is headroom,
        // not a target — BtiClient reads a local cached file, so a larger
        // window costs nothing.
        'sync_page_size' => (int) env('BTI_SYNC_PAGE_SIZE', 25000),""")

io.open(p, 'w', encoding='utf-8').write(s)
print('patched', p)

# ---------------------------------------------------------------- service
p = 'app/Services/Distributors/DistributorCatalogSyncService.php'
s = io.open(p, encoding='utf-8').read()

old = """            $batch = $adapter->products(['pageStartIndex' => 1, 'pageSize' => $pageSize]);"""
assert s.count(old) == 1, 'S1 products call anchor'
s = s.replace(old, """            // MARKER-SYNC-PAGE-SIZE — per-distributor override, falling back to
            // the caller's value. HLC keeps 8000 (its API is asked for this
            // number, so changing it is a live third-party behaviour change);
            // BTI sets its own, because it reads a local file and 8000 leaves
            // barely 250 products of headroom.
            $pageSize = (int) config(
                'distributors.' . strtolower($code) . '.sync_page_size',
                $pageSize
            );

            $batch = $adapter->products(['pageStartIndex' => 1, 'pageSize' => $pageSize]);""")

old = """            $res['errors'][] = \"catalog returned the full pageSize ({$pageSize}); it may be truncated — raise HLC_API_PAGE_SIZE.\";"""
assert s.count(old) == 1, 'S2 guard message anchor'
s = s.replace(old, """            // MARKER-SYNC-PAGE-SIZE — name the distributor and the env var that
            // actually applies. The old text said HLC_API_PAGE_SIZE whoever
            // tripped it, which sends the next reader to the wrong knob.
            $envVar = strtoupper($code) . '_SYNC_PAGE_SIZE';
            $res['errors'][] = \"{$code} returned the full pageSize ({$pageSize}); the catalog is probably larger than one pull — raise {$envVar}.\";""")

io.open(p, 'w', encoding='utf-8').write(s)
print('patched', p)
PY

echo "--- wiring ---"
grep -n "sync_page_size\|SYNC_PAGE_SIZE" config/distributors.php app/Services/Distributors/DistributorCatalogSyncService.php

echo
echo "--- balance ---"
python3 - <<'PY'
import io
def bal(p):
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
    return d, par
for f in ['config/distributors.php', 'app/Services/Distributors/DistributorCatalogSyncService.php']:
    print(f, bal(f))
PY

echo
echo "apply-sync-page-size-per-distributor: OK"
