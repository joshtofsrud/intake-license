#!/bin/bash
# uncategorized-split-client — filter in the browser, no reload, no scroll jump.
#
#   The chips and the dropdown were links, so every click was a full page load
#   that dumped you back at the top of a long bucket page. Anchoring would have
#   hidden the jump; this removes the reload.
#
#   The bucket is already capped at 500 rows, so every row now carries its own
#   attribute values and the whole picker runs client-side: switching attribute
#   rebuilds the chips and the Size column, clicking a chip shows and hides
#   rows. No round trip either way.
#
#   The part that actually needed care is the checkboxes. Hidden rows keep
#   their inputs in the form, so a filtered selection would have submitted
#   items you could not see — you filter to 29", tick "all", and quietly assign
#   146 wheels instead of 71. Every filter change now clears the selection on
#   rows it hides, and select-all only ever touches visible rows. What you see
#   is what you assign.
#
#   Server-side attr/val query params are gone with the links. Nothing was
#   persisting them anyway.
# NO MIGRATION. Server: optimize:clear.
set -e
if grep -q "MARKER-SPLIT-BY-CLIENT" resources/views/tenant/inventory/uncategorized.blade.php; then
  echo "uncategorized-split-client already applied — aborting."; exit 1
fi
if ! grep -q "MARKER-SPLIT-BY" app/Http/Controllers/Tenant/InventoryController.php; then
  echo "uncategorized-split-by-attribute must be applied first — aborting."; exit 1
fi

# ------------------------------------------------------------------ controller
python3 - <<'USC_0_EOF'
import io
p = 'app/Http/Controllers/Tenant/InventoryController.php'
s = io.open(p, encoding='utf-8').read()

# Values for every attribute, not just the active one; no server-side filter.
old = """        // Values for the chip row, biggest first. No unit is appended \u2014 the
        // value carries its own (622 has none, 12mm keeps its own).
        $valueCounts = [];
        if ($activeAttr !== null) {
            $valueCounts = $tally[$activeAttr]['vals'] ?? [];
            arsort($valueCounts);
        }

        foreach ($all as $it) {
            $it->_val = '';
            $cat = $it->distributorCatalog;
            if (! $cat || $activeAttr === null) { continue; }
            if ($activeAttr === '__brand') {
                $it->_val = trim((string) ($cat->manufacturer ?? ''));
                continue;
            }
            foreach (($cat->attributes ?? []) as $a) {
                if (is_array($a) && isset($a['Name'])
                    && trim((string) $a['Name']) === $activeAttr) {
                    $it->_val = trim((string) ($a['Value'] ?? ''));
                    break;
                }
            }
        }

        $items = $attrVal !== null
            ? $all->filter(fn ($it) => $it->_val === $attrVal)->values()
            : $all;
"""
assert s.count(old) == 1, s.count(old)

new = """        // MARKER-SPLIT-BY-CLIENT \u2014 every attribute's value counts, and every
        // row's values, go to the browser so switching attribute and picking a
        // value are both instant. The bucket is capped at 500 rows, so this is
        // a few tens of KB, not a page weight problem.
        $valuesByAttr = [];
        foreach ($tally as $name => $t) {
            $vals = $t['vals'] ?? [];
            arsort($vals);
            $valuesByAttr[$name] = $vals;
        }

        foreach ($all as $it) {
            $vals = [];
            $cat  = $it->distributorCatalog;
            if ($cat) {
                foreach (($cat->attributes ?? []) as $a) {
                    if (! is_array($a) || ! isset($a['Name'])) { continue; }
                    $n = trim((string) $a['Name']);
                    $v = trim((string) ($a['Value'] ?? ''));
                    if ($n !== '' && $v !== '' && ! isset($vals[$n])) { $vals[$n] = $v; }
                }
                $brand = trim((string) ($cat->manufacturer ?? ''));
                if ($brand !== '') { $vals['__brand'] = $brand; }
            }
            $it->_attrs = $vals;
            $it->_val   = $activeAttr !== null ? ($vals[$activeAttr] ?? '') : '';
        }

        // No server-side filtering \u2014 the browser hides rows.
        $items = $all;
"""
s = s.replace(old, new)

old = """            'attrOptions' => $attrOptions, 'activeAttr' => $activeAttr,
            'activeAttrLabel' => $activeAttrLabel, 'valueCounts' => $valueCounts,
            'activeVal' => $attrVal, 'bucketTotal' => $bucketTotal,"""
assert s.count(old) == 1, s.count(old)
new = """            'attrOptions' => $attrOptions, 'activeAttr' => $activeAttr,
            'activeAttrLabel' => $activeAttrLabel, 'valuesByAttr' => $valuesByAttr,
            'bucketTotal' => $bucketTotal,"""
s = s.replace(old, new)

io.open(p, 'w', encoding='utf-8').write(s)
print('controller ok')
USC_0_EOF

