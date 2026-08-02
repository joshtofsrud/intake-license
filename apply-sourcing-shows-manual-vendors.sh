#!/usr/bin/env bash
# apply-sourcing-shows-manual-vendors.sh
# MARKER-SOURCING-MANUAL — a vendor you add by hand never appears in Sourcing.
#
# The table filters to rows that carry a distributor_code:
#
#     ->filter(fn ($v) => filled($v->pivot->distributor_code ?? null))
#
# Manual rows have none, so they are dropped — and because the whole card is
# wrapped in @if($sources->count()), an item sourced ONLY from hand-added
# vendors shows no Sourcing section at all. That filter was correct when
# importing was the only way to attach a vendor; adding the sources editor
# made it wrong the same day.
#
# Changes, all display-level:
#   * the filter goes, so every source row appears
#   * the first column falls back to the vendor's name when there is no
#     distributor code — "BTI" for synced rows, "Bicycle Technologies" for a
#     manual one, rather than an empty cell
#   * the header counts "sources" rather than "distributors", because a shop
#     you buy from down the road is not a distributor
#   * a manual row is labelled so it is obvious why it has no availability:
#     the cost is what you typed, not something tier-2 refreshes
#
# The cheapest badge and cost sort already read live_cost_cents ?? unit_cost_cents,
# so a hand-typed cost competes correctly with a synced one — nothing to change.
set -e

python3 <<'PY'
import io

p = 'resources/views/tenant/inventory/show.blade.php'
s = io.open(p, encoding='utf-8').read()

# 1. stop dropping manual rows
old = """  $sources = $item->vendors
      ->filter(fn ($v) => filled($v->pivot->distributor_code ?? null))
      ->map(function ($v) use ($infoCatalogId) {"""
assert s.count(old) == 1, 'S1 sources filter anchor'
s = s.replace(old, """  // MARKER-SOURCING-MANUAL — no filter. This used to keep only rows with a
  // distributor_code, which silently hid every hand-added vendor and left an
  // item sourced only from those with no Sourcing card at all.
  $sources = $item->vendors
      ->map(function ($v) use ($infoCatalogId) {""")

# 2. carry the vendor name and a manual flag through
old = """              'vendor'    => $v,
              'code'      => $v->pivot->distributor_code,"""
assert s.count(old) == 1, 'S2 row shape anchor'
s = s.replace(old, """              'vendor'    => $v,
              'code'      => $v->pivot->distributor_code,
              // MARKER-SOURCING-MANUAL — what to show in the first column, and
              // whether tier-2 owns this row's cost.
              'label'     => filled($v->pivot->distributor_code ?? null)
                  ? $v->pivot->distributor_code
                  : $v->name,
              'manual'    => ! filled($v->pivot->distributor_code ?? null),""")

# 3. header wording
old = """          {{ $sources->count() }} distributor{{ $sources->count() === 1 ? '' : 's' }} carry this"""
assert s.count(old) == 1, 'S3 header anchor'
s = s.replace(old, """          {{ $sources->count() }} source{{ $sources->count() === 1 ? '' : 's' }} for this""")

old = """            <th>Distributor</th><th>Their part no.</th>"""
assert s.count(old) == 1, 'S4 header cell anchor'
s = s.replace(old, """            <th>Source</th><th>Their part no.</th>""")

# 4. first column label + manual marker
old = """                <strong>{{ $src->code }}</strong>"""
assert s.count(old) == 1, 'S5 first cell anchor'
s = s.replace(old, """                <strong>{{ $src->label }}</strong>
                @if($src->manual)
                  <span class="ia-badge" style="margin-left:6px" title="Cost you entered — not refreshed from a distributor feed">Manual</span>
                @endif""")

io.open(p, 'w', encoding='utf-8').write(s)
print('patched', p)
PY

echo
echo "--- filter gone, label wired ---"
grep -n "MARKER-SOURCING-MANUAL\|'label'\|'manual'\|\$src->label" resources/views/tenant/inventory/show.blade.php | head

echo
echo "--- blade sweep ---"
python3 - <<'PY'
import io, re
f = 'resources/views/tenant/inventory/show.blade.php'
raw = io.open(f, encoding='utf-8').read()
s = re.sub(r'\{\{--.*?--\}\}', '', raw, flags=re.S)
print('glued:', len(re.findall(r'\w@(?:if|endif|foreach|endforeach|else|elseif|unless|php|endphp)\b', s)))
for a, b in [('@if','@endif'), ('@foreach','@endforeach'), ('@php','@endphp'), ('@section','@endsection')]:
    o = len(re.findall(r'\B'+a+r'\b', s)); c = len(re.findall(r'\B'+b+r'\b', s))
    print(' ', a, o, b, c, 'OK' if o == c else 'MISMATCH')
d = 0; nested = False
for m in re.finditer(r'<form\b|</form>', raw):
    d += -1 if m.group(0) == '</form>' else 1
    if d > 1: nested = True
print('forms balanced:', d == 0, 'nested:', nested)
PY

echo
echo "apply-sourcing-shows-manual-vendors: OK"
