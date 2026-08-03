#!/usr/bin/env bash
# apply-so-vendor-copy-export.sh
# MARKER-SO-COPY-EXPORT — copy a vendor's order as part-number TAB quantity.
#
# Until the direct-to-distributor ordering exists, orders leave Intake by
# being pasted into whatever the vendor accepts. Every one of those forms
# takes the same two columns: THEIR part number, then the quantity.
#
# One button per vendor box, because you order one vendor at a time and the
# board is already grouped that way.
#
# THE PART NUMBER IS THE VENDOR'S, NOT YOURS. It comes from vendor_sku on the
# item/vendor link — the number that distributor uses — which is why the
# export has to be per vendor and cannot be built from the item's own SKU.
# vendor_sku was not being passed to the board at all, so the controller now
# includes it.
#
# ROWS WITH NO PART NUMBER ARE NOT SILENTLY DROPPED. A file with blank part
# numbers is worse than a short one — the vendor fills the order wrong rather
# than rejecting it. Those rows are excluded from the copied text and counted
# in an amber note beside the button, so the gap is visible before you paste.
#
# Tabs are never put in an HTML attribute. The rows travel as JSON and are
# joined with a real tab in JS, so nothing depends on attribute whitespace
# surviving Blade, the browser, or a copy/paste round trip.
set -e

python3 <<'PY'
import io

# ---------------------------------------------------------------- controller
p = 'app/Http/Controllers/Tenant/SpecialOrderController.php'
s = io.open(p, encoding='utf-8').read()

old = """                $options[$p->inventory_item_id][] = [
                    'vendor_id' => $p->vendor_id,
                    'name'      => $vendors[$p->vendor_id]->name,
                    'cost'      => $p->live_cost_cents ?? $p->unit_cost_cents,"""
assert s.count(old) == 1, 'C1 options row anchor'
s = s.replace(old, """                $options[$p->inventory_item_id][] = [
                    'vendor_id' => $p->vendor_id,
                    'name'      => $vendors[$p->vendor_id]->name,
                    // MARKER-SO-COPY-EXPORT — THEIR part number. The export is
                    // per vendor because this number is too.
                    'sku'       => $p->vendor_sku,
                    'cost'      => $p->live_cost_cents ?? $p->unit_cost_cents,""")

io.open(p, 'w', encoding='utf-8').write(s)
print('patched', p)

# ---------------------------------------------------------------- board
p = 'resources/views/tenant/special-orders/_vendor_groups.blade.php'
s = io.open(p, encoding='utf-8').read()

old = """    $min = $vendor->free_freight_cents ?? null;
  @endphp"""
assert s.count(old) == 1, 'B1 group php anchor'
s = s.replace(old, """    $min = $vendor->free_freight_cents ?? null;

    // MARKER-SO-COPY-EXPORT — build the two columns for this vendor.
    // Quantities for the same part number are summed: two lines for one part
    // is a common way to get shorted, and vendors' paste boxes rarely add.
    $sogExport = [];
    $sogNoSku  = 0;
    foreach ($rows as $r) {
      $o   = collect($voptions[$r->inventory_item_id] ?? [])->firstWhere('vendor_id', $vendorId);
      $sku = trim((string) ($o['sku'] ?? ''));
      if ($sku === '') { $sogNoSku++; continue; }
      $sogExport[$sku] = ($sogExport[$sku] ?? 0) + (int) $r->quantity;
    }
    $sogLines = [];
    foreach ($sogExport as $sku => $qty) { $sogLines[] = [$sku, $qty]; }
  @endphp""")

old = """        <button type="button" class="ia-btn ia-btn--primary" style="padding:7px 13px;font-size:11.5px" data-sog-order>
          Mark ordered
        </button>"""
assert s.count(old) == 1, 'B2 order button anchor'
s = s.replace(old, """        {{-- MARKER-SO-COPY-EXPORT --}}
        <button type="button" class="ia-btn" style="padding:7px 13px;font-size:11.5px"
                data-sog-copy data-sog-rows='@json($sogLines)'
                @disabled(! count($sogLines))>
          Copy order
        </button>
        @if($sogNoSku)
          <span style="font-size:11px;color:var(--ia-warning,#d9a441)"
                title="These have no part number for this vendor, so they are left out of the copied text">
            {{ $sogNoSku }} without a part no.
          </span>
        @endif
        <button type="button" class="ia-btn ia-btn--primary" style="padding:7px 13px;font-size:11.5px" data-sog-order>
          Mark ordered
        </button>""")

