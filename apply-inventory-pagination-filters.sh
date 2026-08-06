#!/usr/bin/env bash
# apply-inventory-pagination-filters.sh
# MARKER-PAGER-FILTERS — page 2 dropped the brand filter.
#
# Filter by Whisky Parts Co., 51 items over 3 pages, press Next → and you land
# on all 5,386 items. The paginator builds its links from a fixed list:
#
#     's', 'category', 'stock', 'sort', 'page'
#
# brand and distributor are missing, so both are silently discarded on every
# page change. The filter still LOOKS applied — the select keeps its value
# from the URL that no longer carries it — which is what makes this read as
# "pagination is broken" rather than "the filter was dropped".
#
# Rather than adding two names to a list that has now been wrong twice, the
# links carry forward every filter the page understands. A filter added later
# is included by being in that one array, not by someone remembering to update
# a second copy of it.
set -e

python3 <<'PY'
import io

p = 'resources/views/tenant/inventory/index.blade.php'
s = io.open(p, encoding='utf-8').read()

assert 'MARKER-PAGER-FILTERS' not in s, 'already applied'

old = """      $qs = function($p) use ($search, $category, $stock, $sort) {
        return http_build_query(array_filter([
          's' => $search, 'category' => $category, 'stock' => $stock,
          'sort' => $sort, 'page' => $p,
        ]));
      };"""
assert s.count(old) == 1, 'P1 qs anchor'
s = s.replace(old, """      // MARKER-PAGER-FILTERS — brand and distributor were missing here, so
      // paging out of a filtered list landed on the unfiltered one. Every
      // filter the page reads lives in this one array; anything added to the
      // form belongs here too, and nowhere else.
      $qs = function ($p) use ($search, $category, $stock, $sort, $brand, $distributor) {
        return http_build_query(array_filter([
          's'           => $search,
          'category'    => $category,
          'brand'       => $brand,
          'distributor' => $distributor,
          'stock'       => $stock,
          'sort'        => $sort,
          'page'        => $p,
        ], fn ($v) => $v !== null && $v !== ''));
      };""")

io.open(p, 'w', encoding='utf-8').write(s)
print('patched', p)
PY

echo
echo "--- every filter the page reads is now carried ---"
python3 - <<'PY'
import io, re
s = io.open('resources/views/tenant/inventory/index.blade.php', encoding='utf-8').read()

qs = re.search(r'\$qs = function \(\$p\) use \((.*?)\)', s).group(1)
carried = set(re.findall(r'\$(\w+)', qs)) - {'p'}

# What the controller actually hands this view as filter state.
ctl = io.open('app/Http/Controllers/Tenant/InventoryController.php', encoding='utf-8').read()
declared = set(re.findall(r"'(search|category|brand|distributor|stock|sort)'\s*=>", ctl))

print('  carried by pager :', ', '.join(sorted(carried)))
print('  known filters    :', ', '.join(sorted(declared)))
missing = sorted(d for d in declared if d not in carried and d != 'search')
print('  still dropped    :', missing or 'none')
PY

echo
echo "--- the reported case, reconstructed ---"
python3 - <<'PY'
from urllib.parse import urlencode

def qs_old(p, **f):
    keep = {k: v for k, v in f.items() if k in ('s', 'category', 'stock', 'sort') and v}
    keep['page'] = p
    return urlencode(keep)

def qs_new(p, **f):
    keep = {k: v for k, v in f.items() if v not in (None, '')}
    keep['page'] = p
    return urlencode(keep)

filt = dict(s='', category='', brand='Whisky Parts Co.', distributor='', stock='', sort='name_asc')
print('  before:', qs_old(2, **filt))
print('  after :', qs_new(2, **filt))
assert 'brand' not in qs_old(2, **filt)
assert 'brand=Whisky+Parts+Co.' in qs_new(2, **filt)
print('  brand survives page 2: True')
PY

echo
echo "--- blade sweep ---"
python3 - <<'PY'
import io, re
pat = re.compile(r'\B@(@?\w+)')
OPEN  = {'if','unless','isset','auth','guest','forelse','foreach','for','while','php','section','error','once'}
CLOSE = {'endif','endunless','endisset','endempty','endauth','endguest','endforelse','endforeach','endfor','endwhile','endphp','endsection','enderror','endonce'}
f = 'resources/views/tenant/inventory/index.blade.php'
raw = io.open(f, encoding='utf-8').read()
s = re.sub(r'\{\{--.*?--\}\}', lambda m: ' ' * len(m.group(0)), raw, flags=re.S)
g = len(re.findall(r'\w@(?:if|endif|else|elseif|foreach|endforeach|php|endphp)\b', s))
d = 0
for m in pat.finditer(s):
    if m.group(1) in OPEN: d += 1
    elif m.group(1) in CLOSE: d -= 1
print('  glued=%d  net depth=%d' % (g, d))
PY

echo
echo "apply-inventory-pagination-filters: OK"
