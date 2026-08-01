#!/bin/bash
# tenant-distributor-route-code — put the distributor in the URL.
#
#   Testing the BTI box reported "Connected to HLC." The code was travelling
#   as a hidden field inside the credential form, read with a fallback:
#       $request->input('distributor_code', self::CODE)
#   and that fallback is HLC. So any path where the field doesn't arrive —
#   and with a `formaction` submit it evidently doesn't, reliably — silently
#   tests the wrong distributor instead of failing.
#
#   A default that is itself a valid distributor is the problem: it turns a
#   missing value into a confident wrong answer. Now the code is a route
#   parameter, so it cannot be absent, cannot be defaulted, and a bad one
#   404s rather than quietly acting on HLC.
#
#   Same for save and reorder — all three now carry it in the path.
#
#   Also fixes the placeholders visible on the BTI box: both the username and
#   password fields showed the same masked value, which is the two joined
#   with a colon, so the username box was hinting at the password. Each field
#   now hints at its own stored value, and the username isn't masked because
#   it's an account number, not a secret.
# NO MIGRATION. Server: optimize:clear && view:clear
set -e
if grep -q "MARKER-ROUTE-CODE" app/Http/Controllers/Tenant/DistributorController.php; then
  echo "tenant-distributor-route-code already applied — aborting."; exit 1
fi

# ------------------------------------------------------------------ routes
python3 - <<'TRC_0_EOF'
import io
p = 'routes/web.php'
s = io.open(p, encoding='utf-8').read()

old = """                Route::post('/connection/key',    [TenantControllers\\DistributorController::class, 'saveKey'])->name('connection.key');"""
assert s.count(old) == 1, s.count(old)
new = """                // MARKER-ROUTE-CODE — the distributor is a path segment, not a
                // form field with an HLC default. A missing field used to
                // become a confident wrong answer.
                Route::post('/connection/{code}/key',      [TenantControllers\\DistributorController::class, 'saveKey'])->name('connection.key');
                Route::post('/connection/{code}/test',     [TenantControllers\\DistributorController::class, 'testConnection'])->name('connection.test');
                Route::post('/connection/{code}/refresh',  [TenantControllers\\DistributorController::class, 'refreshSync'])->name('connection.refresh');
                Route::post('/connection/{code}/priority', [TenantControllers\\DistributorController::class, 'movePriority'])->name('connection.priority');"""
s = s.replace(old, new)

# drop the old bodyless routes
for dead in [
    """
                Route::post('/connection/test',   [TenantControllers\\DistributorController::class, 'testConnection'])->name('connection.test');""",
    """
                Route::post('/connection/refresh',[TenantControllers\\DistributorController::class, 'refreshSync'])->name('connection.refresh');""",
]:
    assert s.count(dead) == 1, ('dead route', dead[:60], s.count(dead))
    s = s.replace(dead, "")

import re
old_pri = re.search(r"\n\s*// MARKER-PRIORITY-ORDER[^\n]*\n(\s*//[^\n]*\n)*\s*Route::post\('/connection/priority'[^\n]*\n", s)
assert old_pri, 'priority route not found'
s = s[:old_pri.start()] + "\n" + s[old_pri.end():]

io.open(p, 'w', encoding='utf-8').write(s)
print('routes ok')
TRC_0_EOF

# ------------------------------------------------------------------ controller
python3 - <<'TRC_1_EOF'
import io
p = 'app/Http/Controllers/Tenant/DistributorController.php'
s = io.open(p, encoding='utf-8').read()

# saveKey
old = """        $data = $request->validate([
            'distributor_code' => ['required', 'string', 'max:32'],
            'api_key'          => ['nullable', 'string', 'max:255'],"""
assert s.count(old) == 1, s.count(old)
new = """        $data = $request->validate([
            'api_key'          => ['nullable', 'string', 'max:255'],"""
s = s.replace(old, new)

old = """        $registry = app(\\App\\Services\\Distributors\\DistributorRegistry::class);
        $code = strtoupper($data['distributor_code']);
        abort_unless($registry->isSupported($code), 404);"""
assert s.count(old) == 1, s.count(old)
new = """        // MARKER-ROUTE-CODE — from the path; abort rather than default.
        $registry = app(\\App\\Services\\Distributors\\DistributorRegistry::class);
        $code = strtoupper($code);
        abort_unless($registry->isSupported($code), 404);"""
s = s.replace(old, new)

s = s.replace("public function saveKey(Request $request): RedirectResponse",
              "public function saveKey(Request $request, string $code): RedirectResponse")

# testConnection
old = """    public function testConnection(Request $request): RedirectResponse
    {
        $this->guard();
        $registry = app(DistributorRegistry::class);
        $code = strtoupper((string) $request->input('distributor_code', self::CODE));
        $label = $registry->label($code);"""
assert s.count(old) == 1, s.count(old)
new = """    // MARKER-ROUTE-CODE — the distributor comes from the URL. It used to be
    // a hidden form field read with an HLC fallback, so a submit that didn't
    // carry it tested HLC and cheerfully reported success for the wrong
    // distributor.
    public function testConnection(Request $request, string $code): RedirectResponse
    {
        $this->guard();
        $registry = app(DistributorRegistry::class);
        $code = strtoupper($code);
        abort_unless($registry->isSupported($code), 404);
        $label = $registry->label($code);"""
s = s.replace(old, new)

