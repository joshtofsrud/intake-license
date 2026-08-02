#!/usr/bin/env bash
# apply-distributor-vendor-link-prompt.sh
# MARKER-DIST-VENDOR-PROMPT — ask which vendor this distributor IS, at the
# moment the shop turns the sync on.
#
# The previous patch added tenant_vendors.distributor_code and made
# vendorFor() prefer it, which heals installs whose vendor was already
# named after the code. It does NOTHING for the case that matters going
# forward:
#
#   a shop creates "Bicycle Technologies" by hand, with contact details,
#   account number, free-freight minimum and program discount on it, then
#   enables the BTI sync later. vendorFor() finds no vendor with
#   distributor_code='bti', no vendor literally named 'bti', and creates a
#   SECOND one. Every imported item, cost and availability figure hangs off
#   the empty duplicate while the real record sits beside it doing nothing —
#   including the freight minimum and discount that make lowest-price work.
#
# The connection screen is the only place the answer is unambiguous, so the
# question goes there: a Vendor select on the credential form, listing the
# shop's vendors, defaulting to whichever is already linked.
#
# Enforces ONE vendor per distributor per tenant: assigning the code clears
# it from any other vendor that held it. Two vendors both claiming 'bti'
# means vendorFor() picks whichever the database returns first, so future
# imports attach to one while existing items hang off the other.
#
# Leaving it on "Not linked" preserves today's behaviour exactly — the
# importer creates a vendor named after the code on first import.
set -e

python3 <<'PY'
import io

# ---------------------------------------------------------------- controller
p = 'app/Http/Controllers/Tenant/DistributorController.php'
s = io.open(p, encoding='utf-8').read()

old = """            $boxes[] = [
                'code'      => $code,
                'label'     => $registry->label($code),
                'sub'       => $sub,"""
assert s.count(old) == 1, 'D1 box array anchor'
s = s.replace(old, """            $boxes[] = [
                'code'      => $code,
                'label'     => $registry->label($code),
                'sub'       => $sub,
                // MARKER-DIST-VENDOR-PROMPT — which of the shop's vendors IS
                // this distributor. Asked here because it's the one moment the
                // answer is unambiguous.
                'vendors'   => \\App\\Models\\Tenant\\TenantVendor::where('tenant_id', tenant()->id)
                    ->where('is_active', true)->orderBy('name')->get(['id', 'name', 'distributor_code']),
                'linkedVendorId' => \\App\\Models\\Tenant\\TenantVendor::where('tenant_id', tenant()->id)
                    ->where('distributor_code', strtolower($code))->value('id'),""")

# saveKey: accept and apply the choice
old = """            'account_number'   => ['nullable', 'string', 'max:64'],
        ]);"""
assert s.count(old) == 1, 'D2 saveKey validation anchor'
s = s.replace(old, """            'account_number'   => ['nullable', 'string', 'max:64'],
            'vendor_id'        => ['nullable', 'string', 'max:64'], // MARKER-DIST-VENDOR-PROMPT
        ]);""")

old = """        $sub->credentials_encrypted = $creds;
        $sub->account_number = $data['account_number'] ?? $sub->account_number;"""
assert s.count(old) == 1, 'D3 saveKey persist anchor'
s = s.replace(old, """        // MARKER-DIST-VENDOR-PROMPT — bind the distributor to a vendor the shop
        // already has, so the importer stops inventing a duplicate.
        $vendorId = trim((string) ($data['vendor_id'] ?? ''));
        if ($vendorId !== '') {
            $target = \\App\\Models\\Tenant\\TenantVendor::where('tenant_id', tenant()->id)
                ->where('id', $vendorId)->first();

            if ($target) {
                // One vendor per distributor per tenant. Two rows claiming the
                // same code means vendorFor() picks whichever the DB returns
                // first, so imports attach to one while existing items hang off
                // the other.
                \\App\\Models\\Tenant\\TenantVendor::where('tenant_id', tenant()->id)
                    ->where('distributor_code', strtolower($code))
                    ->where('id', '!=', $target->id)
                    ->update(['distributor_code' => null]);

                $target->update(['distributor_code' => strtolower($code)]);
            }
        }

        $sub->credentials_encrypted = $creds;
        $sub->account_number = $data['account_number'] ?? $sub->account_number;""")

