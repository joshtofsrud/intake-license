#!/bin/bash
# item-specs-grid — stops the item modal's Specs section from pairing the
# wrong label with the wrong value.
#   Each attribute rendered as TWO loose children of
#   .rim-attrs{grid-template-columns:repeat(auto-fill,minmax(180px,1fr))}.
#   At the modal's 680px width that grid resolves to three columns, so the
#   label/value pairs desynchronise on every row: "Primary Color | Black |
#   Bead" then "Folding | Tire Compound | 3C Maxx Grip". Nothing is missing,
#   it is just offset by one — which is worse, because it reads as real data.
#   Fix wraps each pair in a single grid child, so a pair can never be split
#   across a column boundary at any width or column count.
# NO MIGRATION. Server: optimize:clear (+ hard refresh).
set -e
if grep -q "MARKER-SPECS-GRID-PAIR" resources/views/tenant/register/index.blade.php; then
  echo "item-specs-grid already applied — aborting."; exit 1
fi

# ---------------------------------------------------------------- css
python3 - <<'ISG_0_EOF'
import io
p = 'resources/views/tenant/register/index.blade.php'
s = io.open(p, encoding='utf-8').read()

old = """  #rim .rim-attrs{display:grid;grid-template-columns:repeat(auto-fill,minmax(180px,1fr));gap:4px 22px;font-size:12.5px}
  #rim .rim-attrs .k{color:var(--ia-text-muted)}"""
assert s.count(old) == 1, s.count(old)

new = """  /* MARKER-SPECS-GRID-PAIR \u2014 one grid child per attribute. Emitting the
     label and the value as separate children let an odd column count
     offset every pair by one. */
  #rim .rim-attrs{display:grid;grid-template-columns:repeat(auto-fill,minmax(180px,1fr));gap:6px 22px;font-size:12.5px}
  #rim .rim-attrs .pair{display:flex;gap:10px;justify-content:space-between;align-items:baseline;border-bottom:0.5px dotted var(--ia-border);padding-bottom:3px}
  #rim .rim-attrs .pair .v{text-align:right}
  #rim .rim-attrs .k{color:var(--ia-text-muted)}"""
io.open(p, 'w', encoding='utf-8').write(s.replace(old, new))
print('css ok')
ISG_0_EOF

# ---------------------------------------------------------------- markup
python3 - <<'ISG_1_EOF'
import io
p = 'resources/views/tenant/register/index.blade.php'
s = io.open(p, encoding='utf-8').read()

old = """      document.getElementById('rim-attrs').innerHTML = d.attrs.map(a =>
        '<span class="k">' + escapeHtml(a.name) + '</span><span>' + escapeHtml(a.value) + '</span>').join('');"""
assert s.count(old) == 1, s.count(old)

new = """      // MARKER-SPECS-GRID-PAIR \u2014 label + value wrapped together so the
      // grid can only ever break BETWEEN pairs, never inside one.
      document.getElementById('rim-attrs').innerHTML = d.attrs.map(a =>
        '<div class="pair"><span class="k">' + escapeHtml(a.name) + '</span>'
        + '<span class="v">' + escapeHtml(a.value) + '</span></div>').join('');"""
io.open(p, 'w', encoding='utf-8').write(s.replace(old, new))
print('markup ok')
ISG_1_EOF

echo
echo "item-specs-grid applied."
