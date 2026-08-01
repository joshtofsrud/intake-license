#!/usr/bin/env bash
# apply-inbox-grid-rows.sh
# MARKER-INBOX-GRID-ROWS — the height chain stopped one level short.
#
# MARKER-INBOX-VIEWPORT correctly bounded .ib-wrap (measured live: content
# 1009 - 56px padding - 72px page head = 881, and .ib-wrap is 881). But
# .ib-wrap is a GRID, and two grid defaults undo the bound:
#
#   * the single implicit row is sized `auto`, so it grows to the tallest
#     column instead of filling the container's height
#   * grid items default to min-height:auto, so .ib-list and .ib-conv
#     refuse to shrink below their content
#
# The row therefore overflows 881px and .ib-wrap's overflow:hidden clips
# it — the thread list is cut off mid-row and the composer is chopped off
# the bottom entirely. Neither inner scroller ever engages, because from
# their point of view there is no overflow to scroll.
#
# grid-template-rows:minmax(0,1fr) pins the row to the container height and
# permits it to shrink below content; min-height:0 on the two columns lets
# their flex children hand overflow to .ib-msgs and the thread-list
# wrapper, which is what finally makes both sides scroll.
#
# Same principle as the previous patch, one level further down. Desktop
# only — mobile uses the fixed-overlay conversation.
#
# View-only: view:clear is enough.
set -e

python3 <<'PY'
import io

p = 'resources/views/tenant/inbox/index.blade.php'
s = io.open(p, encoding='utf-8').read()

old = """    .ib-wrap { flex:1 1 auto; min-height:360px; }"""
assert s.count(old) == 1, 'G1 ib-wrap desktop anchor'
s = s.replace(old, """    .ib-wrap { flex:1 1 auto; min-height:360px; }
    /* MARKER-INBOX-GRID-ROWS — the grid's implicit row is `auto`, so it sizes
       to the tallest column and blows past the height we just gave .ib-wrap.
       minmax(0,1fr) pins it to the container AND allows it to shrink below
       its content; min-height:0 does the same for the two columns. Without
       both, .ib-msgs and the thread-list wrapper see no overflow and never
       scroll — the content just gets clipped by .ib-wrap's overflow:hidden. */
    .ib-wrap { grid-template-rows: minmax(0, 1fr); }
    .ib-list, .ib-conv { min-height: 0; }""")

io.open(p, 'w', encoding='utf-8').write(s)
print('patched', p)
PY

echo "--- desktop height rules now read ---"
sed -n '/MARKER-INBOX-VIEWPORT/,/^  }$/p' resources/views/tenant/inbox/index.blade.php | grep -E "ia-shell|ia-main|ia-content|ib-wrap|ib-list|ib-msgs"

echo
echo "--- blade + css sanity ---"
python3 - <<'PY'
import io, re
raw = io.open('resources/views/tenant/inbox/index.blade.php', encoding='utf-8').read()
s = re.sub(r'\{\{--.*?--\}\}', '', raw, flags=re.S)
print('glued:', len(re.findall(r'\w@(?:if|endif|foreach|endforeach|elseif|else|unless|php|endphp|forelse|empty|endforelse|push|endpush|section|endsection)\b', s)))
for a, b in [('@if','@endif'), ('@forelse','@endforelse'), ('@php','@endphp'), ('@push','@endpush'), ('@section','@endsection')]:
    o = len(re.findall(r'\B'+a+r'\b', s)); c = len(re.findall(r'\B'+b+r'\b', s))
    print(a, o, b, c, 'OK' if o == c else 'MISMATCH')
css = re.search(r'<style>(.*?)</style>', raw, re.S).group(1)
css = re.sub(r'/\*.*?\*/', '', css, flags=re.S)
print('css braces', css.count('{') - css.count('}'))
PY

echo
echo "apply-inbox-grid-rows: OK"