# refreshSync
old = """    public function refreshSync(Request $request): RedirectResponse
    {
        $this->guard();
        $registry = app(DistributorRegistry::class);
        $code = strtoupper((string) $request->input('distributor_code', self::CODE));
        $label = $registry->label($code);"""
assert s.count(old) == 1, s.count(old)
new = """    // MARKER-ROUTE-CODE
    public function refreshSync(Request $request, string $code): RedirectResponse
    {
        $this->guard();
        $registry = app(DistributorRegistry::class);
        $code = strtoupper($code);
        abort_unless($registry->isSupported($code), 404);
        $label = $registry->label($code);"""
s = s.replace(old, new)

# movePriority
old = """        $data = $request->validate([
            'distributor_code' => ['required', 'string', 'max:32'],
            'direction'        => ['required', 'in:up,down'],
        ]);

        $code = strtoupper($data['distributor_code']);"""
assert s.count(old) == 1, s.count(old)
new = """        $data = $request->validate([
            'direction' => ['required', 'in:up,down'],
        ]);

        // MARKER-ROUTE-CODE
        $code = strtoupper($code);"""
s = s.replace(old, new)

s = s.replace("public function movePriority(Request $request): RedirectResponse",
              "public function movePriority(Request $request, string $code): RedirectResponse")

io.open(p, 'w', encoding='utf-8').write(s)
print('controller ok')
TRC_1_EOF

# ------------------------------------------------------------------ view
python3 - <<'TRC_2_EOF'
import io
p = 'resources/views/tenant/distributors/connection.blade.php'
s = io.open(p, encoding='utf-8').read()

# routes now take the code
s = s.replace("""route('tenant.distributors.connection.priority')""",
              """route('tenant.distributors.connection.priority', $b['code'])""")
s = s.replace("""route('tenant.distributors.connection.key')""",
              """route('tenant.distributors.connection.key', $b['code'])""")
s = s.replace("""route('tenant.distributors.connection.test')""",
              """route('tenant.distributors.connection.test', $b['code'])""")

# the hidden fields are now redundant
s = s.replace("""            <input type="hidden" name="distributor_code" value="{{ $b['code'] }}">
""", "")
s = s.replace("""        <input type="hidden" name="distributor_code" value="{{ $b['code'] }}">
""", "")

# --- per-field placeholders ---------------------------------------------
old = """              <input class="dc-input" type="{{ $f['type'] === 'password' ? 'text' : $f['type'] }}"
                     name="{{ $f['name'] }}" autocomplete="off"
                     placeholder="{{ $b['hasKey'] ? $b['maskedKey'] : 'paste your ' . $b['label'] . ' ' . strtolower($f['label']) }}">"""
assert s.count(old) == 1, s.count(old)
new = """              {{-- MARKER-ROUTE-CODE — each field hints at ITS OWN stored value.
                   Both BTI fields used to show the same masked string, which is
                   the username and password joined with a colon, so the
                   username box was hinting at the password. A username is an
                   account number, not a secret, so it isn't masked. --}}
              <input class="dc-input" type="{{ $f['type'] === 'password' ? 'text' : $f['type'] }}"
                     name="{{ $f['name'] }}" autocomplete="off"
                     placeholder="{{ $b['hints'][$f['name']] ?? ('paste your ' . $b['label'] . ' ' . strtolower($f['label'])) }}">"""
s = s.replace(old, new)

io.open(p, 'w', encoding='utf-8').write(s)
print('view ok; leftover hidden fields:', s.count('name="distributor_code"'))
TRC_2_EOF

# ------------------------------------------------------- per-field hints
python3 - <<'TRC_3_EOF'
import io
p = 'app/Http/Controllers/Tenant/DistributorController.php'
s = io.open(p, encoding='utf-8').read()

old = """                'hasKey'    => filled($creds['api_key'] ?? null),
                'maskedKey' => $this->mask($creds['api_key'] ?? null),"""
assert s.count(old) == 1, s.count(old)
new = """                'hasKey'    => filled($creds['api_key'] ?? null),
                'maskedKey' => $this->mask($creds['api_key'] ?? null),
                // MARKER-ROUTE-CODE — a hint per field. The stored credential
                // is one string; BTI's is "username:password", so showing the
                // whole masked value under BOTH fields hinted at the password
                // in the username box.
                'hints'     => $this->credentialHints($code, $creds),"""
s = s.replace(old, new)

old = """    public function saveKey(Request $request, string $code): RedirectResponse"""
assert s.count(old) == 1, s.count(old)
new = """    /**
     * MARKER-ROUTE-CODE — placeholder per credential field.
     *
     * @return array<string,string>
     */
    private function credentialHints(string $code, array $creds): array
    {
        $stored = (string) ($creds['api_key'] ?? '');
        if ($stored === '') {
            return [];
        }

        if (strtoupper($code) === 'BTI' && str_contains($stored, ':')) {
            [$user, $pass] = explode(':', $stored, 2);
            return [
                // An account number, not a secret — showing it saves a trip
                // to the BTI portal to check which account is connected.
                'username' => $user,
                'password' => $this->mask($pass),
            ];
        }

        return ['api_key' => $this->mask($stored)];
    }

    public function saveKey(Request $request, string $code): RedirectResponse"""
s = s.replace(old, new)

io.open(p, 'w', encoding='utf-8').write(s)
print('hints ok')
TRC_3_EOF

echo
echo "tenant-distributor-route-code applied."
