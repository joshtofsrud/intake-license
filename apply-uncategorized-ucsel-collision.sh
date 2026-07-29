#!/bin/bash
# uncategorized-ucsel-collision — the split-by script never ran at all.
#
#   uncategorized.blade.php already had `function ucSel()` (line ~158) which
#   counts checked rows. The client-side split patch added
#   `const ucSel = document.getElementById('ucAttr')` in the same script
#   block. Two declarations of the same identifier, one of them const, is a
#   SyntaxError — and it is a PARSE-time error, so the entire <script> block
#   was discarded before a single statement executed.
#
#   That is why the symptoms looked so strange: chips never drew, the value
#   column stayed empty, the column header kept whatever the server rendered,
#   and changing the dropdown did nothing — while the console showed no
#   runtime error, because nothing ever ran to throw one. ucUpd() and
#   ucAll() were dead too, so the selection counter was also broken.
#
#   Renamed to ucAttrSel. The existing ucSel() keeps its name because other
#   code calls it.
#
#   The earlier data-attrs quoting fix was still needed — inch values really
#   did break those attributes — it just wasn't what stopped the script.
# NO MIGRATION. Server: view:clear.
set -e
if grep -q "MARKER-UCSEL-COLLISION" resources/views/tenant/inventory/uncategorized.blade.php; then
  echo "uncategorized-ucsel-collision already applied — aborting."; exit 1
fi

python3 - <<'UCC_0_EOF'
import io
p = 'resources/views/tenant/inventory/uncategorized.blade.php'
s = io.open(p, encoding='utf-8').read()

old = """const ucSel = document.getElementById('ucAttr');
if (ucSel) ucSel.addEventListener('change', e => {"""
assert s.count(old) == 1, s.count(old)

new = """/* MARKER-UCSEL-COLLISION \u2014 was `const ucSel`, which collided with the
   existing `function ucSel()` above that counts checked rows. Duplicate
   identifiers are a parse-time SyntaxError, so the whole script block was
   thrown away and none of this ever ran. */
const ucAttrSel = document.getElementById('ucAttr');
if (ucAttrSel) ucAttrSel.addEventListener('change', e => {"""
s = s.replace(old, new)

# the label helper reads the same element
old = """function ucLabel(){
  const sel = document.getElementById('ucAttr');"""
assert s.count(old) == 1, s.count(old)
new = """function ucLabel(){
  const sel = document.getElementById('ucAttr');  // MARKER-UCSEL-COLLISION"""
s = s.replace(old, new)

io.open(p, 'w', encoding='utf-8').write(s)
print('ucSel collision ok')
UCC_0_EOF

echo
echo "uncategorized-ucsel-collision applied."