# ------------------------------------------------------------------ view
python3 - <<'USC_1_EOF'
import io
p = 'resources/views/tenant/inventory/uncategorized.blade.php'
s = io.open(p, encoding='utf-8').read()

start = s.index("    {{-- MARKER-SPLIT-BY \u2014 pick the attribute to group by.")
endAnchor = "        </div>\n      @endif\n    @endif\n"
assert s.count(endAnchor) == 1, s.count(endAnchor)
end = s.index(endAnchor) + len(endAnchor)

block = """    {{-- MARKER-SPLIT-BY-CLIENT — the picker runs in the browser. Nothing is
         remembered; the default still comes from the server-side ranking. --}}
    @if(count($attrOptions))
      <div style="display:flex;gap:10px;align-items:center;flex-wrap:wrap;margin-bottom:12px">
        <span style="font-size:11px;text-transform:uppercase;letter-spacing:.05em;color:var(--ia-text-mute);font-weight:600">Split by</span>
        <select id="ucAttr"
                style="background:var(--ia-input-bg);border:1px solid var(--ia-border);border-radius:var(--ia-r-md);color:var(--ia-text);padding:7px 10px;font-size:12.5px;min-width:280px">
          <option value="" @selected(! $activeAttr)>— no split —</option>
          @foreach($attrOptions as $o)
            <option value="{{ $o['key'] }}" data-label="{{ $o['label'] }}" @selected($activeAttr === $o['key'])>
              {{ $o['label'] }} — {{ $o['cov'] }}% · {{ $o['vals'] }} values{{ $o['qualifies'] ? '' : ' (' . $o['reason'] . ')' }}
            </option>
          @endforeach
        </select>
        <span id="ucAttrNote" style="font-size:12px;color:var(--ia-text-dim)"></span>
      </div>

      <div id="ucChips" style="display:flex;gap:7px;flex-wrap:wrap;align-items:center;margin-bottom:14px"></div>
    @endif
"""
s = s[:start] + block + s[end:]

# --- rows carry their values --------------------------------------------
old = """                  <td style="padding:11px 14px"><input type="checkbox" class="uc-cb" name="item_ids[]" value="{{ $it->id }}" onchange="ucUpd()"></td>"""
assert s.count(old) == 1, s.count(old)
new = """                  {{-- MARKER-SPLIT-BY-CLIENT — values for every attribute ride on the row. --}}
                  <td style="padding:11px 14px"><input type="checkbox" class="uc-cb" name="item_ids[]" value="{{ $it->id }}" onchange="ucUpd()"></td>"""
s = s.replace(old, new)

# tag the <tr> — find the row opener immediately before the checkbox cell
old = """                <tr style="border-top:.5px solid var(--ia-border)">"""
assert s.count(old) == 1, s.count(old)
new = """                <tr class="uc-row" data-attrs='@json($it->_attrs)' style="border-top:.5px solid var(--ia-border)">"""
s = s.replace(old, new)

# value cell gets a hook
old = """@if($it->_val)<span style="font-size:11px;font-family:var(--ia-mono);padding:2px 8px;border-radius:5px;background:var(--ia-surface-2);border:.5px solid var(--ia-border);color:var(--ia-text-dim)">{{ $it->_val }}</span>@else<span style="color:var(--ia-text-mute)">—</span>@endif"""
assert s.count(old) == 1, s.count(old)
new = """<span class="uc-val"></span>"""
s = s.replace(old, new)

# select-all only touches visible rows
old = """onclick="document.querySelectorAll('.uc-cb').forEach(c=>c.checked=this.checked);ucUpd()\""""
assert s.count(old) == 1, s.count(old)
new = """onclick="ucAll(this.checked)\""""
s = s.replace(old, new)

# --- the script ----------------------------------------------------------
old = """    if (n === 0){ btn.disabled = true; btn.textContent = 'Select items to assign'; }"""
assert s.count(old) == 1, s.count(old)
marker = s.index(old)
scriptEnd = s.index('</script>', marker)

