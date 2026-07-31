#!/bin/bash
# distributor-box-actions — restore Test and Refresh, per distributor.
#
#   Two fixes.
#
#   1. REGRESSION I CAUSED. apply-tenant-distributor-multi replaced the page
#      body from the HLC heading to the end of the section, which took the
#      Test connection and Refresh sync buttons with it. The page gained a
#      second distributor and lost the ability to verify either. Both are
#      back, inside each box, acting on that box's distributor.
#
#      testConnection and refreshSync were HLC-only too (self::CODE, and
#      "Enter your HLC key first"). They now take a distributor_code and name
#      whichever distributor they're talking to.
#
#   2. WHY THE ARROWS COULD MOVE THE WRONG BOX. renumber() and movePriority()
#      walked EVERY subscription row for the tenant, while the screen only
#      lists codes the registry supports. A leftover subscription for an
#      unsupported code — QBP has been created and abandoned during this
#      work — sits in the ordered list, holds a position, and shifts every
#      index after it. The view's $i and the controller's index then disagree
#      and an arrow moves a different distributor than the one clicked.
#      Both now filter to supported codes, so the list the controller
#      reorders is exactly the list on screen.
# NO MIGRATION. Server: optimize:clear
set -e
if grep -q "MARKER-BOX-ACTIONS" app/Http/Controllers/Tenant/DistributorController.php; then
  echo "distributor-box-actions already applied — aborting."; exit 1
fi

# ------------------------------------------------------------------ controller
python3 - <<'DBA_0_EOF'
import io
p = 'app/Http/Controllers/Tenant/DistributorController.php'
s = io.open(p, encoding='utf-8').read()

# --- scope the ordered list to what's on screen --------------------------
old = """    /** The tenant's subscriptions in priority order, ties broken by code. */
    private function orderedSubs()
    {
        return TenantDistributorCatalogSubscription::where('tenant_id', tenant()->id)
            ->orderBy('data_priority')
            ->orderBy('distributor_code')
            ->get();
    }"""
assert s.count(old) == 1, s.count(old)
new = """    /**
     * The tenant's subscriptions in priority order, ties broken by code.
     *
     * MARKER-BOX-ACTIONS \u2014 limited to codes the registry supports, which is
     * exactly what the screen lists. A leftover subscription for a code with
     * no adapter (QBP was created and abandoned mid-build) would otherwise
     * occupy a position here, shift every index after it, and make an arrow
     * move a different distributor than the one that was clicked.
     */
    private function orderedSubs()
    {
        $codes = app(\\App\\Services\\Distributors\\DistributorRegistry::class)->supported();

        return TenantDistributorCatalogSubscription::where('tenant_id', tenant()->id)
            ->whereIn('distributor_code', $codes)
            ->orderBy('data_priority')
            ->orderBy('distributor_code')
            ->get();
    }

    /**
     * MARKER-BOX-ACTIONS \u2014 the subscription for one distributor, validated
     * against the registry so a bad code can't create a junk row.
     */
    private function subFor(string $code): TenantDistributorCatalogSubscription
    {
        $registry = app(\\App\\Services\\Distributors\\DistributorRegistry::class);
        $code = strtoupper($code);
        abort_unless($registry->isSupported($code), 404);

        return TenantDistributorCatalogSubscription::firstOrCreate(
            ['tenant_id' => tenant()->id, 'distributor_code' => $code],
            ['is_active' => true],
        );
    }"""
s = s.replace(old, new)

# --- testConnection per distributor --------------------------------------
old = """    public function testConnection(): RedirectResponse
    {
        $this->guard();
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
assert s.count(old) == 1, s.count(old)
new = """    // MARKER-BOX-ACTIONS \u2014 was HLC-only, in the code it built and in every
    // message it produced.
    public function testConnection(Request $request): RedirectResponse
    {
        $this->guard();
        $registry = app(DistributorRegistry::class);
        $code = strtoupper((string) $request->input('distributor_code', self::CODE));
        $label = $registry->label($code);

        $sub = $this->subFor($code);
        $creds = (array) ($sub->credentials_encrypted ?? []);
        if (blank($creds['api_key'] ?? null)) {
            return back()->with('error', 'Enter your ' . $label . ' credentials first.');
        }

        try {
            $adapter = $registry->make($code, [
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

# --- refreshSync per distributor -----------------------------------------
old = """    public function refreshSync(): RedirectResponse
    {
        $this->guard();
        $sub = $this->subscription();
        $creds = (array) ($sub->credentials_encrypted ?? []);
        if (blank($creds['api_key'] ?? null)) {
            return back()->with('error', 'Connect your HLC key before refreshing.');
        }"""
assert s.count(old) == 1, s.count(old)
new = """    // MARKER-BOX-ACTIONS \u2014 the job itself syncs the whole tenant, so the
    // distributor here only decides which credentials are checked first and
    // what the message says. Naming that rather than implying a per-vendor
    // refresh that doesn't exist.
    public function refreshSync(Request $request): RedirectResponse
    {
        $this->guard();
        $registry = app(DistributorRegistry::class);
        $code = strtoupper((string) $request->input('distributor_code', self::CODE));
        $label = $registry->label($code);

        $sub = $this->subFor($code);
        $creds = (array) ($sub->credentials_encrypted ?? []);
        if (blank($creds['api_key'] ?? null)) {
            return back()->with('error', 'Connect ' . $label . ' before refreshing.');
        }"""
s = s.replace(old, new)

io.open(p, 'w', encoding='utf-8').write(s)
print('controller ok')
DBA_0_EOF

# ------------------------------------------------------------------ view
python3 - <<'DBA_1_EOF'
import io
p = 'resources/views/tenant/distributors/connection.blade.php'
s = io.open(p, encoding='utf-8').read()

old = """        <div style="margin-top:12px;display:flex;gap:8px">
          <button class="ia-btn ia-btn--primary">Save</button>
        </div>
      </form>"""
assert s.count(old) == 1, s.count(old)
new = """        <div style="margin-top:12px;display:flex;gap:8px">
          <button class="ia-btn ia-btn--primary">Save</button>
        </div>
      </form>

      {{-- MARKER-BOX-ACTIONS — Test and Refresh, restored per distributor.
           Separate forms from the credential save so neither can be
           submitted by the other. --}}
      @if ($b['hasKey'])
        <div style="display:flex;gap:8px;margin-top:10px;flex-wrap:wrap;align-items:center">
          <form method="POST" action="{{ route('tenant.distributors.connection.test') }}" style="margin:0">
            @csrf
            <input type="hidden" name="distributor_code" value="{{ $b['code'] }}">
            <button class="ia-btn ia-btn--ghost">Test connection</button>
          </form>

          <form method="POST" action="{{ route('tenant.distributors.connection.refresh') }}" style="margin:0">
            @csrf
            <input type="hidden" name="distributor_code" value="{{ $b['code'] }}">
            <button class="ia-btn ia-btn--ghost">Refresh cost &amp; availability</button>
          </form>

          @if ($b['sub']->last_sync_status)
            <span style="font-size:11.5px;color:var(--ia-text-dim)">
              last check: {{ $b['sub']->last_sync_status }}
              @if ($b['sub']->last_sync_at)
                · {{ $b['sub']->last_sync_at->diffForHumans() }}
              @endif
            </span>
          @endif
        </div>
      @endif"""
io.open(p, 'w', encoding='utf-8').write(s.replace(old, new))
print('view ok')
DBA_1_EOF

php -l app/Http/Controllers/Tenant/DistributorController.php

echo
echo "distributor-box-actions applied."