# self-contained block — never injected into an existing <script>, where a
# duplicate identifier is a parse error that silently kills the whole block.
s = s.rstrip() + """

{{-- MARKER-SO-COPY-EXPORT --}}
<script>
(function () {
  function toText(rows) {
    // Real tabs are built HERE, never carried through an HTML attribute.
    return rows.map(function (r) { return r[0] + '\\t' + r[1]; }).join('\\n');
  }

  function fallbackCopy(text) {
    var ta = document.createElement('textarea');
    ta.value = text;
    ta.setAttribute('readonly', '');
    ta.style.position = 'fixed';
    ta.style.opacity = '0';
    document.body.appendChild(ta);
    ta.select();
    var ok = false;
    try { ok = document.execCommand('copy'); } catch (e) { ok = false; }
    document.body.removeChild(ta);
    return ok;
  }

  document.addEventListener('click', function (e) {
    var btn = e.target.closest('[data-sog-copy]');
    if (!btn) { return; }
    e.preventDefault();

    var rows;
    try { rows = JSON.parse(btn.getAttribute('data-sog-rows') || '[]'); }
    catch (err) { rows = []; }
    if (!rows.length) { return; }

    var text = toText(rows);
    var label = btn.textContent;

    function done(ok) {
      btn.textContent = ok ? ('Copied ' + rows.length + ' line' + (rows.length === 1 ? '' : 's')) : 'Copy failed';
      setTimeout(function () { btn.textContent = label; }, 2200);
    }

    if (navigator.clipboard && window.isSecureContext) {
      navigator.clipboard.writeText(text).then(function () { done(true); },
                                              function () { done(fallbackCopy(text)); });
    } else {
      done(fallbackCopy(text));
    }
  });
}());
</script>
"""

io.open(p, 'w', encoding='utf-8').write(s)
print('patched', p)
PY

echo
echo "--- wiring ---"
grep -n "MARKER-SO-COPY-EXPORT" app/Http/Controllers/Tenant/SpecialOrderController.php resources/views/tenant/special-orders/_vendor_groups.blade.php

echo
echo "--- blade sweep ---"
python3 - <<'PY'
import io, re, subprocess, os
f = 'resources/views/tenant/special-orders/_vendor_groups.blade.php'
raw = io.open(f, encoding='utf-8').read()
s = re.sub(r'\{\{--.*?--\}\}', '', raw, flags=re.S)
print('glued:', len(re.findall(r'\w@(?:if|endif|foreach|endforeach|forelse|endforelse|else|elseif|php|endphp|json|disabled)\b', s)))
for a, b in [('@if','@endif'), ('@foreach','@endforeach'), ('@forelse','@endforelse'), ('@php','@endphp')]:
    o = len(re.findall(r'\B'+a+r'\b', s)); c = len(re.findall(r'\B'+b+r'\b', s))
    print(' ', a, o, b, c, 'OK' if o == c else 'MISMATCH')

os.makedirs('/tmp/so', exist_ok=True)
js = re.findall(r'<script[^>]*>(.*?)</script>', raw, flags=re.S)
io.open('/tmp/so/s.js','w',encoding='utf-8').write(js[-1])
r = subprocess.run(['node','--check','/tmp/so/s.js'], capture_output=True, text=True)
print('copy JS:', 'OK' if r.returncode==0 else 'FAIL\n'+r.stderr[:300])
PY

echo "--- php balance ---"
python3 - <<'PY'
import io
s = io.open('app/Http/Controllers/Tenant/SpecialOrderController.php', encoding='utf-8').read()
i, n, d, par = 0, len(s), 0, 0
while i < n:
    c = s[i]
    if c == '#' or (c == '/' and i+1 < n and s[i+1] == '/'):
        while i < n and s[i] != '\n': i += 1
    elif c == '/' and i+1 < n and s[i+1] == '*':
        i += 2
        while i+1 < n and not (s[i] == '*' and s[i+1] == '/'): i += 1
        i += 2
    elif c in '"\'':
        q = c; i += 1
        while i < n and s[i] != q:
            if s[i] == '\\': i += 1
            i += 1
        i += 1
    else:
        if c == '{': d += 1
        elif c == '}': d -= 1
        elif c == '(': par += 1
        elif c == ')': par -= 1
        i += 1
print('SpecialOrderController braces', d, 'parens', par)
PY

echo
echo "apply-so-vendor-copy-export: OK"
