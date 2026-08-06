#!/usr/bin/env bash
# apply-cls-hint.sh
# MARKER-CLS-HINT — show that a CLS key is stored.
#
# The key saves correctly, but the tenant page renders no hint for it, so the
# box looks empty on every reload and there is no way to tell a saved key from
# an unsaved one. Worse, the API1 box was masking the WHOLE "api1:cls" string,
# so its hint described a credential that is not the API1 key.
#
# credentialHints() already solves this for BTI's username/password pair; it
# simply had no QBP branch and fell through to masking the joined value.
#
# The CLS key is masked like any secret. BTI's username stays unmasked because
# an account number is not one — that distinction already existed and is left
# alone.
set -e

python3 <<'PY'
import io

p = 'app/Services/Distributors/DistributorRegistry.php'
s = io.open(p, encoding='utf-8').read()

assert 'MARKER-CLS-HINT' not in s, 'already applied'

old = """        if (strtoupper($code) === 'BTI' && str_contains($stored, ':')) {
            [$user, $pass] = explode(':', $stored, 2);
            return [
                // An account number, not a secret.
                'username' => $user,
                'password' => $mask($pass),
            ];
        }

        return ['api_key' => $mask($stored)];"""
assert s.count(old) == 1, 'H1 hints anchor'
s = s.replace(old, """        if (strtoupper($code) === 'BTI' && str_contains($stored, ':')) {
            [$user, $pass] = explode(':', $stored, 2);
            return [
                // An account number, not a secret.
                'username' => $user,
                'password' => $mask($pass),
            ];
        }

        // MARKER-CLS-HINT — QBP packs "api1:cls". Without this the API1 box
        // showed a mask of the JOINED string and the CLS box showed nothing,
        // so a saved licence key looked like an empty field on every reload.
        if (strtoupper($code) === 'QBP') {
            if (str_contains($stored, ':')) {
                [$api, $cls] = explode(':', $stored, 2);
                return [
                    'api_key' => $mask($api),
                    'cls_key' => $mask($cls),
                ];
            }
            // API1 only — a valid state. Images are the sole casualty.
            return ['api_key' => $mask($stored)];
        }

        return ['api_key' => $mask($stored)];""")

io.open(p, 'w', encoding='utf-8').write(s)
print('patched', p)
PY

echo
echo "--- QBP now gets a hint per field ---"
grep -n "MARKER-CLS-HINT" -A 12 app/Services/Distributors/DistributorRegistry.php | grep -n "cls_key\|api_key" | head -4

echo
echo "--- hints, per stored shape ---"
python3 - <<'PY'
def mask(v):
    v = str(v)
    return '' if v == '' else ('•' * max(0, len(v) - 4)) + v[-4:]

def hints(code, stored):
    stored = str(stored)
    if stored == '': return {}
    if code == 'BTI' and ':' in stored:
        u, p = stored.split(':', 1)
        return {'username': u, 'password': mask(p)}
    if code == 'QBP':
        if ':' in stored:
            a, c = stored.split(':', 1)
            return {'api_key': mask(a), 'cls_key': mask(c)}
        return {'api_key': mask(stored)}
    return {'api_key': mask(stored)}

for code, stored in [('QBP', 'AAAA1111BBBB:CCCC2222DDDD'),
                     ('QBP', 'AAAA1111BBBB'),
                     ('QBP', ''),
                     ('BTI', '500169:hunter2'),
                     ('HLC', 'ZZZZ9999')]:
    h = hints(code, stored)
    print('  %-4s %-28s -> %s' % (code, stored or '(empty)',
          ', '.join(f'{k}={v}' for k, v in h.items()) or '(none)'))

# The API1 hint must describe the API1 key, not the joined string.
h = hints('QBP', 'AAAA1111BBBB:CCCC2222DDDD')
assert h['api_key'] == mask('AAAA1111BBBB'), h
assert h['cls_key'] == mask('CCCC2222DDDD'), h
assert 'cls_key' not in hints('QBP', 'AAAA1111BBBB')
assert hints('BTI', '500169:hunter2')['username'] == '500169'
print('  api1 hint no longer masks the joined value: OK')
PY

echo
echo "--- balance ---"
python3 - <<'PY'
import io
s = io.open('app/Services/Distributors/DistributorRegistry.php', encoding='utf-8').read()
i, n, d, par, brk = 0, len(s), 0, 0, 0
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
        elif c == '[': brk += 1
        elif c == ']': brk -= 1
        i += 1
print('DistributorRegistry braces', d, 'parens', par, 'brackets', brk)
PY

echo
echo "apply-cls-hint: OK"