io.open(p, 'w', encoding='utf-8').write(s)
print('patched', p)

# ---------------------------------------------------------------- view
p = 'resources/views/tenant/distributors/connection.blade.php'
s = io.open(p, encoding='utf-8').read()

old = """          <div class=\"dc-field\" style=\"max-width:180px\">
            <label>Account #</label>
            <input class=\"dc-input\" type=\"text\" name=\"account_number\"
                   value=\"{{ $b['sub']->account_number }}\" placeholder=\"optional\">
          </div>
        </div>"""
assert s.count(old) == 1, 'V1 account field anchor'
s = s.replace(old, """          <div class=\"dc-field\" style=\"max-width:180px\">
            <label>Account #</label>
            <input class=\"dc-input\" type=\"text\" name=\"account_number\"
                   value=\"{{ $b['sub']->account_number }}\" placeholder=\"optional\">
          </div>
        </div>

        {{-- MARKER-DIST-VENDOR-PROMPT — which vendor is this distributor --}}
        <div class=\"dc-row\">
          <div class=\"dc-field\" style=\"max-width:320px\">
            <label>This distributor is which of your vendors?</label>
            <select class=\"dc-input\" name=\"vendor_id\">
              <option value=\"\">Not linked — create one on first import</option>
              @foreach ($b['vendors'] as $v)
                <option value=\"{{ $v->id }}\" @selected($b['linkedVendorId'] === $v->id)>
                  {{ $v->name }}@if($v->distributor_code && $v->distributor_code !== strtolower($b['code'])) (linked to {{ strtoupper($v->distributor_code) }})@endif
                </option>
              @endforeach
            </select>
            <div style=\"font-size:11.5px;color:var(--ia-text-dim);margin-top:5px;line-height:1.5\">
              @if ($b['linkedVendorId'])
                Imported items, costs and stock attach to this vendor, and its
                free-freight minimum and program discount are what the
                lowest-price rule compares.
              @else
                Pick the vendor you already use for {{ $b['label'] }}. Leave it
                unlinked and the first import creates a separate vendor called
                {{ strtoupper($b['code']) }}, leaving your own record — and its
                freight minimum and discount — out of the picture.
              @endif
            </div>
          </div>
        </div>""")

io.open(p, 'w', encoding='utf-8').write(s)
print('patched', p)
PY

echo "--- wiring ---"
grep -n "MARKER-DIST-VENDOR-PROMPT" app/Http/Controllers/Tenant/DistributorController.php resources/views/tenant/distributors/connection.blade.php

echo
echo "--- blade sweep ---"
python3 - <<'PY'
import io, re
raw = io.open('resources/views/tenant/distributors/connection.blade.php', encoding='utf-8').read()
s = re.sub(r'\{\{--.*?--\}\}', '', raw, flags=re.S)
print('glued:', len(re.findall(r'\w@(?:if|endif|foreach|endforeach|elseif|else|unless|php|endphp)\b', s)))
for a, b in [('@if','@endif'), ('@foreach','@endforeach'), ('@php','@endphp'), ('@section','@endsection')]:
    o = len(re.findall(r'\B'+a+r'\b', s)); c = len(re.findall(r'\B'+b+r'\b', s))
    print(a, o, b, c, 'OK' if o == c else 'MISMATCH')
PY

echo
echo "--- balance ---"
python3 - <<'PY'
import io
s = io.open('app/Http/Controllers/Tenant/DistributorController.php', encoding='utf-8').read()
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
print('DistributorController braces', d, 'parens', par)
PY

echo
echo "apply-distributor-vendor-link-prompt: OK"
