#!/bin/bash
# distributor-partial-credentials — fix the silent no-op on a partial edit.
#
#   NOT cosmetic. Two faults that combine into a trap:
#
#   1. packCredentials() returns null for BTI unless BOTH username and
#      password are filled. The form says "Leave the credential blank to keep
#      the saved one", so typing only a corrected password and leaving the
#      username blank saves NOTHING — and says "saved". That is almost
#      certainly why BTI's stored password is still 37 characters with a
#      stray leading character: it was corrected and silently discarded.
#
#   2. Both BTI fields showed the SAME placeholder, the whole masked
#      credential, which is username and password joined by a colon. So the
#      username box hinted at the password, making it look already filled and
#      encouraging exactly the partial edit that then didn't save.
#
#   Fixes:
#     · packCredentials takes the stored value and merges field by field, so
#       a blank field keeps what is stored and a filled one replaces it. That
#       is what the on-screen promise already claimed.
#     · each field hints at its own stored value. The username is shown
#       unmasked because an account number is not a secret, and seeing it
#       saves a trip to the distributor's portal to check which account is
#       connected.
#     · saveKey reports when nothing changed, instead of claiming success on
#       a no-op.
# NO MIGRATION. Server: optimize:clear && view:clear
set -e
if grep -q "MARKER-PARTIAL-CREDS" app/Services/Distributors/DistributorRegistry.php; then
  echo "distributor-partial-credentials already applied — aborting."; exit 1
fi

# ------------------------------------------------------------------ registry
python3 - <<'DPC_0_EOF'
import io
p = 'app/Services/Distributors/DistributorRegistry.php'
s = io.open(p, encoding='utf-8').read()

old = """    public function packCredentials(string $code, array $input): ?string
    {
        if (strtoupper($code) === 'BTI') {
            $u = trim($input['username'] ?? '');
            $p = trim($input['password'] ?? '');
            return ($u === '' || $p === '') ? null : $u . ':' . $p;
        }
        $k = trim($input['api_key'] ?? '');
        return $k === '' ? null : $k;
    }"""
assert s.count(old) == 1, ('pack anchor', s.count(old))

new = """    /**
     * MARKER-PARTIAL-CREDS — merge submitted fields over what is stored.
     *
     * This used to require BOTH BTI fields and return null otherwise. The
     * form tells the user a blank field keeps the saved value, so typing
     * only a corrected password saved nothing at all and still reported
     * success — a silent no-op on the one screen where being wrong is
     * invisible, because the stored value is masked.
     *
     * Now each field replaces its own part and a blank one keeps what is
     * there, which is what the screen already promises.
     *
     * @param  array<string,string> $input
     * @param  string|null $stored  the currently saved credential
     */
    public function packCredentials(string $code, array $input, ?string $stored = null): ?string
    {
        $stored = (string) $stored;

        if (strtoupper($code) === 'BTI') {
            $curUser = '';
            $curPass = '';
            if (str_contains($stored, ':')) {
                [$curUser, $curPass] = explode(':', $stored, 2);
            }

            $u = trim($input['username'] ?? '') ?: $curUser;
            $p = trim($input['password'] ?? '') ?: $curPass;

            // Still nothing to store if neither side has ever been given.
            return ($u === '' || $p === '') ? null : $u . ':' . $p;
        }

        $k = trim($input['api_key'] ?? '');
        return $k !== '' ? $k : ($stored !== '' ? $stored : null);
    }

    /**
     * MARKER-PARTIAL-CREDS — placeholder per credential field.
     *
     * Both BTI fields used to show the same masked string, which is the
     * username and password joined with a colon, so the username box hinted
     * at the password.
     *
     * @return array<string,string>
     */
    public function credentialHints(string $code, ?string $stored, callable $mask): array
    {
        $stored = (string) $stored;
        if ($stored === '') {
            return [];
        }

        if (strtoupper($code) === 'BTI' && str_contains($stored, ':')) {
            [$user, $pass] = explode(':', $stored, 2);
            return [
                // An account number, not a secret.
                'username' => $user,
                'password' => $mask($pass),
            ];
        }

        return ['api_key' => $mask($stored)];
    }"""
