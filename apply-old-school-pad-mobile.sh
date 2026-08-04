#!/usr/bin/env bash
# apply-old-school-pad-mobile.sh
# MARKER-PAD-MOBILE — the pad on phones.
#
# It was only ever in _attention-row.blade.php, which is the DESKTOP header.
# Phones render _mobile-header.blade.php instead, so the pad simply was not
# there — on the surface where a scratch pad is most useful.
#
# Both headers now include it, which makes the partial render TWICE on a page
# (CSS shows one, but both are in the DOM). The original script used
# querySelector, binding only the first — so on mobile the button would have
# been inert while a hidden desktop panel got all the wiring. It now binds
# every instance, and the shared style/script block is wrapped in @once so it
# is emitted a single time.
#
# The badge count and note list are per-instance by nature, so nothing else
# needs to change.
set -e

python3 <<'PY'
import io

# ---------------------------------------------------------------- mobile header
p = 'resources/views/layouts/tenant/_mobile-header.blade.php'
s = io.open(p, encoding='utf-8').read()

assert 'MARKER-PAD-MOBILE' not in s, 'already applied'

old = """    {{-- MARKER-PATCH-363 — alerts bell -> full notifications page, with unread badge --}}
    <a href="{{ route('tenant.notifications') }}" class="ia-mobile-header-bell\""""
assert s.count(old) == 1, 'M1 mobile bell anchor'
s = s.replace(old, """    {{-- MARKER-PAD-MOBILE — the pad, beside the bell. A scratch pad matters
         more on the phone than at the desk. --}}
    @include('layouts.tenant._notes-pad')

    {{-- MARKER-PATCH-363 — alerts bell -> full notifications page, with unread badge --}}
    <a href="{{ route('tenant.notifications') }}" class="ia-mobile-header-bell\"""")

io.open(p, 'w', encoding='utf-8').write(s)
print('patched', p)

# ---------------------------------------------------------------- pad partial
p = 'resources/views/layouts/tenant/_notes-pad.blade.php'
s = io.open(p, encoding='utf-8').read()

# 1. bind every instance rather than the first
old = """( function () {
  var wrap = document.querySelector( '[data-pad]' );
  if ( !wrap ) { return; }
  var btn   = wrap.querySelector( '[data-pad-toggle]' );
  var panel = wrap.querySelector( '[data-pad-panel]' );
  if ( !btn || !panel ) { return; }"""
assert s.count(old) == 1, 'P1 iife head anchor'
s = s.replace(old, """/* MARKER-PAD-MOBILE — the partial renders in BOTH headers, so there are two
   instances in the DOM and CSS decides which is visible. querySelector bound
   only the first, which left the mobile button dead while a hidden desktop
   panel held all the wiring. Bind each one. */
( function () {
  document.querySelectorAll( '[data-pad]' ).forEach( function ( wrap ) { bindPad( wrap ); } );

  function bindPad( wrap ) {
  var btn   = wrap.querySelector( '[data-pad-toggle]' );
  var panel = wrap.querySelector( '[data-pad-panel]' );
  if ( !btn || !panel ) { return; }""")

# The IIFE's tail depends on which of the earlier patches appended last, so
# close the new bindPad() function at the LAST `}() );` in the script rather
# than matching whatever block happens to sit above it.
old = """  }
}() );
</script>"""
assert s.count(old) == 1, 'P2 iife tail anchor'
s = s.replace(old, """  }
  }
}() );
</script>""")

# 2. styles and script once, markup per instance
old = """<style>
  .pad { position:relative; }"""
assert s.count(old) == 1, 'P3 style open anchor'
s = s.replace(old, """{{-- MARKER-PAD-MOBILE — markup renders per instance; these do not. --}}
@once
<style>
  .pad { position:relative; }""")

old = """}() );
</script>"""
assert s.count(old) == 1, 'P4 script close anchor'
s = s.replace(old, """}() );
</script>
@endonce""")

io.open(p, 'w', encoding='utf-8').write(s)
print('patched', p)
PY

echo
echo "--- included in both headers ---"
for f in resources/views/layouts/tenant/_attention-row.blade.php resources/views/layouts/tenant/_mobile-header.blade.php; do
  printf '  %-46s ' "$(basename $f)"
  grep -q "_notes-pad" "$f" && echo "pad ✓" || echo "MISSING"
done

echo
echo "--- every instance is bound, styles emitted once ---"
python3 - <<'PY'
import io
s = io.open('resources/views/layouts/tenant/_notes-pad.blade.php', encoding='utf-8').read()
print('  querySelectorAll used :', 'querySelectorAll( \'[data-pad]\' )' in s)
print('  querySelector on wrap :', "document.querySelector( '[data-pad]' )" in s, '(should be False)')
print('  @once wraps style/js  :', s.count('@once') == 1 and s.count('@endonce') == 1)
PY

echo
echo "--- js parses ---"
python3 - <<'PY'
import io, re, subprocess, os
raw = io.open('resources/views/layouts/tenant/_notes-pad.blade.php', encoding='utf-8').read()
js = re.findall(r'<script[^>]*>(.*?)</script>', raw, flags=re.S)[0]
out, i = [], 0
while i < len(js):
    if js.startswith('@json(', i):
        d = 0; j = i + 5
        while j < len(js):
            if js[j] == '(': d += 1
            elif js[j] == ')':
                d -= 1
                if d == 0: break
            j += 1
        out.append('"/x"'); i = j + 1
    else:
        out.append(js[i]); i += 1
os.makedirs('/tmp/pad7', exist_ok=True)
io.open('/tmp/pad7/p.js','w',encoding='utf-8').write(''.join(out))
r = subprocess.run(['node','--check','/tmp/pad7/p.js'], capture_output=True, text=True)
print('  pad JS:', 'OK' if r.returncode==0 else 'FAIL\n'+r.stderr[:400])
PY

echo
echo "--- blade sweep ---"
python3 - <<'PY'
import io, re
pat = re.compile(r'\B@(@?\w+(?:::\w+)?)([ \t]*)(\()?', re.X)
OPEN  = {'if','unless','isset','auth','guest','forelse','foreach','for','while','php','section','error','once'}
CLOSE = {'endif','endunless','endisset','endempty','endauth','endguest','endforelse','endforeach','endfor','endwhile','endphp','endsection','enderror','endonce'}
for f in ['resources/views/layouts/tenant/_notes-pad.blade.php',
          'resources/views/layouts/tenant/_mobile-header.blade.php']:
    raw = io.open(f, encoding='utf-8').read()
    s = re.sub(r'\{\{--.*?--\}\}', lambda m: ' '*len(m.group(0)), raw, flags=re.S)
    g = len(re.findall(r'\w@(?:if|endif|foreach|endforeach|forelse|endforelse|else|elseif|php|endphp|once|endonce|include|csrf)\b', s))
    d = 0
    for m in re.finditer(r'@(\w+)', s):
        if not pat.match(s, m.start()): continue
        if m.group(1) in OPEN: d += 1
        elif m.group(1) in CLOSE: d -= 1
    print('  %-38s glued=%d depth=%d %s' % (f.split('/')[-1], g, d, 'OK' if (g==0 and d==0) else '*** CHECK ***'))
PY

echo
echo "apply-old-school-pad-mobile: OK"
