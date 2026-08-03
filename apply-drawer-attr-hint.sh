#!/usr/bin/env bash
# apply-drawer-attr-hint.sh
# MARKER-DRAWER-ATTR-HINT — say that {attr:...} takes ANY name.
#
# The drawer offers a chip per attribute found on the sampled items, which is
# better than a generic placeholder — until the sample does not happen to
# carry the attribute you want. Attribute names come from the distributor, so
# they vary by category and by product, and a category whose sample lacks one
# renders no chips at all and no hint that the token exists.
#
# So the note under the chips now covers both shapes: any attribute by name,
# and the fallback chain. Placed where the chain hint already is, so it shows
# whether or not the attribute row renders.
set -e

python3 <<'PY'
import io

p = 'resources/views/filament/pages/catalog-title-review.blade.php'
s = io.open(p, encoding='utf-8').read()

old = """                    {{-- MARKER-DRAWER-TOKENS — chains have no chip, so say so once. --}}
                    <div class="text-[11px] opacity-60 mb-3 leading-relaxed">
                        Separate tokens with <span class="font-mono">|</span> to try them in order —
                        <span class="font-mono">{size|attr:Labeled Size|size_code}</span> uses the first
                        one that has a value.
                    </div>"""
assert s.count(old) == 1, 'H1 chain note anchor (run apply-drawer-token-chips.sh first)'
s = s.replace(old, """    {{-- MARKER-DRAWER-ATTR-HINT — both shapes that have no chip. Shown
         regardless of whether the attribute row below renders, because a
         category whose sampled items carry no attributes would otherwise
         give no clue that {attr:...} exists at all. --}}
                    <div class="text-[11px] opacity-60 mb-3 leading-relaxed">
                        <span class="font-mono">{attr:Casing}</span> works for any attribute name this
                        distributor sends, not only the ones shown below.
                        Separate tokens with <span class="font-mono">|</span> to try them in order —
                        <span class="font-mono">{size|attr:Labeled Size|size_code}</span> uses the first
                        one that has a value.
                    </div>""")

io.open(p, 'w', encoding='utf-8').write(s)
print('patched', p)
PY

echo
echo "--- note renders outside the attribute row's @if ---"
python3 - <<'PY'
import io
s = io.open('resources/views/filament/pages/catalog-title-review.blade.php', encoding='utf-8').read()
hint = s.index('MARKER-DRAWER-ATTR-HINT')
cond = s.index("@if (count($this->attrNames))")
print('hint before the @if :', hint < cond)
PY

echo
echo "--- blade sweep ---"
python3 - <<'PY'
import io, re
f = 'resources/views/filament/pages/catalog-title-review.blade.php'
s = re.sub(r'\{\{--.*?--\}\}', '', io.open(f, encoding='utf-8').read(), flags=re.S)
print('glued:', len(re.findall(r'\w@(?:if|endif|foreach|endforeach|else|elseif|php|endphp)\b', s)))
for a, b in [('@if','@endif'), ('@foreach','@endforeach'), ('@php','@endphp')]:
    o = len(re.findall(r'\B'+a+r'\b', s)); c = len(re.findall(r'\B'+b+r'\b', s))
    print(' ', a, o, b, c, 'OK' if o == c else 'MISMATCH')
PY

echo
echo "apply-drawer-attr-hint: OK"
