#!/usr/bin/env bash
# apply-pad-mobile-position.sh
# MARKER-PAD-MOBILE-POS — pad outermost, matched to the bell.
#
# The mobile header does not lay its right-hand icons out in a row — each is
# absolutely positioned from the right edge (bell at right:10px, the offline
# status pill at right:54px). So "to the right of the bell" is not a reorder;
# it is a re-offset of all three, or they overlap.
#
#   pad   right: 10px   ← now outermost
#   bell  right: 56px   (10 + 38 wide + 8 gap)
#   pill  right: 102px  (56 + 38 wide + 8 gap)
#
# Sizing matched to the bell: 38x38 with a 20px icon and a 10px radius,
# against the pad's desktop 36x36/17px. The size only changes below 1024px,
# where the desktop attention row is hidden anyway, so the two instances
# never disagree on screen.
#
# The badge is nudged to match the bell's too — an 18px circle at the same
# offsets, so two adjacent counts line up rather than sitting a pixel apart.
set -e

python3 <<'PY'
import io

# ---------------------------------------------------------------- order
p = 'resources/views/layouts/tenant/_mobile-header.blade.php'
s = io.open(p, encoding='utf-8').read()

assert 'MARKER-PAD-MOBILE-POS' not in s, 'already applied'

old = """    {{-- MARKER-PAD-MOBILE — the pad, beside the bell. A scratch pad matters
         more on the phone than at the desk. --}}
    @include('layouts.tenant._notes-pad')

"""
assert s.count(old) == 1, 'O1 pad include anchor'
s = s.replace(old, '')

old = """      @if($mhdrAlertsUnread > 0)<span class="ia-mobile-header-bell-badge">{{ $mhdrAlertsUnread > 99 ? '99+' : $mhdrAlertsUnread }}</span>@endif
    </a>"""
assert s.count(old) == 1, 'O2 bell close anchor'
s = s.replace(old, """      @if($mhdrAlertsUnread > 0)<span class="ia-mobile-header-bell-badge">{{ $mhdrAlertsUnread > 99 ? '99+' : $mhdrAlertsUnread }}</span>@endif
    </a>

    {{-- MARKER-PAD-MOBILE-POS — after the bell in the markup and outermost on
         screen. These are absolutely positioned, so DOM order alone decides
         nothing; the offsets below do. --}}
    @include('layouts.tenant._notes-pad')""")

# The offline pill has to clear both.
old = """<span id="ioMountMobile" style="position:absolute;right:54px;top:50%;transform:translateY(-50%);display:inline-flex;align-items:center"></span>"""
assert s.count(old) == 1, 'O3 offline pill anchor'
s = s.replace(old, """<span id="ioMountMobile" style="position:absolute;right:102px;top:50%;transform:translateY(-50%);display:inline-flex;align-items:center"></span>""")

io.open(p, 'w', encoding='utf-8').write(s)
print('patched', p)

# ---------------------------------------------------------------- bell offset
p = 'public/css/tenant/mobile-nav.css'
s = io.open(p, encoding='utf-8').read()

old = """  .ia-mobile-header-bell {
    position: absolute;
    right: 10px;"""
assert s.count(old) == 1, 'O4 bell css anchor'
s = s.replace(old, """  /* MARKER-PAD-MOBILE-POS — the notes pad now sits outermost at right:10px,
     so the bell moves in by one slot: 10 + 38 wide + 8 gap. */
  .ia-mobile-header-bell {
    position: absolute;
    right: 56px;""")

io.open(p, 'w', encoding='utf-8').write(s)
print('patched', p)

# ---------------------------------------------------------------- pad sizing
p = 'resources/views/layouts/tenant/_notes-pad.blade.php'
s = io.open(p, encoding='utf-8').read()

old = """  .pad-foot:hover { background:#E5DAC0; }"""
assert s.count(old) == 1, 'O5 pad style tail anchor'
s = s.replace(old, """  .pad-foot:hover { background:#E5DAC0; }

  /* MARKER-PAD-MOBILE-POS — below 1024px the mobile header is the one on
     screen (the desktop attention row is hidden), so this only ever restyles
     the visible instance. Matched to .ia-mobile-header-bell: 38x38, 10px
     radius, 20px icon, and the same absolute anchoring from the right edge —
     that header lays its icons out by offset, not by flow. */
  @media (max-width: 1023px) {
    .pad {
      position: absolute;
      right: 10px;
      top: 50%;
      transform: translateY(-50%);
      display: flex;
      align-items: center;
    }
    .pad-btn {
      width: 38px;
      height: 38px;
      border-radius: 10px;
      opacity: 1;
      color: rgba(255, 255, 255, .9);
    }
    body.ia-theme-b .pad-btn { color: rgba(0, 0, 0, .78); }
    .pad-btn:active { background: rgba(127, 127, 127, .12); }
    .pad-btn svg { width: 20px; height: 20px; }
    /* Same 18px circle the bell badge uses, so two adjacent counts sit level. */
    .pad-badge {
      min-width: 18px;
      height: 18px;
      top: 2px;
      right: 2px;
      font-size: 10.5px;
    }
  }""")

