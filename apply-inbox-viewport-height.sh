#!/usr/bin/env bash
# apply-inbox-viewport-height.sh
# MARKER-INBOX-VIEWPORT — make the inbox own the viewport instead of growing.
#
# The previous patch capped .ib-msgs at 58vh, which stopped the messages
# running away but did nothing for the thread list — and left dead space
# between the last message and the composer, because .ib-conv was still as
# tall as the grid row while its scroller was capped shorter.
#
# The real problem is one level up. Both columns ALREADY have correct inner
# scrollers (.ib-msgs, and the overflow-y:auto wrapper around the threads).
# Neither can ever scroll, because nothing above them has a bounded height:
#
#   .ia-shell   min-height:100vh   <- minimum, not a maximum
#   .ia-main    flex column, no height
#   .ia-content flex:1, no height
#   .ib-wrap    min-height:560px, no maximum
#
# So the grid grows to fit the longest column (the thread list), the page
# scrolls, and the composer ends up below the fold.
#
# Fixing it needs no magic numbers — the layout is already the right shape,
# it just needs a ceiling and min-height:0 so flex children may shrink below
# their content and hand overflow to the inner scrollers. Because these
# styles are @push'd from the inbox page, the .ia-* overrides apply on this
# page only.
#
# Desktop only (min-width:981px). Mobile already solves this with the
# fixed-overlay conversation from MARKER-PATCH-433.
#
# View-only: view:clear is enough.
set -e

python3 <<'PY'
import io

p = 'resources/views/tenant/inbox/index.blade.php'
s = io.open(p, encoding='utf-8').read()

old = """  /* MARKER-INBOX-POLISH — bound the scroller itself. Guessing the height of
     the chrome above it would break the next time the header changes. */
  @media (min-width: 981px) {
    .ib-msgs { max-height:58vh; }
  }"""
assert s.count(old) == 1, 'V1 previous max-height block anchor'
s = s.replace(old, """  /* MARKER-INBOX-VIEWPORT — give the shell a ceiling so the inner scrollers
     (.ib-msgs and the thread-list wrapper) actually have somewhere to scroll.
     min-height:0 on each flex ancestor is the load-bearing part: without it a
     flex item refuses to shrink below its content and the overflow escapes
     upward instead of scrolling. Scoped to this page by the pushed styles. */
  @media (min-width: 981px) {
    .ia-shell   { height:100dvh; min-height:0; }
    .ia-main    { min-height:0; }
    .ia-content { min-height:0; display:flex; flex-direction:column; overflow:hidden; }
    /* Everything above the inbox keeps its natural height; only the inbox
       takes the slack. Guards against a flash banner getting squashed. */
    .ia-content > * { flex:0 0 auto; }
    .ib-wrap { flex:1 1 auto; min-height:360px; }
    /* .ib-msgs goes back to plain flex:1 — with a bounded parent it fills the
       space and scrolls, so the composer sits directly under the last
       message instead of after a gap. */
    .ib-msgs { max-height:none; }
  }""")

io.open(p, 'w', encoding='utf-8').write(s)
print('patched', p)
PY

echo "--- the height chain ---"
grep -n "ia-shell\|ia-main\|ia-content\|ib-wrap {\|ib-msgs {" resources/views/tenant/inbox/index.blade.php

echo
echo "--- glued directive sweep + pairing ---"
python3 - <<'PY'
import io, re
s = re.sub(r'\{\{--.*?--\}\}', '', io.open('resources/views/tenant/inbox/index.blade.php', encoding='utf-8').read(), flags=re.S)
print('glued:', len(re.findall(r'\w@(?:if|endif|foreach|endforeach|elseif|else|unless|php|endphp|forelse|empty|endforelse)\b', s)))
for a, b in [('@if','@endif'), ('@forelse','@endforelse'), ('@php','@endphp'), ('@push','@endpush')]:
    o = len(re.findall(r'\B'+a+r'\b', s)); c = len(re.findall(r'\B'+b+r'\b', s))
    print(a, o, b, c, 'OK' if o == c else 'MISMATCH')
PY

echo
echo "--- brace balance in the style block ---"
python3 - <<'PY'
import io, re
s = io.open('resources/views/tenant/inbox/index.blade.php', encoding='utf-8').read()
css = re.search(r'<style>(.*?)</style>', s, re.S).group(1)
css = re.sub(r'/\*.*?\*/', '', css, flags=re.S)
print('css braces', css.count('{') - css.count('}'))
PY

echo
echo "apply-inbox-viewport-height: OK"
