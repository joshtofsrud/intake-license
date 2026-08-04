#!/usr/bin/env bash
# apply-pad-bind-on-ready.sh
# MARKER-PAD-READY — desktop pad stopped opening.
#
# The mobile patch wrapped the pad's <style> and <script> in @once so they
# would not be emitted twice. @once emits at the FIRST include — the mobile
# header, which the layout includes at line 70 — and the desktop attention row
# renders later in the page.
#
# The script runs inline the moment the parser reaches it, so
# querySelectorAll('[data-pad]') matched only the mobile instance. The desktop
# one did not exist yet and was never bound: its button had no click handler,
# so nothing happened. Mobile kept working, which is why this looked like the
# mobile change breaking desktop when it was really the ordering.
#
# Binding now waits for DOM ready, at which point both instances exist. A
# data-pad-bound flag makes the pass idempotent, so a second call — from a
# future include, a Livewire swap, or a re-run — cannot attach a second set of
# listeners to the same button.
#
# This is the same class as the @push('styles') ordering trap that kept the
# customer picker out of the shared component: in a layout partial, anything
# that depends on the rest of the page being present has to wait for it.
set -e

python3 <<'PY'
import io

p = 'resources/views/layouts/tenant/_notes-pad.blade.php'
s = io.open(p, encoding='utf-8').read()

assert 'MARKER-PAD-READY' not in s, 'already applied'

old = """( function () {
  document.querySelectorAll( '[data-pad]' ).forEach( function ( wrap ) { bindPad( wrap ); } );

  function bindPad( wrap ) {"""
assert s.count(old) == 1, 'R1 iife head anchor'
s = s.replace(old, """( function () {
  /* MARKER-PAD-READY — wait for the document.

     @once emits this block at the FIRST include (the mobile header), and the
     desktop attention row is further down the page. Running immediately meant
     querySelectorAll found only the instance that had already been parsed,
     leaving the desktop button with no handler at all. */
  function initPads() {
    document.querySelectorAll( '[data-pad]' ).forEach( function ( wrap ) {
      // Idempotent: a second pass must not stack a second set of listeners
      // on a button that already has them.
      if ( wrap.hasAttribute( 'data-pad-bound' ) ) { return; }
      wrap.setAttribute( 'data-pad-bound', '' );
      bindPad( wrap );
    } );
  }

  if ( document.readyState === 'loading' ) {
    document.addEventListener( 'DOMContentLoaded', initPads );
  } else {
    initPads();
  }

  function bindPad( wrap ) {""")

io.open(p, 'w', encoding='utf-8').write(s)
print('patched', p)
PY

echo
echo "--- binding waits for the document ---"
python3 - <<'PY'
import io
s = io.open('resources/views/layouts/tenant/_notes-pad.blade.php', encoding='utf-8').read()
print('  DOMContentLoaded guard :', 'DOMContentLoaded' in s)
print('  readyState fallback    :', "readyState === 'loading'" in s)
print('  double-bind guard      :', 'data-pad-bound' in s)
print('  no bare immediate scan :', "forEach( function ( wrap ) { bindPad( wrap ); } );" not in s)
PY

echo
echo "--- js parses, function nesting closes ---"
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
js2 = ''.join(out)
os.makedirs('/tmp/pad8', exist_ok=True)
io.open('/tmp/pad8/p.js','w',encoding='utf-8').write(js2)
r = subprocess.run(['node','--check','/tmp/pad8/p.js'], capture_output=True, text=True)
print('  pad JS:', 'OK' if r.returncode==0 else 'FAIL\n'+r.stderr[:400])
print('  brace depth at end:', js2.count('{') - js2.count('}'))
PY

echo
echo "--- both headers still include it, styles still once ---"
grep -c "_notes-pad" resources/views/layouts/tenant/_attention-row.blade.php resources/views/layouts/tenant/_mobile-header.blade.php
python3 -c "
import io
s = io.open('resources/views/layouts/tenant/_notes-pad.blade.php', encoding='utf-8').read()
print('  @once/@endonce:', s.count('@once'), '/', s.count('@endonce'))
"

echo
echo "apply-pad-bind-on-ready: OK"