inject = """
/* MARKER-SPLIT-BY-CLIENT ------------------------------------------------
   Filtering happens here rather than on the server, so the page never
   reloads and never jumps to the top.

   The rule that matters: a hidden row's checkbox is still inside the form.
   Filter to 29", hit select-all, and without this you would assign every
   wheel in the bucket instead of the 71 on screen. So any change that hides
   rows clears their selection first, and select-all is scoped to what's
   visible. */
const UC_VALUES = @json($valuesByAttr ?? []);
const UC_MAX_CHIPS = 16;
let ucAttr = @json($activeAttr);
let ucVal  = null;

function ucAll(checked){
  document.querySelectorAll('.uc-row').forEach(tr => {
    if (tr.style.display === 'none') return;          // visible only
    const cb = tr.querySelector('.uc-cb');
    if (cb) cb.checked = checked;
  });
  ucUpd();
}

function ucLabel(){
  const sel = document.getElementById('ucAttr');
  const opt = sel && sel.selectedOptions[0];
  return (opt && opt.dataset.label) || 'Size';
}

function ucPaintRows(){
  let shown = 0;
  document.querySelectorAll('.uc-row').forEach(tr => {
    let vals = {};
    try { vals = JSON.parse(tr.dataset.attrs || '{}'); } catch (e) {}
    const v = ucAttr ? (vals[ucAttr] || '') : '';

    const cell = tr.querySelector('.uc-val');
    if (cell) {
      cell.innerHTML = v
        ? '<span style="font-size:11px;font-family:var(--ia-mono);padding:2px 8px;border-radius:5px;background:var(--ia-surface-2);border:.5px solid var(--ia-border);color:var(--ia-text-dim)">'
          + v.replace(/[<>&]/g, c => ({'<':'&lt;','>':'&gt;','&':'&amp;'}[c])) + '</span>'
        : '<span style="color:var(--ia-text-mute)">\\u2014</span>';
    }

    const hide = ucVal !== null && v !== ucVal;
    tr.style.display = hide ? 'none' : '';
    if (hide) {
      const cb = tr.querySelector('.uc-cb');
      if (cb) cb.checked = false;                     // never assign unseen rows
    } else { shown++; }
  });

  const head = document.getElementById('ucColHead');
  if (head) head.textContent = ucAttr ? ucLabel() : 'Size';

  const all = document.getElementById('ucAllBox');
  if (all) all.checked = false;

  ucUpd();
  return shown;
}

function ucPaintChips(){
  const box = document.getElementById('ucChips');
  if (!box) return;
  if (!ucAttr){ box.innerHTML = ''; return; }

  const counts = UC_VALUES[ucAttr] || {};
  const entries = Object.entries(counts);
  const total = document.querySelectorAll('.uc-row').length;

  const pill = (label, count, on, val) =>
    '<button type="button" data-val="' + (val === null ? '' : String(val).replace(/"/g,'&quot;')) + '"'
    + ' style="padding:5px 11px;border-radius:20px;font-size:12.5px;font-weight:600;cursor:pointer;'
    + 'border:1px solid ' + (on ? 'var(--ia-text)' : 'var(--ia-border)') + ';'
    + 'background:' + (on ? 'var(--ia-text)' : 'var(--ia-surface-2)') + ';'
    + 'color:' + (on ? 'var(--ia-bg)' : 'var(--ia-text-dim)') + '">'
    + label + ' <span style="font-family:var(--ia-mono);opacity:.7">' + count + '</span></button>';

  let html = pill('All', total, ucVal === null, null);
  entries.slice(0, UC_MAX_CHIPS).forEach(([v, c]) => {
    html += pill(v.replace(/[<>&]/g, ch => ({'<':'&lt;','>':'&gt;','&':'&amp;'}[ch])), c, ucVal === v, v);
  });
  if (entries.length > UC_MAX_CHIPS){
    html += '<span style="font-size:12px;color:var(--ia-text-mute)">+ '
         + (entries.length - UC_MAX_CHIPS) + ' more</span>';
  }
  box.innerHTML = html;

  box.querySelectorAll('button[data-val]').forEach(b => b.addEventListener('click', () => {
    const v = b.dataset.val;
    ucVal = (v === '' ? null : v);
    ucPaintChips();
    ucPaintRows();
  }));
}

function ucNote(){
  const el = document.getElementById('ucAttrNote');
  if (!el) return;
  const n = document.querySelectorAll('.uc-row').length;
  el.textContent = ucAttr
    ? 'grouping ' + n + ' items'
    : 'nothing in this bucket groups usefully \\u2014 pick one above if you disagree';
}

const ucSel = document.getElementById('ucAttr');
if (ucSel) ucSel.addEventListener('change', e => {
  ucAttr = e.target.value || null;
  ucVal = null;
  ucNote(); ucPaintChips(); ucPaintRows();
});

ucNote(); ucPaintChips(); ucPaintRows();
"""
s = s[:scriptEnd] + inject + s[scriptEnd:]

# ids the script reaches for
old = """<th style="width:28px;padding:10px 14px"><input type="checkbox" onclick="ucAll(this.checked)"></th>"""
assert s.count(old) == 1, s.count(old)
new = """<th style="width:28px;padding:10px 14px"><input type="checkbox" id="ucAllBox" onclick="ucAll(this.checked)"></th>"""
s = s.replace(old, new)

old = """<th style="padding:10px 14px">{{ $activeAttrLabel }}</th>"""
assert s.count(old) == 1, s.count(old)
new = """<th style="padding:10px 14px" id="ucColHead">{{ $activeAttrLabel }}</th>"""
s = s.replace(old, new)

io.open(p, 'w', encoding='utf-8').write(s)
print('view ok')
USC_1_EOF

php -l app/Http/Controllers/Tenant/InventoryController.php

echo
echo "uncategorized-split-client applied."
