#!/usr/bin/env bash
# apply-blade-glued-directives-and-nested-form.sh
# MARKER-BLADE-GLUE-FIX — four live display bugs and one invalid form nesting,
# all previously noted and not acted on.
#
# THE GLUE BUG. Blade's compileStatements pattern begins \B@, so a directive
# immediately preceded by a WORD character is never compiled. Its partner
# @endif usually IS preceded by punctuation, so the endif compiles while the
# @if does not — leaving the directive on screen as literal text AND closing
# whichever conditional was open above it, one level too early.
#
# Verified by walking each file positionally against Blade's real regex.
# Final depth is 0 in every case, so nothing is fatal — these render, they
# just render wrong:
#
#   receipt.blade.php:110
#     A tax-exempt receipt prints the literal string
#     "@if($sale->tax_exempt_certificate)" on the customer's copy, shows the
#     certificate line even when there is no certificate, and its @endif
#     closes an outer conditional at line 116, so the PO number block prints
#     unconditionally. This is a customer-facing document that prints daily.
#
#   sale-show.blade.php:50
#     "}}@endif@if($sale->card_last4)" — the second @if is glued to the "f"
#     of the preceding endif, so the masked-card dots print for sales with
#     no card at all.
#
#   appointments/show.blade.php:763 and :1441
#     Same shape, twice.
#
# The fix is whitespace before the directive. These are all HTML contexts,
# so a newline collapses and the rendered output is unchanged apart from the
# bug going away.
#
# THE NESTED FORM. inventory/edit.blade.php had the Archive form nested
# inside the item-update form. Nested forms are invalid HTML and browsers
# resolve them unpredictably — the inner submit can post to the outer
# action. Fixed with the HTML5 `form` attribute: the update form closes
# before the button row, and Save references it by id, so the two forms are
# siblings and the layout is unchanged.
set -e

python3 <<'PY'
import io

def fix(path, old, new, label):
    s = io.open(path, encoding='utf-8').read()
    assert s.count(old) >= 1, label + ' anchor not found in ' + path
    n = s.count(old)
    s = s.replace(old, new)
    io.open(path, 'w', encoding='utf-8').write(s)
    print('  %s — %d instance(s)' % (path, n))

print('glued directives:')

# receipt — tax exempt certificate
fix('resources/views/tenant/register/receipt.blade.php',
    'Tax exempt@if($sale->tax_exempt_certificate)',
    'Tax exempt\n            @if($sale->tax_exempt_certificate)',
    'R1')

# sale-show — card brand / last4 run together
fix('resources/views/tenant/register/sale-show.blade.php',
    '}}@endif@if($sale->card_last4)',
    '}}@endif\n      @if($sale->card_last4)',
    'R2')

# appointments — two identical instances
fix('resources/views/tenant/appointments/show.blade.php',
    'Tax exempt@if($appointment->customer->tax_exempt_certificate)',
    'Tax exempt\n              @if($appointment->customer->tax_exempt_certificate)',
    'R3')

print('nested form:')

p = 'resources/views/tenant/inventory/edit.blade.php'
s = io.open(p, encoding='utf-8').read()

old = """<form method="POST" action="{{ route('tenant.inventory.update', $item->id) }}">"""
assert s.count(old) == 1, 'F1 main form anchor'
s = s.replace(old, """{{-- MARKER-BLADE-GLUE-FIX — the Archive form used to be nested INSIDE this
     one. Nested forms are invalid HTML and the inner submit can post to the
     outer action. The two are siblings now; Save reaches this form by id. --}}
<form id="item-edit-form" method="POST" action="{{ route('tenant.inventory.update', $item->id) }}">""")

old = """  <div style="display:flex;gap:8px;justify-content:space-between;align-items:center">
    <form method="POST" action="{{ route('tenant.inventory.destroy', $item->id) }}\""""
assert s.count(old) == 1, 'F2 actions row anchor'
s = s.replace(old, """</form>

  <div style="display:flex;gap:8px;justify-content:space-between;align-items:center">
    <form method="POST" action="{{ route('tenant.inventory.destroy', $item->id) }}\"""")

old = """      <button type="submit" class="ia-btn ia-btn--primary">Save changes</button>
    </div>
  </div>
</form>"""
assert s.count(old) == 1, 'F3 save button anchor'
s = s.replace(old, """      <button type="submit" form="item-edit-form" class="ia-btn ia-btn--primary">Save changes</button>
    </div>
  </div>""")

io.open(p, 'w', encoding='utf-8').write(s)
print('  %s — forms unnested' % p)
PY

echo
echo "--- no glued directives remain in those files ---"
python3 - <<'PY'
import io, re
pat = re.compile(r'\B@(@?\w+(?:::\w+)?)([ \t]*)(\()?', re.X)
OPEN  = {'if','unless','isset','empty','auth','guest','hasSection','sectionMissing','env','production','can','cannot','canany'}
CLOSE = {'endif','endunless','endisset','endempty','endauth','endguest','endcan','endcannot','endcanany','endenv','endproduction'}
tok = re.compile(r'@(\w+)')
for f in ['resources/views/tenant/register/receipt.blade.php',
          'resources/views/tenant/register/sale-show.blade.php',
          'resources/views/tenant/appointments/show.blade.php',
          'resources/views/tenant/inventory/edit.blade.php']:
    raw = io.open(f, encoding='utf-8').read()
    s = re.sub(r'\{\{--.*?--\}\}', lambda m: ' '*len(m.group(0)), raw, flags=re.S)
    glued = [m.start() for m in re.finditer(r'\w@(?:if|endif|foreach|endforeach|else|elseif|unless|php|endphp)\b', s)]
    depth = 0; neg = 0
    for m in tok.finditer(s):
        if not pat.match(s, m.start()): continue
        if m.group(1) in OPEN: depth += 1
        elif m.group(1) in CLOSE:
            depth -= 1
            if depth < 0: neg += 1; depth = 0
    print('%-52s glued=%d  early-close=%d  final-depth=%d %s'
          % (f.split('/')[-1], len(glued), neg, depth,
             'OK' if (not glued and neg == 0 and depth == 0) else '*** CHECK ***'))
PY

echo
echo "--- form nesting ---"
grep -n "<form\|</form>\|form=\"item-edit-form\"" resources/views/tenant/inventory/edit.blade.php

echo
echo "apply-blade-glued-directives-and-nested-form: OK"
