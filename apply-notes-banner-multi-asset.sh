#!/usr/bin/env bash
# apply-notes-banner-multi-asset.sh
# MARKER-OLD-SCHOOL-BANNER-MA — the other appointment view.
#
# There are two: show.blade.php and show-multi-asset.blade.php. The controller
# picks the multi-asset one whenever the appointment has assets attached, which
# for a bike shop is nearly every appointment — so the banner was added to the
# view almost nobody sees.
#
# I already knew there were two; the send-confirmation button earlier in the
# week was deliberately applied to both. I did not apply that knowledge here,
# and "no banner" was the result.
#
# Placed after the page header rather than before it: this view opens with the
# RA number and status, which is how staff confirm they are on the right
# appointment at all. A note belongs immediately under that, not above it.
set -e

python3 <<'PY'
import io

p = 'resources/views/tenant/appointments/show-multi-asset.blade.php'
s = io.open(p, encoding='utf-8').read()

assert 'MARKER-OLD-SCHOOL-BANNER-MA' not in s, 'already applied'
assert 'MARKER-PATCH-158-G8' in s, 'unexpected view state'

old = """@section('content')
{{-- MARKER-PATCH-158-G8 — Removed max-width:1400px + margin:0 auto. No other tenant page uses centered wrapper; this one shouldn't either. --}}
<div class="ia-page" id="ma-appt" style="padding: 24px 28px 60px;">
"""
assert s.count(old) == 1, 'M1 content open anchor'
s = s.replace(old, """@section('content')
{{-- MARKER-PATCH-158-G8 — Removed max-width:1400px + margin:0 auto. No other tenant page uses centered wrapper; this one shouldn't either. --}}
{{-- MARKER-OLD-SCHOOL-BANNER-MA — this view renders whenever the appointment
     has assets, which is most of them. $noteCustomer also makes the pad
     button pre-attach this customer. --}}
@php $noteCustomer = $appointment->customer ?? null; @endphp
<div class="ia-page" id="ma-appt" style="padding: 24px 28px 60px;">

  @include('tenant._notes-banner', ['bannerCustomer' => $noteCustomer])
""")

io.open(p, 'w', encoding='utf-8').write(s)
print('patched', p)
PY

echo
echo "--- both appointment views now carry it ---"
for f in resources/views/tenant/appointments/show.blade.php resources/views/tenant/appointments/show-multi-asset.blade.php; do
  printf '  %-52s ' "$(basename $f)"
  grep -q "_notes-banner" "$f" && echo "banner ✓" || echo "MISSING"
done

echo
echo "--- \$noteCustomer set in both, so the pad pre-attaches ---"
grep -c "noteCustomer" resources/views/tenant/appointments/show.blade.php resources/views/tenant/appointments/show-multi-asset.blade.php

echo
echo "--- blade sweep ---"
python3 - <<'PY'
import io, re
pat = re.compile(r'\B@(@?\w+(?:::\w+)?)([ \t]*)(\()?', re.X)
OPEN  = {'if','unless','isset','auth','guest','forelse','foreach','for','while','php','section','error','once'}
CLOSE = {'endif','endunless','endisset','endempty','endauth','endguest','endforelse','endforeach','endfor','endwhile','endphp','endsection','enderror','endonce'}
f = 'resources/views/tenant/appointments/show-multi-asset.blade.php'
raw = io.open(f, encoding='utf-8').read()
s = re.sub(r'\{\{--.*?--\}\}', lambda m: ' '*len(m.group(0)), raw, flags=re.S)
glued = [m.start() for m in re.finditer(r'\w@(?:if|endif|foreach|endforeach|forelse|endforelse|else|elseif|php|endphp|include)\b', s)]
print('  glued:', len(glued))
for p2 in glued[:4]:
    print('    line', s[:p2].count('\n')+1, repr(s[max(0,p2-30):p2+12].replace('\n',' ')))
d = 0
for m in re.finditer(r'@(\w+)', s):
    if not pat.match(s, m.start()): continue
    if m.group(1) in OPEN: d += 1
    elif m.group(1) in CLOSE: d -= 1
print('  directive depth:', d, 'OK' if d == 0 else '*** CHECK ***')
for m in re.finditer(r'@php(.*?)@endphp', raw, re.S):
    if '{{--' in m.group(1):
        print('  *** blade comment inside @php ***')
PY

echo
echo "--- forms not nested ---"
python3 - <<'PY'
import io, re
s = io.open('resources/views/tenant/appointments/show-multi-asset.blade.php', encoding='utf-8').read()
d = 0; nested = False
for m in re.finditer(r'<form\b|</form>', s):
    d += -1 if m.group(0) == '</form>' else 1
    if d > 1: nested = True
print('  balanced=%s nested=%s' % (d == 0, nested))
PY

echo
echo "apply-notes-banner-multi-asset: OK"
