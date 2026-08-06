#!/usr/bin/env bash
# apply-cls-tenant-and-view.sh
# MARKER-CLS-RENDER — the CLS key saves, and QBP images appear.
#
# TWO GAPS, found by reading rather than assuming this time.
#
# 1. The tenant connection page DOES build its inputs from the registry, so
#    the CLS field already renders there. But saveKey() validates an explicit
#    list — api_key, username, password, account_number — and Laravel drops
#    anything absent from it. cls_key would have rendered, accepted a paste,
#    and silently saved nothing.
#
# 2. The view expects each image to be a URL: it reads url/Url/path off an
#    array, or takes a plain string as-is. HLC and BTI supply URLs so that has
#    always worked. QBP supplies FILE NAMES — "TR00172.jpg" — which the
#    browser cannot resolve, so a filename rendered as a broken image.
#
#    QBP images are built as {cls_image_url}/{size}/{FILENAME}, using the
#    prefix stored on THIS tenant's subscription. The prefix embeds their own
#    Image Service ID, so one shop's images can never be served from another's
#    licence. No CLS key means no prefix means the media card says so plainly
#    rather than showing broken frames.
#
# HOTLINKED, NEVER DOWNLOADED — the CLS agreement requires these URLs be the
# only display mechanism. Confirmed working against a real product:
#   https://images.qbp.com/imageservice/image/<serviceId>/p350x350m/TR00172.jpg
set -e

python3 <<'PY'
import io

# ---------------------------------------------------------------- validation
p = 'app/Http/Controllers/Tenant/DistributorController.php'
s = io.open(p, encoding='utf-8').read()

assert 'MARKER-CLS-RENDER' not in s, 'already applied'

old = """            'api_key'          => ['nullable', 'string', 'max:255'],"""
assert s.count(old) == 1, 'V1 validation anchor'
s = s.replace(old, """            'api_key'          => ['nullable', 'string', 'max:255'],
            // MARKER-CLS-RENDER — QBP's second key. Absent from this list it
            // was stripped before packCredentials ever saw it, so the field
            // rendered, accepted a paste, and saved nothing.
            'cls_key'          => ['nullable', 'string', 'max:255'],""")

io.open(p, 'w', encoding='utf-8').write(s)
print('patched', p)

# ---------------------------------------------------------------- view
p = 'resources/views/tenant/inventory/show.blade.php'
s = io.open(p, encoding='utf-8').read()

old = """  $catImages = $item->distributorCatalog?->images ?? [];"""
assert s.count(old) == 1, 'W1 catImages anchor'
s = s.replace(old, """  $catImages = $item->distributorCatalog?->images ?? [];

  // MARKER-CLS-RENDER — QBP gives file names, not URLs. The URL prefix
  // belongs to THIS tenant's CLS subscription (it embeds their Image Service
  // ID), so it is read per tenant and never shared. Licence requires
  // hotlinking: these URLs are the only permitted display mechanism.
  $catCode  = $item->distributorCatalog?->distributor_code;
  $clsPrefix = null;
  if ($catCode === 'QBP') {
      $clsPrefix = \\App\\Models\\Tenant\\TenantDistributorCatalogSubscription::query()
          ->where('tenant_id', tenant()->id)
          ->where('distributor_code', 'QBP')
          ->value('cls_image_url');
  }
  $clsSize = config('distributors.qbp_cls.image_size', 'p350x350m');""")

old = """          $imgSrcs = collect($catImages)->map(function ($img) {
            return is_array($img) ? ($img['url'] ?? $img['Url'] ?? $img['path'] ?? null) : (is_string($img) ? $img : null);
          })->filter()->values();
        @endphp
        @if($imgSrcs->isNotEmpty())"""
assert s.count(old) == 1, 'W2 imgSrcs anchor'
s = s.replace(old, """          $imgSrcs = collect($catImages)->map(function ($img) use ($clsPrefix, $clsSize) {
            $raw = is_array($img)
                ? ($img['url'] ?? $img['Url'] ?? $img['path'] ?? $img['fileName'] ?? null)
                : (is_string($img) ? $img : null);

            if (! is_string($raw) || trim($raw) === '') {
                return null;
            }
            $raw = trim($raw);

            // Already a URL (HLC, BTI) — leave it alone.
            if (str_starts_with($raw, 'http://') || str_starts_with($raw, 'https://') || str_starts_with($raw, '//')) {
                return $raw;
            }

            // A bare file name (QBP) needs this tenant's CLS prefix. Without
            // one there is nothing to build, and a filename in a src attribute
            // is just a broken image.
            return $clsPrefix
                ? \\App\\Services\\Distributors\\QbpClsClient::imageUrl($clsPrefix, $clsSize, $raw)
                : null;
          })->filter()->values();

          // Names present but no licence to display them — worth saying,
          // because "no image" and "no CLS key" have different fixes.
          $imagesNeedCls = $imgSrcs->isEmpty() && ! empty($catImages) && $catCode === 'QBP' && ! $clsPrefix;
        @endphp
        @if($imgSrcs->isNotEmpty())""")

