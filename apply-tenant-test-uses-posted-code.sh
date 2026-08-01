#!/bin/bash
# tenant-test-uses-posted-code — Test connection tests the box you clicked.
#
#   Testing the BTI box reported "Connected to HLC." The view has been
#   posting distributor_code all along; testConnection() and refreshSync()
#   simply never read it — both were still the original single-distributor
#   methods using self::CODE, so every box tested HLC and said so.
#
#   Anchored on the exact original method bodies rather than on any earlier
#   patch of mine, because several of those didn't land: this file has
#   MARKER-DIST-MULTI, MARKER-PRIORITY-ORDER and MARKER-IMPORTER-PER-CODE,
#   but not the priority-contiguous or box-actions edits I assumed were
#   there. Everything after that failure was patching against a file that
#   didn't exist.
#
#   No route change is involved. An earlier attempt moved the distributor
#   into the URL to stop a missing field defaulting to HLC — but the field
#   was never missing, so that was solving the wrong problem.
#
#   The one thing kept from that idea: NO silent default. A request without a
#   distributor now errors instead of quietly acting on HLC, because a
#   default that is itself a valid distributor turns a missing value into a
#   confident wrong answer — which is precisely how this hid.
# NO MIGRATION. Server: optimize:clear
set -e
if grep -q "MARKER-POSTED-CODE" app/Http/Controllers/Tenant/DistributorController.php; then
  echo "tenant-test-uses-posted-code already applied — aborting."; exit 1
fi

python3 - <<'TPC_0_EOF'
import io
p = 'app/Http/Controllers/Tenant/DistributorController.php'
s = io.open(p, encoding='utf-8').read()

# ---------------------------------------------------------------- helper
old = """    public function testConnection(): RedirectResponse"""
assert s.count(old) == 1, ('testConnection anchor', s.count(old))
new = """    /**
     * MARKER-POSTED-CODE — the distributor the request is about.
     *
     * No default. self::CODE as a fallback meant a request that didn't carry
     * a distributor quietly acted on HLC and reported success for it, which
     * is exactly how "Test connection on the BTI box says HLC connected"
     * stayed hidden.
     */
    private function requestedSub(Request $request): array
    {
        $registry = app(DistributorRegistry::class);
        $code = strtoupper(trim((string) $request->input('distributor_code', '')));

        abort_unless($code !== '' && $registry->isSupported($code), 404);

        $sub = TenantDistributorCatalogSubscription::firstOrCreate(
            ['tenant_id' => tenant()->id, 'distributor_code' => $code],
            ['is_active' => true],
        );

        return [$code, $registry->label($code), $sub];
    }

    public function testConnection(Request $request): RedirectResponse"""
s = s.replace(old, new)

# ---------------------------------------------------------------- test
old = """        $this->guard();
        $sub = $this->subscription();
        $creds = (array) ($sub->credentials_encrypted ?? []);
        if (blank($creds['api_key'] ?? null)) {
            return back()->with('error', 'Enter your HLC key first.');
        }

        try {
            $adapter = app(DistributorRegistry::class)->make(self::CODE, [
                'api_key' => $creds['api_key'],
                'region'  => $creds['region'] ?? 'us',
            ]);
            $res = $adapter->testConnection();
            $ok = (bool) ($res['ok'] ?? false);

            $sub->last_sync_status = $ok ? 'connected' : 'auth_failed';
            $sub->save();

            return back()->with($ok ? 'success' : 'error',
                $ok ? 'Connected to HLC.' : ('HLC rejected the key (HTTP ' . ($res['status'] ?? '?') . ').'));
        } catch (\\Throwable $e) {
            return back()->with('error', 'Could not reach HLC: ' . $e->getMessage());
        }
    }"""
assert s.count(old) == 1, ('test body', s.count(old))
new = """        $this->guard();
        // MARKER-POSTED-CODE — was self::CODE, so every box tested HLC.
        [$code, $label, $sub] = $this->requestedSub($request);

        $creds = (array) ($sub->credentials_encrypted ?? []);
        if (blank($creds['api_key'] ?? null)) {
            return back()->with('error', 'Enter your ' . $label . ' credentials first.');
        }

        try {
            $adapter = app(DistributorRegistry::class)->make($code, [
                'api_key' => $creds['api_key'],
                'region'  => $creds['region'] ?? 'us',
            ]);
            $res = $adapter->testConnection();
            $ok = (bool) ($res['ok'] ?? false);

            $sub->last_sync_status = $ok ? 'connected' : 'auth_failed';
            $sub->save();

            return back()->with($ok ? 'success' : 'error',
                $ok
                    ? 'Connected to ' . $label . '.'
                    : ($label . ' rejected the credentials (HTTP ' . ($res['status'] ?? '?') . ').'));
        } catch (\\Throwable $e) {
            return back()->with('error', 'Could not reach ' . $label . ': ' . $e->getMessage());
        }
    }"""
s = s.replace(old, new)

# ---------------------------------------------------------------- refresh
old = """    public function refreshSync(): RedirectResponse
    {
        $this->guard();
        $sub = $this->subscription();
        $creds = (array) ($sub->credentials_encrypted ?? []);
        if (blank($creds['api_key'] ?? null)) {
            return back()->with('error', 'Connect your HLC key before refreshing.');
        }"""
assert s.count(old) == 1, ('refresh body', s.count(old))
new = """    // MARKER-POSTED-CODE — the job syncs every active subscription for the
    // tenant, so the distributor here decides whose credentials are checked
    // first and what the message names, not which feed runs.
    public function refreshSync(Request $request): RedirectResponse
    {
        $this->guard();
        [$code, $label, $sub] = $this->requestedSub($request);

        $creds = (array) ($sub->credentials_encrypted ?? []);
        if (blank($creds['api_key'] ?? null)) {
            return back()->with('error', 'Connect ' . $label . ' before refreshing.');
        }"""
s = s.replace(old, new)

io.open(p, 'w', encoding='utf-8').write(s)
print('controller ok')
TPC_0_EOF

echo
echo "tenant-test-uses-posted-code applied."
