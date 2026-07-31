#!/bin/bash
# tenant-distributor-multi — one box per distributor, with a priority picker.
#
#   The tenant connection page was HLC only: DistributorController opened with
#   `private const CODE = 'HLC'` and the view said HLC throughout. Adding BTI
#   was never a config change.
#
#   Now the page lists every distributor the registry supports, so QBP appears
#   the day its adapter is registered with no UI work.
#
#   Credential shape varies by distributor, which is why this isn't just a
#   loop: HLC takes one API key, BTI takes a username and a password. Each
#   adapter declares its own fields; the form renders whatever it declares.
#   BTI's two fields are still stored as "username:password" in the api_key
#   slot — that keeps DistributorRegistry::make() and every existing caller
#   untouched — but a shop owner now sees two labelled boxes instead of being
#   asked to type a colon.
#
#   Priority is per tenant per distributor: which feed's data wins on a
#   product both carry. Lower number wins. It does NOT affect purchasing, and
#   it does NOT touch the platform catalog or title rules — it only decides
#   which source populates the tenant's own item records, so tenants still
#   never change platform config.
#
#   Nothing consumes priority yet. This patch is the surface: the field, the
#   picker, and the storage. The resolution at sync time is the next piece,
#   and is deliberately separate so the screen can be looked at first.
# MIGRATION REQUIRED. Server: optimize:clear
set -e
if grep -q "MARKER-DIST-MULTI" app/Http/Controllers/Tenant/DistributorController.php; then
  echo "tenant-distributor-multi already applied — aborting."; exit 1
fi

# ------------------------------------------------------------------ migration
cat > 'database/migrations/2026_07_31_000003_add_priority_to_distributor_subscriptions.php' <<'TDM_0_EOF'
<?php

// MARKER-DIST-MULTI — per-tenant data priority. Lower wins.

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenant_distributor_catalog_subscriptions', function (Blueprint $t) {
            // Which feed's title/attributes/description win on a product two
            // distributors both carry. Purchasing is a separate question and
            // is not decided here.
            $t->unsignedTinyInteger('data_priority')->default(50)->after('is_active');
        });
    }

    public function down(): void
    {
        Schema::table('tenant_distributor_catalog_subscriptions', function (Blueprint $t) {
            $t->dropColumn('data_priority');
        });
    }
};
TDM_0_EOF

# ------------------------------------------- registry: credential descriptors
python3 - <<'TDM_1_EOF'
import io
p = 'app/Services/Distributors/DistributorRegistry.php'
s = io.open(p, encoding='utf-8').read()

old = """    /** @return array<int,string> supported distributor codes */"""
assert s.count(old) == 1, s.count(old)
new = """    /**
     * MARKER-DIST-MULTI \u2014 what each distributor asks a shop for.
     *
     * HLC issues one API key. BTI issues a username and a password for HTTP
     * Basic. Both end up in the api_key credential slot (BTI's joined with a
     * colon) so make() keeps its single shape, but the shop sees the fields
     * its own distributor actually uses.
     *
     * @return array<int,array{name:string,label:string,type:string,hint:?string}>
     */
    public function credentialFields(string $code): array
    {
        return match (strtoupper($code)) {
            'BTI' => [
                ['name' => 'username', 'label' => 'BTI username', 'type' => 'text',
                 'hint' => 'The account number on your BTI Inventory Data Download page.'],
                ['name' => 'password', 'label' => 'BTI password', 'type' => 'password',
                 'hint' => 'The long code on the same page.'],
            ],
            default => [
                ['name' => 'api_key', 'label' => 'API key', 'type' => 'password',
                 'hint' => 'Issued by the distributor for your dealer account.'],
            ],
        };
    }

    /** Human label for a code, without building an adapter. */
    public function label(string $code): string
    {
        return match (strtoupper($code)) {
            'HLC' => 'HLC',
            'BTI' => 'BTI',
            'QBP' => 'QBP',
            default => strtoupper($code),
        };
    }

    /**
     * Collapse a submitted credential form into the stored shape. BTI's two
     * fields join with a colon because BtiClient splits on the first one.
     *
     * @param  array<string,string> $input
     */
    public function packCredentials(string $code, array $input): ?string
    {
        if (strtoupper($code) === 'BTI') {
            $u = trim($input['username'] ?? '');
            $p = trim($input['password'] ?? '');
            return ($u === '' || $p === '') ? null : $u . ':' . $p;
        }
        $k = trim($input['api_key'] ?? '');
        return $k === '' ? null : $k;
    }

    /** @return array<int,string> supported distributor codes */"""
io.open(p, 'w', encoding='utf-8').write(s.replace(old, new))
print('registry ok')
TDM_1_EOF

# ------------------------------------------------------------------ controller
python3 - <<'TDM_2_EOF'
import io
p = 'app/Http/Controllers/Tenant/DistributorController.php'
s = io.open(p, encoding='utf-8').read()