old = """        @else
          <div class="ia-media-empty">No image from the distributor catalog.</div>
        @endif"""
assert s.count(old) == 1, 'W3 empty state anchor'
s = s.replace(old, """        @elseif($imagesNeedCls)
          <div class="ia-media-empty">
            {{ count($catImages) }} QBP image{{ count($catImages) === 1 ? '' : 's' }} available — add your QBP
            Content License Service key under Connection &amp; sync to display them.
          </div>
        @else
          <div class="ia-media-empty">No image from the distributor catalog.</div>
        @endif""")

io.open(p, 'w', encoding='utf-8').write(s)
print('patched', p)
PY

echo
echo "--- cls_key survives validation ---"
grep -n "'cls_key'" app/Http/Controllers/Tenant/DistributorController.php

echo
echo "--- view builds QBP URLs, leaves HLC/BTI alone ---"
python3 - <<'PY'
# Mirror the Blade closure.
def to_src(img, prefix, size):
    raw = None
    if isinstance(img, dict):
        raw = img.get('url') or img.get('Url') or img.get('path') or img.get('fileName')
    elif isinstance(img, str):
        raw = img
    if not isinstance(raw, str) or not raw.strip(): return None
    raw = raw.strip()
    if raw.startswith(('http://', 'https://', '//')): return raw
    if not prefix: return None
    f = raw
    if '.' in f:
        i = f.rindex('.'); f = f[:i].upper() + f[i:].lower()
    else:
        f = f.upper()
    return prefix.rstrip('/') + '/' + size + '/' + f

pre = 'https://images.qbp.com/imageservice/image/bc146e3d8d3a'
cases = [
    ('HLC url string',  'https://cdn.hlc.example/x.jpg', pre, 'https://cdn.hlc.example/x.jpg'),
    ('BTI url in array', {'url': 'https://bti.example/y.jpg'}, pre, 'https://bti.example/y.jpg'),
    ('QBP file name',   'TR00172.jpg', pre, pre + '/p350x350m/TR00172.jpg'),
    ('QBP lowercase',   'tr00172.jpg', pre, pre + '/p350x350m/TR00172.jpg'),
    ('QBP, no licence', 'TR00172.jpg', None, None),
    ('empty',           '', pre, None),
]
for label, img, prefix, want in cases:
    got = to_src(img, prefix, 'p350x350m')
    print('  %-16s -> %s' % (label, got if got else '(none)'))
    assert got == want, (label, got, want)
print('  all shapes OK')
PY

echo
echo "--- the no-key state is distinguished from no-images ---"
grep -n "imagesNeedCls" resources/views/tenant/inventory/show.blade.php | head -3

echo
echo "--- nothing in the view downloads an image ---"
python3 - <<'PY'
import io
s = io.open('resources/views/tenant/inventory/show.blade.php', encoding='utf-8').read()
bad = [k for k in ['file_get_contents', 'Storage::', 'curl_', 'Http::get'] if k in s]
print('  fetch/store calls:', bad or 'none')
assert not bad
PY

echo
echo "--- blade sweep ---"
python3 - <<'PY'
import io, re
pat = re.compile(r'\B@(@?\w+(?:::\w+)?)', re.X)
OPEN  = {'if','unless','isset','auth','guest','forelse','foreach','for','while','php','section','error','once'}
CLOSE = {'endif','endunless','endisset','endempty','endauth','endguest','endforelse','endforeach','endfor','endwhile','endphp','endsection','enderror','endonce'}
f = 'resources/views/tenant/inventory/show.blade.php'
raw = io.open(f, encoding='utf-8').read()
s = re.sub(r'\{\{--.*?--\}\}', lambda m: ' ' * len(m.group(0)), raw, flags=re.S)
g = len(re.findall(r'\w@(?:if|endif|elseif|else|foreach|endforeach|php|endphp)\b', s))
d = 0
for m in pat.finditer(s):
    if m.group(1) in OPEN: d += 1
    elif m.group(1) in CLOSE: d -= 1
print('  glued=%d  net depth=%d' % (g, d))
PY

echo
echo "--- balance ---"
python3 - <<'PY'
import io
s = io.open('app/Http/Controllers/Tenant/DistributorController.php', encoding='utf-8').read()
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
print('DistributorController braces', d, 'parens', par, 'brackets', brk)
PY

echo
echo "apply-cls-tenant-and-view: OK"
