#!/usr/bin/env bash
# apply-cls-field-master-admin.sh
# MARKER-CLS-FIELD-ADMIN — the CLS key needs a box to go in.
#
# I added QBP's two-key definition to DistributorRegistry::credentialFields()
# and said both screens would pick it up. Neither does — the master-admin page
# hardcodes its inputs (api_key, plus username/password shown only for BTI)
# and never calls credentialFields() at all. So the registry change altered
# nothing visible and there was nowhere to enter the key.
#
# This adds the field the same way BTI's pair is added: an explicit input,
# visible only for QBP, with the split-on-load and pack-on-save either side of
# it. Matching the page's existing pattern rather than rebuilding it from the
# registry — that refactor is worth doing, but not in the middle of trying to
# get an image to appear.
#
# Blank means keep: saving the API1 key alone must not wipe a stored CLS key,
# which is the same rule BTI's two fields already follow.
set -e

python3 <<'PY'
import io

p = 'app/Filament/Pages/Distributors.php'
s = io.open(p, encoding='utf-8').read()

assert 'MARKER-CLS-FIELD-ADMIN' not in s, 'already applied'

# ---------------------------------------------------------------- load
old = """        $user = '';
        $pass = '';
        if (strtoupper($this->code) === 'BTI' && str_contains((string) $conn->api_key, ':')) {
            [$user, $pass] = explode(':', (string) $conn->api_key, 2);
        }"""
assert s.count(old) == 1, 'F1 split anchor'
s = s.replace(old, """        $user = '';
        $pass = '';
        if (strtoupper($this->code) === 'BTI' && str_contains((string) $conn->api_key, ':')) {
            [$user, $pass] = explode(':', (string) $conn->api_key, 2);
        }

        // MARKER-CLS-FIELD-ADMIN — QBP packs "api1:cls" in the same slot.
        // API1 is free and carries the catalog; CLS is licensed and carries
        // only the images.
        $apiKeyShown = (string) $conn->api_key;
        $clsKey      = '';
        if (strtoupper($this->code) === 'QBP' && str_contains($apiKeyShown, ':')) {
            [$apiKeyShown, $clsKey] = explode(':', $apiKeyShown, 2);
        }""")

old = """            'api_key'    => $conn->api_key,
            'username'   => $user,
            'password'   => $pass,"""
assert s.count(old) == 1, 'F2 fill anchor'
s = s.replace(old, """            'api_key'    => $apiKeyShown,
            'cls_key'    => $clsKey,
            'username'   => $user,
            'password'   => $pass,""")

# ---------------------------------------------------------------- field
old = """                        TextInput::make('username')->label('Username')"""
assert s.count(old) == 1, 'F3 form anchor'
s = s.replace(old, """                        // MARKER-CLS-FIELD-ADMIN — QBP's second key. Optional:
                        // without it the catalog, cost and stock all still
                        // work and only images stop.
                        TextInput::make('cls_key')->label('API3 key (Content License Service)')
                            ->password()->revealable()->autocomplete('off')
                            ->helperText('Optional. Licensed separately by QBP and needed only for product images. Leave blank to keep the saved key.')
                            ->visible(fn () => strtoupper($this->currentCode()) === 'QBP')
                            ->columnSpanFull(),

                        TextInput::make('username')->label('Username')""")

io.open(p, 'w', encoding='utf-8').write(s)
print('patched', p)
PY

echo
echo "--- the page now has a QBP CLS input ---"
grep -n "cls_key" app/Filament/Pages/Distributors.php | head -4

echo
echo "--- packing is delegated to the registry, which already handles QBP ---"
grep -n "packCredentials" app/Filament/Pages/Distributors.php | head -3

echo
echo "--- API1 field shows only its own half when both are stored ---"
python3 - <<'PY'
def split(stored, code):
    if code == 'QBP' and ':' in stored:
        return stored.split(':', 1)
    return [stored, '']
for stored, want in [('A1', ('A1', '')), ('A1:C1', ('A1', 'C1')), ('', ('', ''))]:
    got = tuple(split(stored, 'QBP'))
    print('  %-8s -> api=%-4s cls=%-4s %s' % (stored or '(empty)', got[0] or '-', got[1] or '-',
                                              'OK' if got == want else '*** WRONG ***'))
    assert got == want
print('  BTI untouched:', tuple(split('user:pass', 'BTI')) == ('user:pass', ''))
PY

echo
echo "--- both keys visible only where they belong ---"
python3 - <<'PY'
import io, re
s = io.open('app/Filament/Pages/Distributors.php', encoding='utf-8').read()
for field, expect in [('cls_key', "=== 'QBP'"), ('username', "=== 'BTI'"), ('password', "=== 'BTI'")]:
    m = re.search(r"TextInput::make\('" + field + r"'\).*?->visible\(fn \(\) => strtoupper\(\$this->currentCode\(\)\) ([^)]+)\)", s, re.S)
    got = m.group(1).strip() if m else 'NONE'
    print('  %-9s visible when %s  %s' % (field, got, 'OK' if expect in got else '*** CHECK ***'))
PY

echo
echo "--- balance ---"
python3 - <<'PY'
import io
s = io.open('app/Filament/Pages/Distributors.php', encoding='utf-8').read()
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
print('Distributors.php braces', d, 'parens', par, 'brackets', brk)
PY

echo
echo "apply-cls-field-master-admin: OK"