s = s.replace(old, new)
io.open(p, 'w', encoding='utf-8').write(s)
print('registry ok')
DPC_0_EOF

# ------------------------------------------------------------------ controller
python3 - <<'DPC_1_EOF'
import io
p = 'app/Http/Controllers/Tenant/DistributorController.php'
s = io.open(p, encoding='utf-8').read()

# hints for the view
old = """                'maskedKey' => $this->mask($creds['api_key'] ?? null),"""
assert s.count(old) == 1, ('hints anchor', s.count(old))
new = """                'maskedKey' => $this->mask($creds['api_key'] ?? null),
                // MARKER-PARTIAL-CREDS — a hint per field, not the whole
                // joined credential under both of them.
                'hints'     => $registry->credentialHints(
                    $code, $creds['api_key'] ?? null, fn ($v) => $this->mask($v)
                ),"""
s = s.replace(old, new)

# pass the stored value into the packer, and don't claim success on a no-op
old = """        $creds = (array) ($sub->credentials_encrypted ?? []);
        $packed = $registry->packCredentials($code, $data);
        if ($packed !== null) {
            $creds['api_key'] = $packed;
            $creds['region'] = $creds['region'] ?? 'us';
        }"""
assert s.count(old) == 1, ('pack call', s.count(old))
new = """        $creds = (array) ($sub->credentials_encrypted ?? []);
        $before = (string) ($creds['api_key'] ?? '');

        // MARKER-PARTIAL-CREDS — hand the stored value in so a blank field
        // keeps its part instead of discarding the whole credential.
        $packed = $registry->packCredentials($code, $data, $before);
        if ($packed !== null) {
            $creds['api_key'] = $packed;
            $creds['region'] = $creds['region'] ?? 'us';
        }"""
s = s.replace(old, new)

old = """        return back()->with('success', $registry->label($code) . ' saved. Test it to confirm access.');"""
assert s.count(old) == 1, ('message', s.count(old))
new = """        // MARKER-PARTIAL-CREDS — say plainly when the credential didn't move.
        // Reporting "saved" on a no-op is what hid the discarded password.
        $after = (string) ($sub->credentials_encrypted['api_key'] ?? '');
        $label = $registry->label($code);

        if ($before !== '' && $after === $before) {
            return back()->with('success', $label . ' updated. The saved credential is unchanged.');
        }

        return back()->with('success', $label . ' credentials saved. Test to confirm access.');"""
s = s.replace(old, new)

io.open(p, 'w', encoding='utf-8').write(s)
print('controller ok')
DPC_1_EOF

# ------------------------------------------------------------------ view
python3 - <<'DPC_2_EOF'
import io
p = 'resources/views/tenant/distributors/connection.blade.php'
s = io.open(p, encoding='utf-8').read()

old = """                     placeholder="{{ $b['hasKey'] ? $b['maskedKey'] : 'paste your ' . $b['label'] . ' ' . strtolower($f['label']) }}">"""
assert s.count(old) == 1, ('placeholder', s.count(old))
new = """                     {{-- MARKER-PARTIAL-CREDS — each field hints at ITS OWN stored
                          value. Both BTI fields used to show the whole joined
                          credential, so the username box hinted at the password. --}}
                     placeholder="{{ $b['hints'][$f['name']] ?? ('paste your ' . $b['label'] . ' ' . strtolower($f['label'])) }}">"""
s = s.replace(old, new)

old = """            Leave the credential blank to keep the saved one."""
assert s.count(old) == 1, ('note', s.count(old))
new = """            Leave a field blank to keep the value already saved for it."""
s = s.replace(old, new)

io.open(p, 'w', encoding='utf-8').write(s)
print('view ok')
DPC_2_EOF

echo
echo "distributor-partial-credentials applied."