old = """    private const CODE = 'HLC';"""
assert s.count(old) == 1, s.count(old)
new = """    // MARKER-DIST-MULTI \u2014 was the whole controller's distributor. Kept as the
    // default for the routes that still assume one (import, attention), which
    // remain HLC-only until those screens are generalised too.
    private const CODE = 'HLC';"""
s = s.replace(old, new)

old = """    public function connection(): View
    {
        $this->guard();
        $tenant = tenant();
        $sub = $this->subscription();
        $creds = (array) ($sub->credentials_encrypted ?? []);

        return view('tenant.distributors.connection', ["""
assert s.count(old) == 1, s.count(old)
new = """    /**
     * MARKER-DIST-MULTI \u2014 one box per supported distributor.
     *
     * Reads DistributorRegistry::supported(), so a newly registered adapter
     * appears here with no change to this method or the view.
     */
    public function connection(): View
    {
        $this->guard();
        $tenant = tenant();
        $registry = app(\\App\\Services\\Distributors\\DistributorRegistry::class);

        $boxes = [];
        foreach ($registry->supported() as $code) {
            $sub = TenantDistributorCatalogSubscription::firstOrCreate(
                ['tenant_id' => $tenant->id, 'distributor_code' => $code],
                ['is_active' => true],
            );
            $creds = (array) ($sub->credentials_encrypted ?? []);

            $boxes[] = [
                'code'      => $code,
                'label'     => $registry->label($code),
                'sub'       => $sub,
                'fields'    => $registry->credentialFields($code),
                'hasKey'    => filled($creds['api_key'] ?? null),
                'maskedKey' => $this->mask($creds['api_key'] ?? null),
                'priority'  => (int) ($sub->data_priority ?? 50),
                'linked'    => TenantInventoryItemVendor::query()
                    ->where('distributor_code', $code)
                    ->whereNotNull('distributor_catalog_id')
                    ->whereHas('item', fn ($q) => $q->where('tenant_id', $tenant->id))
                    ->count(),
            ];
        }

        // Lowest number first, so the screen reads in the order it resolves.
        usort($boxes, fn ($a, $b) => $a['priority'] <=> $b['priority']);

        $legacy = $this->subscription();
        $legacyCreds = (array) ($legacy->credentials_encrypted ?? []);

        return view('tenant.distributors.connection', [
            'boxes'        => $boxes,
            'sub'          => $legacy,
            'hasKey'       => filled($legacyCreds['api_key'] ?? null),
            'maskedKey'    => $this->mask($legacyCreds['api_key'] ?? null),"""
s = s.replace(old, new)

old = """            'sub'          => $sub,
            'hasKey'       => filled($creds['api_key'] ?? null),
            'maskedKey'    => $this->mask($creds['api_key'] ?? null),
            'accountNo'    => $sub->account_number,"""
assert s.count(old) == 1, s.count(old)
new = """            'accountNo'    => $legacy->account_number,"""
s = s.replace(old, new)

old = """    public function saveKey(Request $request): RedirectResponse
    {
        $this->guard();
        $data = $request->validate([
            'api_key'        => ['nullable', 'string', 'max:255'],
            'account_number' => ['nullable', 'string', 'max:64'],
        ]);

        $sub = $this->subscription();
        $creds = (array) ($sub->credentials_encrypted ?? []);
        if (filled($data['api_key'] ?? null)) {
            $creds['api_key'] = trim($data['api_key']);
            $creds['region'] = $creds['region'] ?? 'us';
        }
        $sub->credentials_encrypted = $creds;
        $sub->account_number = $data['account_number'] ?? $sub->account_number;
        $sub->save();

        return back()->with('success', 'HLC key saved. Test it to confirm access.');
    }"""
assert s.count(old) == 1, s.count(old)
new = """    /**
     * MARKER-DIST-MULTI \u2014 saves one distributor's box.
     *
     * The credential fields differ per distributor, so the submitted values
     * go through the registry, which knows how to collapse them into the
     * single stored api_key (BTI joins username and password with a colon).
     * A blank credential means "leave what's stored alone", so a shop can
     * change its priority without re-typing a key it can't read back.
     */
    public function saveKey(Request $request): RedirectResponse
    {
        $this->guard();
        $data = $request->validate([
            'distributor_code' => ['required', 'string', 'max:32'],
            'api_key'          => ['nullable', 'string', 'max:255'],
            'username'         => ['nullable', 'string', 'max:128'],
            'password'         => ['nullable', 'string', 'max:255'],
            'account_number'   => ['nullable', 'string', 'max:64'],
            'data_priority'    => ['nullable', 'integer', 'min:1', 'max:99'],
        ]);

        $registry = app(\\App\\Services\\Distributors\\DistributorRegistry::class);
        $code = strtoupper($data['distributor_code']);
        abort_unless($registry->isSupported($code), 404);

        $sub = TenantDistributorCatalogSubscription::firstOrCreate(
            ['tenant_id' => tenant()->id, 'distributor_code' => $code],
            ['is_active' => true],
        );

        $creds = (array) ($sub->credentials_encrypted ?? []);
        $packed = $registry->packCredentials($code, $data);
        if ($packed !== null) {
            $creds['api_key'] = $packed;
            $creds['region'] = $creds['region'] ?? 'us';
        }

        $sub->credentials_encrypted = $creds;
        $sub->account_number = $data['account_number'] ?? $sub->account_number;
        if (isset($data['data_priority'])) {
            $sub->data_priority = (int) $data['data_priority'];
        }
        $sub->save();

        return back()->with('success', $registry->label($code) . ' saved. Test it to confirm access.');
    }"""
