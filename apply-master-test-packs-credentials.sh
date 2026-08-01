#!/bin/bash
# master-test-packs-credentials — testing BTI tested the wrong credentials.
#
#   testConnection() opened with $conn->update($this->form->getState()) —
#   "persist what's on screen so we test what's on screen". That was fine
#   when every distributor had a single api_key field. It isn't now.
#
#   For BTI the form state carries `username` and `password`, which are not
#   columns on platform_distributor_connections, and carries NO api_key. So
#   the update wrote fields that don't exist and left api_key untouched — the
#   test then ran against whatever was stored before, not what was typed.
#
#   That is very likely why a corrected BTI password still returned 401: the
#   corrected value was never used.
#
#   Now it packs through the registry, exactly as save() does, so the same
#   typed credential is what gets stored and what gets tested. Also stops
#   using update() with unfiltered form state, which is what let this happen.
# NO MIGRATION. Server: optimize:clear && php artisan filament:cache-components
set -e
if grep -q "MARKER-TEST-PACKS-CREDS" app/Filament/Pages/Distributors.php; then
  echo "master-test-packs-credentials already applied — aborting."; exit 1
fi

python3 - <<'MTP_0_EOF'
import io
p = 'app/Filament/Pages/Distributors.php'
s = io.open(p, encoding='utf-8').read()

old = """        $conn = PlatformDistributorConnection::forCode($this->code);
        // persist current form first so we test what's on screen
        $conn->update($this->form->getState());
"""
assert s.count(old) == 1, s.count(old)

new = """        $conn = PlatformDistributorConnection::forCode($this->code);

        // MARKER-TEST-PACKS-CREDS — persist what's on screen, packed the same
        // way save() does. This used to be $conn->update($form->getState()),
        // which for BTI wrote `username` and `password` (not columns) and no
        // api_key at all — so the test ran against the PREVIOUSLY stored
        // credential rather than the one just typed.
        $state = $this->form->getState();
        $packed = app(DistributorRegistry::class)->packCredentials($this->code, $state);
        if ($packed !== null) {
            $conn->api_key = $packed;
        }
        $conn->region = $state['region'] ?? ($conn->region ?: 'us');
        if ($this->usesAuthStyle($this->code)) {
            $conn->auth_style = $state['auth_style'] ?? $conn->auth_style;
        }
        $conn->distributor_code = $this->code;
        $conn->save();
"""
s = s.replace(old, new)

io.open(p, 'w', encoding='utf-8').write(s)
print('test packs credentials ok')
MTP_0_EOF

echo
echo "master-test-packs-credentials applied."
