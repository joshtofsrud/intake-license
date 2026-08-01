#!/usr/bin/env bash
# apply-from-address-replyable.sh
# MARKER-FROM-REPLYABLE — send FROM an address that receives.
#
# Customers copy or save the From address, not the Reply-To. Ours has been
# {subdomain}@intake.works, which receives nothing: the apex MX points at
# Cloudflare Email Routing for josh@intake.works, so anything sent there
# either bounces or disappears.
#
# Moving From onto {subdomain}@reply.intake.works — the same address the
# cold path already routes — means every way a customer might reach back
# works: reply, copy-paste, or an address saved in their records.
#
# PREREQUISITE: reply.intake.works must be verified as a SENDER domain in
# Postmark with its own DKIM record. Having an MX there is not enough —
# that is inbound only. Unverified, Postmark rejects the send or ships it
# unsigned from a brand-new subdomain, which is a fast trip to spam.
#
# Two independent changes, which is why this looked done and wasn't:
#   * Tenant::emailFromAddress() builds the real From — all three
#     EmailService paths read it.
#   * The Settings field hardcoded its own copy of the string
#     (MARKER-PATCH-143), so display and reality were two strings that
#     happened to agree. It now reads the method, so they cannot drift.
#
# Unaffected: WelcomeEmail and other platform mail send from
# config('mail.from.address') on the apex, which is correct — that is
# Intake writing to a tenant, not a shop writing to its customer.
#
# Model + view: optimize:clear and an fpm cycle.
set -e

python3 <<'PY'
import io

# ---------------------------------------------------------------- model
p = 'app/Models/Tenant.php'
s = io.open(p, encoding='utf-8').read()

old = """        return $this->email_from_address
            ?: ($this->subdomain . '@intake.works');"""
assert s.count(old) == 1, 'F1 emailFromAddress body anchor'
s = s.replace(old, """        // MARKER-FROM-REPLYABLE — prefer the inbound domain so the From is an
        // address customers can actually write to. The old apex fallback
        // stays last: it keeps sending working if inbound is unconfigured,
        // and it is the address Postmark is already verified for.
        return $this->email_from_address
            ?: ($this->inboundAddress()
            ?: ($this->subdomain . '@intake.works'));""")

io.open(p, 'w', encoding='utf-8').write(s)
print('patched', p)

# ---------------------------------------------------------------- settings view
p = 'resources/views/tenant/settings/index.blade.php'
s = io.open(p, encoding='utf-8').read()

old = """      {{-- MARKER-PATCH-143 — From address locked to <subdomain>@intake.works until custom domains land --}}"""
assert s.count(old) == 1, 'F2 settings comment anchor'
s = s.replace(old, """      {{-- MARKER-FROM-REPLYABLE — reads emailFromAddress() rather than rebuilding
           the string, so the field can't disagree with what actually sends --}}""")

old = """          <input type=\"email\" class=\"ia-input\" readonly disabled
            value=\"{{ $currentTenant->subdomain }}@intake.works\"
            style=\"opacity:.7;cursor:not-allowed\">
          <div style=\"font-size:11px;color:var(--ia-text-dim);margin-top:4px\">
            All your customer emails come from this address. Custom domains coming soon.
          </div>"""
assert s.count(old) == 1, 'F3 settings input anchor'
s = s.replace(old, """          <input type=\"email\" class=\"ia-input\" readonly disabled
            value=\"{{ $currentTenant->emailFromAddress() }}\"
            style=\"opacity:.7;cursor:not-allowed\">
          <div style=\"font-size:11px;color:var(--ia-text-dim);margin-top:4px\">
            All your customer emails come from this address, and customers can write
            back to it — replies land in your Inbox. Custom domains coming soon.
          </div>""")

io.open(p, 'w', encoding='utf-8').write(s)
print('patched', p)
PY

echo "--- resolution order ---"
sed -n '/public function emailFromAddress/,/^    }$/p' app/Models/Tenant.php

echo
echo "--- balance + blade sweep ---"
python3 - <<'PY'
import io, re
s = io.open('app/Models/Tenant.php', encoding='utf-8').read()
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
print('Tenant.php braces', d, 'parens', par)

v = re.sub(r'\{\{--.*?--\}\}', '', io.open('resources/views/tenant/settings/index.blade.php', encoding='utf-8').read(), flags=re.S)
print('glued:', len(re.findall(r'\w@(?:if|endif|foreach|endforeach|elseif|else|unless|php|endphp)\b', v)))
o = len(re.findall(r'\B@if\b', v)); c2 = len(re.findall(r'\B@endif\b', v))
print('@if', o, '@endif', c2, 'OK' if o == c2 else 'MISMATCH')
PY

echo
echo "apply-from-address-replyable: OK"