io.open(p, 'w', encoding='utf-8').write(s)
print('patched', p)
PY

echo
echo "--- markup order: pad after the bell ---"
python3 - <<'PY'
import io
s = io.open('resources/views/layouts/tenant/_mobile-header.blade.php', encoding='utf-8').read()
bell = s.index('ia-mobile-header-bell')
pad  = s.index("@include('layouts.tenant._notes-pad')")
print('  bell at', bell, '· pad at', pad, '→', 'pad after bell' if pad > bell else '*** pad still before ***')
PY

echo
echo "--- the three offsets do not overlap ---"
python3 - <<'PY'
import io, re
css = io.open('public/css/tenant/mobile-nav.css', encoding='utf-8').read()
bell = re.search(r'\.ia-mobile-header-bell \{.*?right: (\d+)px', css, re.S).group(1)
hdr  = io.open('resources/views/layouts/tenant/_mobile-header.blade.php', encoding='utf-8').read()
pill = re.search(r'ioMountMobile[^>]*right:(\d+)px', hdr).group(1)
pad  = re.search(r'\.pad \{\s*position: absolute;\s*right: (\d+)px', 
                 io.open('resources/views/layouts/tenant/_notes-pad.blade.php', encoding='utf-8').read()).group(1)
items = [('pad', int(pad), 38), ('bell', int(bell), 38), ('pill', int(pill), 38)]
items.sort(key=lambda x: x[1])
ok = True
for i in range(len(items) - 1):
    n, r, w = items[i]
    n2, r2, _ = items[i + 1]
    gap = r2 - (r + w)
    print('  %-5s right:%-4d → %-5s right:%-4d   gap %dpx' % (n, r, n2, r2, gap))
    if gap < 0: ok = False
print('  ', 'no overlap, even spacing' if ok else '*** OVERLAP ***')
PY

echo
echo "--- blade sweep ---"
python3 - <<'PY'
import io, re
pat = re.compile(r'\B@(@?\w+(?:::\w+)?)([ \t]*)(\()?', re.X)
OPEN  = {'if','unless','isset','auth','guest','forelse','foreach','for','while','php','section','error','once'}
CLOSE = {'endif','endunless','endisset','endempty','endauth','endguest','endforelse','endforeach','endfor','endwhile','endphp','endsection','enderror','endonce'}
for f in ['resources/views/layouts/tenant/_mobile-header.blade.php',
          'resources/views/layouts/tenant/_notes-pad.blade.php']:
    raw = io.open(f, encoding='utf-8').read()
    s = re.sub(r'\{\{--.*?--\}\}', lambda m: ' '*len(m.group(0)), raw, flags=re.S)
    g = len(re.findall(r'\w@(?:if|endif|foreach|endforeach|else|elseif|php|endphp|once|endonce|include)\b', s))
    d = 0
    for m in re.finditer(r'@(\w+)', s):
        if not pat.match(s, m.start()): continue
        if m.group(1) in OPEN: d += 1
        elif m.group(1) in CLOSE: d -= 1
    print('  %-38s glued=%d depth=%d %s' % (f.split('/')[-1], g, d, 'OK' if (g==0 and d==0) else '*** CHECK ***'))
PY

echo
echo "--- css braces balance ---"
python3 -c "
import io
s = io.open('public/css/tenant/mobile-nav.css', encoding='utf-8').read()
print('  mobile-nav.css braces:', s.count('{') - s.count('}'))
s2 = io.open('resources/views/layouts/tenant/_notes-pad.blade.php', encoding='utf-8').read()
import re
css = re.findall(r'<style>(.*?)</style>', s2, flags=re.S)[0]
print('  pad style braces:', css.count('{') - css.count('}'))
"

echo
echo "apply-pad-mobile-position: OK"