s = s.replace(old, new)

io.open(p, 'w', encoding='utf-8').write(s)
print('controller ok')
TDM_2_EOF

# ------------------------------------------------------------------ view
python3 - <<'TDM_3_EOF'
import io
p = 'resources/views/tenant/distributors/connection.blade.php'
s = io.open(p, encoding='utf-8').read()

start = s.index('  <h1 style="font-size:20px;font-weight:600;margin-bottom:14px">HLC Catalog</h1>')
end = s.index('</x-tenant-layout>') if '</x-tenant-layout>' in s else s.rindex('@endsection')

block = """  {{-- MARKER-DIST-MULTI — one box per supported distributor. --}}
  <h1 style="font-size:20px;font-weight:600;margin-bottom:6px">Distributor catalogs</h1>
  <p class="dc-sub">Connect each distributor you buy from. Browsing and importing works without a key —
  your own key unlocks <b>your cost</b> and <b>live availability</b>, per account, never shared between shops.</p>

  <div class="dc-note" style="margin-bottom:18px">
    <b>Priority</b> decides which distributor's product information wins when two of them carry the
    same item — the name, description and specs on your items. Lower number wins. It doesn't change
    who you buy from.
  </div>

  @foreach ($boxes as $i => $b)
    <div class="dc-card" style="margin-bottom:16px">
      <div style="display:flex;align-items:baseline;justify-content:space-between;gap:12px;flex-wrap:wrap">
        <h2 class="dc-h" style="margin:0">{{ $b['label'] }}</h2>
        <div style="font-size:12px;color:var(--ia-text-dim)">
          @if ($b['hasKey'])
            <span style="color:var(--ia-ok,#8FD14F)">connected</span> ·
          @endif
          {{ number_format($b['linked']) }} linked item{{ $b['linked'] === 1 ? '' : 's' }}
          @if ($i === 0 && $b['hasKey'])
            · <b>data source for shared items</b>
          @endif
        </div>
      </div>

      <form method="POST" action="{{ route('tenant.distributors.connection.key') }}" style="margin-top:12px">
        @csrf
        <input type="hidden" name="distributor_code" value="{{ $b['code'] }}">

        <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:12px">
          @foreach ($b['fields'] as $f)
            <div>
              <label class="dc-label">{{ $f['label'] }}</label>
              <input class="dc-input" type="{{ $f['type'] }}" name="{{ $f['name'] }}"
                     autocomplete="off"
                     placeholder="{{ $b['hasKey'] ? $b['maskedKey'] : '' }}">
              @if (! empty($f['hint']))
                <div style="font-size:11px;color:var(--ia-text-dim);margin-top:4px">{{ $f['hint'] }}</div>
              @endif
            </div>
          @endforeach

          <div>
            <label class="dc-label">Priority</label>
            <select class="dc-input" name="data_priority">
              @foreach ([1,2,3,4,5,10,20,50] as $p)
                <option value="{{ $p }}" @selected($b['priority'] === $p)>{{ $p }}</option>
              @endforeach
            </select>
            <div style="font-size:11px;color:var(--ia-text-dim);margin-top:4px">Lower wins.</div>
          </div>
        </div>

        @if ($b['hasKey'])
          <div style="font-size:11px;color:var(--ia-text-dim);margin-top:8px">
            Leave the credential blank to keep the saved one and change only the priority.
          </div>
        @endif

        <div style="margin-top:12px;display:flex;gap:8px">
          <button class="ia-btn ia-btn--primary">Save</button>
        </div>
      </form>
    </div>
  @endforeach

"""

s = s[:start] + block + s[end:]
io.open(p, 'w', encoding='utf-8').write(s)
print('view ok')
TDM_3_EOF

php -l app/Http/Controllers/Tenant/DistributorController.php
php -l app/Services/Distributors/DistributorRegistry.php

echo
echo "tenant-distributor-multi applied."
