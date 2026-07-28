#!/bin/bash
# register-results-scroll — search results scroll instead of pushing the page.
#   #resultsArea had no height constraint, so a broad search ("maxxis")
#   expanded the panel past the viewport and everything below the fold was
#   unreachable without scrolling the whole page away from the search box.
#   Capped at min(52vh, 560px) with its own scroller.
#
#   applyHighlight() is patched in the same breath, and has to be: arrow-key
#   navigation had no scrollIntoView because it never needed one while the
#   list was fully visible. Capping the height without this would move the
#   highlight out of view on the first keypress past the fold — a worse bug
#   than the one being fixed.
#   scrollIntoView uses block:'nearest' so it only scrolls when the row is
#   actually outside the box, rather than yanking the list on every keypress.
# NO MIGRATION. Server: view:clear.
set -e
if grep -q "MARKER-RESULTS-SCROLL" resources/views/tenant/register/index.blade.php; then
  echo "register-results-scroll already applied — aborting."; exit 1
fi

python3 - <<'RRS_0_EOF'
import io
p = 'resources/views/tenant/register/index.blade.php'
s = io.open(p, encoding='utf-8').read()

# --- css ---------------------------------------------------------------
old = """  .reg-results-section{margin-top:14px}"""
assert s.count(old) == 1, s.count(old)
new = """  /* MARKER-RESULTS-SCROLL — cap the list and give it its own scroller, so a
     broad search doesn't run off the bottom of the screen. overscroll-behavior
     keeps a trackpad flick inside the list instead of scrolling the page. */
  #resultsArea{max-height:min(52vh,560px);overflow-y:auto;overscroll-behavior:contain}
  #resultsArea::-webkit-scrollbar{width:8px}
  #resultsArea::-webkit-scrollbar-thumb{background:var(--ia-border);border-radius:4px}
  #resultsArea::-webkit-scrollbar-thumb:hover{background:var(--ia-border-strong,var(--ia-border))}
  #resultsArea::-webkit-scrollbar-track{background:transparent}
  .reg-results-section{margin-top:14px}"""
s = s.replace(old, new)

# --- keep the highlighted row visible -----------------------------------
old = """function applyHighlight() {
  resultsArea.querySelectorAll('.reg-row').forEach((row, i) => {
    if (parseInt(row.dataset.i, 10) === highlighted) {
      row.classList.add('highlighted');
    } else {
      row.classList.remove('highlighted');
    }
  });
}"""
assert s.count(old) == 1, s.count(old)
new = """function applyHighlight() {
  resultsArea.querySelectorAll('.reg-row').forEach((row, i) => {
    if (parseInt(row.dataset.i, 10) === highlighted) {
      row.classList.add('highlighted');
      // MARKER-RESULTS-SCROLL — the list is scrollable now, so keyboard
      // navigation has to bring its own row into view. block:'nearest'
      // means this is a no-op while the row is already visible.
      if (typeof row.scrollIntoView === 'function') {
        row.scrollIntoView({ block: 'nearest' });
      }
    } else {
      row.classList.remove('highlighted');
    }
  });
}"""
s = s.replace(old, new)

io.open(p, 'w', encoding='utf-8').write(s)
print('results scroll ok')
RRS_0_EOF

echo
echo "register-results-scroll applied."
