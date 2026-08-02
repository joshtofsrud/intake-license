#!/usr/bin/env bash
# apply-vendor-distributor-badge.sh
# MARKER-VENDOR-CODE-BADGE — make the distributor link visible.
#
# distributor_code is stored but rendered nowhere: not in the tenant's vendor
# list, not on the vendor edit page. So a shop can call its BTI account
# anything at all and there is no way — for them or for support — to tell
# which vendor is actually the feed.
#
# That gap is what made forcing the merge to keep the distributor's name look
# necessary. It isn't: the code identifies the account unambiguously and, unlike
# a name, the tenant cannot type over it. The shop keeps the name that means
# something to them; the code rides alongside it.
#
# Set only by linking on Connection & sync, never by hand on the vendor form —
# the select there already writes it, and a free-text field would let a shop
# claim to be a feed it has no credentials for.
set -e

python3 <<'PY'
import io

# ---------------------------------------------------------------- vendor list
p = 'resources/views/tenant/vendors/index.blade.php'
s = io.open(p, encoding='utf-8').read()

old = """            <td>
              <strong>{{ $v->name }}</strong>"""
assert s.count(old) == 1, 'V1 desk row anchor'
s = s.replace(old, """            <td>
              <strong>{{ $v->name }}</strong>
              {{-- MARKER-VENDOR-CODE-BADGE — which feed this vendor IS. Survives
                   any rename, which a name-based convention could not. --}}
              @if($v->distributor_code)
                <span class="ia-badge" style="margin-left:6px"
                      title="Linked to your {{ strtoupper($v->distributor_code) }} catalog feed">{{ strtoupper($v->distributor_code) }}</span>
              @endif""")

old = """          <span class="vendor-card-name">{{ $v->name }}</span>"""
assert s.count(old) == 1, 'V2 mobile card anchor'
s = s.replace(old, """          <span class="vendor-card-name">{{ $v->name }}</span>
          {{-- MARKER-VENDOR-CODE-BADGE --}}
          @if($v->distributor_code)
            <span class="ia-badge" style="margin-left:6px">{{ strtoupper($v->distributor_code) }}</span>
          @endif""")

io.open(p, 'w', encoding='utf-8').write(s)
print('patched', p)

# ---------------------------------------------------------------- vendor edit
p = 'resources/views/tenant/vendors/edit.blade.php'
s = io.open(p, encoding='utf-8').read()

old = """    <h1 class="ia-page-title">Edit vendor</h1>"""
assert s.count(old) == 1, 'V3 edit heading anchor'
s = s.replace(old, """    <h1 class="ia-page-title">
      Edit vendor
      {{-- MARKER-VENDOR-CODE-BADGE — set by linking on Connection & sync, not
           editable here: a free-text field would let a shop claim a feed it has
           no credentials for. --}}
      @if($vendor->distributor_code)
        <span class="ia-badge" style="margin-left:8px;vertical-align:middle"
              title="This vendor is your {{ strtoupper($vendor->distributor_code) }} catalog feed. Change it from Distributors → Connection &amp; sync.">{{ strtoupper($vendor->distributor_code) }}</span>
      @endif
    </h1>""")

io.open(p, 'w', encoding='utf-8').write(s)
print('patched', p)
PY

echo
echo "--- badge placement ---"
grep -n "MARKER-VENDOR-CODE-BADGE" resources/views/tenant/vendors/index.blade.php resources/views/tenant/vendors/edit.blade.php

echo
echo "--- blade sweep ---"
python3 - <<'PY'
import io, re
for f in ['resources/views/tenant/vendors/index.blade.php',
          'resources/views/tenant/vendors/edit.blade.php']:
    s = re.sub(r'\{\{--.*?--\}\}', '', io.open(f, encoding='utf-8').read(), flags=re.S)
    out = [f.split('/')[-1], 'glued=%d' % len(re.findall(r'\w@(?:if|endif|foreach|endforeach|else|elseif|unless|php|endphp)\b', s))]
    for a, b in [('@if','@endif'), ('@foreach','@endforeach'), ('@php','@endphp')]:
        o = len(re.findall(r'\B'+a+r'\b', s)); c = len(re.findall(r'\B'+b+r'\b', s))
        if o != c: out.append('MISMATCH %s %d/%d' % (a, o, c))
    print('  '.join(out))
PY

echo
echo "apply-vendor-distributor-badge: OK"
