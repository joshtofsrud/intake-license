#!/usr/bin/env bash
# apply-attention-item-modal.sh
# MARKER-ATTENTION-ITEM-INFO — open the item detail modal from an attention row.
#
# Deciding whether to accept a renamed title means knowing what the item
# actually is — its images, specs, current price, every source and their
# costs. All of that is already in the shared item modal the register and
# the multi-asset appointment page use. The attention queue just never
# included it, so the only way to check was opening the item in another tab
# and losing your place in the queue.
#
# Nothing new is built: window.IntakeItemModal.open(id) already exists and
# already fetches /admin/register/item/{id}/info. This includes the partial
# and puts an (i) on each row.
#
# The button is a <button>, not a link, and stops propagation — the row
# lives inside the bulk-action form with a checkbox per flag, and a stray
# submit or a checkbox toggle would act on the wrong rows.
set -e

python3 <<'PY'
import io

p = 'resources/views/tenant/distributors/attention.blade.php'
s = io.open(p, encoding='utf-8').read()

assert 'IntakeItemModal' not in s, 'already applied'

# (i) beside the item name
old = """              <td>
                <div style="font-weight:600">{{ $item->name ?? '—' }}</div>"""
assert s.count(old) == 1, 'A1 item cell anchor'
s = s.replace(old, """              <td>
                <div style="display:flex;align-items:center;gap:7px">
                  <div style="font-weight:600">{{ $item->name ?? '—' }}</div>
                  {{-- MARKER-ATTENTION-ITEM-INFO — deciding on a rename needs to
                       see the item. Button, not a link: this row sits inside the
                       bulk-action form and a stray submit would act on other
                       flags. --}}
                  @if($item)
                    <button type="button" title="Item details"
                            onclick="event.stopPropagation(); window.IntakeItemModal.open('{{ $item->id }}')"
                            style="flex:none;width:18px;height:18px;border-radius:50%;border:1px solid var(--ia-border);background:none;color:var(--ia-text-dim);font-size:11px;font-weight:700;line-height:1;cursor:pointer;padding:0">i</button>
                  @endif
                </div>""")

# include the shared modal once, before the closing section
old = """@endsection"""
assert s.count(old) >= 1, 'A2 endsection anchor'
idx = s.rindex(old)
s = s[:idx] + """{{-- MARKER-ATTENTION-ITEM-INFO — the same modal the register uses. --}}
@include('tenant._item-detail-modal')

""" + s[idx:]

io.open(p, 'w', encoding='utf-8').write(s)
print('patched', p)
PY

echo
echo "--- wiring ---"
grep -n "MARKER-ATTENTION-ITEM-INFO\|IntakeItemModal\|_item-detail-modal" resources/views/tenant/distributors/attention.blade.php

echo
echo "--- include sits inside the section, and after the table ---"
python3 - <<'PY'
import io
s = io.open('resources/views/tenant/distributors/attention.blade.php', encoding='utf-8').read()
inc = s.index("@include('tenant._item-detail-modal')")
sec = s.index('@section(')
end = s.rindex('@endsection')
btn = s.index('IntakeItemModal.open')
print('include after @section :', sec < inc)
print('include before @endsection:', inc < end)
print('button before include    :', btn < inc)
PY

echo
echo "--- blade sweep ---"
python3 - <<'PY'
import io, re
s = re.sub(r'\{\{--.*?--\}\}', '', io.open('resources/views/tenant/distributors/attention.blade.php', encoding='utf-8').read(), flags=re.S)
print('glued:', len(re.findall(r'\w@(?:if|endif|foreach|endforeach|else|elseif|unless|php|endphp)\b', s)))
for a, b in [('@if','@endif'), ('@foreach','@endforeach'), ('@php','@endphp'), ('@section','@endsection')]:
    o = len(re.findall(r'\B'+a+r'\b', s)); c = len(re.findall(r'\B'+b+r'\b', s))
    print(' ', a, o, b, c, 'OK' if o == c else 'MISMATCH')
d = 0; nested = False
for m in re.finditer(r'<form\b|</form>', s):
    d += -1 if m.group(0) == '</form>' else 1
    if d > 1: nested = True
print('forms balanced:', d == 0, 'nested:', nested)
PY

echo
echo "apply-attention-item-modal: OK"
