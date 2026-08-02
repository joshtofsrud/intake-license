#!/usr/bin/env bash
# apply-vendor-discount-and-distributor-link.sh
# MARKER-VENDOR-NET-COST — two columns on tenant_vendors, and the reason
# lowest_price has been picking the wrong vendor.
#
# ---------------------------------------------------------------- discount
# SpecialOrderService::autoAssignVendor()'s `lowest_price` rule sorts on
# `live_cost_cents ?? unit_cost_cents` — the distributor's list cost. A shop
# on a program with one vendor and not another gets the comparison exactly
# backwards. program_discount_pct is a flat percentage off that vendor's
# cost; the rule now sorts on the NET figure.
#
# Flat per vendor is deliberate for a first pass. Real programs often vary
# by brand tier, which a single number can't express — but per Josh, only
# some vendors offer one at all, typically single-brand companies where a
# flat rate is accurate.
#
# Freight is deliberately NOT part of the comparison. A vendor short of its
# free-freight threshold can cost more all-in, but that depends on the rest
# of the order, and shops can weigh their own freight against their own
# discounts. The placement board already shows a freight bar per vendor.
#
# ---------------------------------------------------------------- the link
# DistributorCatalogImportService::vendorFor() matches a vendor by NAME
# against the distributor code:
#
#     TenantVendor::firstOrCreate(['tenant_id' => $t, 'name' => $code])
#
# So the vendor is literally called "BTI". A shop that already had a vendor
# named "Bicycle Technologies International" — with the account number,
# contact and freight threshold on it — silently gets a SECOND vendor, and
# every imported item links to that one. Renaming the auto-created vendor
# breaks the next import, because firstOrCreate then makes a third.
#
# tenant_vendors.distributor_catalog_id already exists but points at
# platform_distributor_catalogs, which is the per-PRODUCT table (~24.7k BTI
# rows). Wrong grain — it can reference one product, never a distributor.
#
# distributor_code is the right link, and it's what the item-vendor pivot
# already carries. vendorFor() now matches on it first, so a vendor the shop
# has linked by hand wins and no duplicate is created. Name matching stays
# as a fallback and stamps the code when it hits, so existing installs heal
# on their next import.
#
# The unique index is (tenant_id, distributor_code). MySQL permits multiple
# NULLs, so ordinary vendors are unaffected while two vendors can never
# claim the same distributor.
#
# Additive only — safe under the release model.
set -e

python3 <<'PY'
import io, os

# ---------------------------------------------------------------- migration
MIG = 'database/migrations/2026_08_02_000100_vendor_discount_and_distributor_link.php'
assert not os.path.exists(MIG), 'migration already exists'
open(MIG, 'w', encoding='utf-8').write('''<?php

// MARKER-VENDOR-NET-COST — see apply-vendor-discount-and-distributor-link.sh

use Illuminate\\Database\\Migrations\\Migration;
use Illuminate\\Database\\Schema\\Blueprint;
use Illuminate\\Support\\Facades\\DB;
use Illuminate\\Support\\Facades\\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenant_vendors', function (Blueprint $t) {
            // Percentage off this vendor's cost. 5.25 means 5.25%. Null means
            // no program, which is different from 0 only in intent.
            $t->decimal('program_discount_pct', 5, 2)->nullable()->after('account_number');

            // Which distributor feed this vendor IS, when it is one. Matches
            // tenant_inventory_item_vendors.distributor_code.
            $t->string('distributor_code', 32)->nullable()->after('program_discount_pct');
        });

        // Heal existing installs: the importer named auto-created vendors
        // after the code, so an exact name match is a safe backfill.
        foreach (array_keys((array) config('distributors', [])) as $code) {
            DB::table('tenant_vendors')
                ->whereNull('distributor_code')
                ->whereRaw('LOWER(name) = ?', [strtolower($code)])
                ->update(['distributor_code' => strtolower($code)]);
        }

        // Added AFTER the backfill so a pre-existing duplicate surfaces as a
        // migration failure rather than silently keeping the wrong vendor.
        Schema::table('tenant_vendors', function (Blueprint $t) {
            $t->unique(['tenant_id', 'distributor_code'], 'tenant_vendors_tenant_distributor_unique');
        });
    }

    public function down(): void
    {
        Schema::table('tenant_vendors', function (Blueprint $t) {
            $t->dropUnique('tenant_vendors_tenant_distributor_unique');
            $t->dropColumn(['program_discount_pct', 'distributor_code']);
        });
    }
};
''')
print('created', MIG)

# ---------------------------------------------------------------- model
p = 'app/Models/Tenant/TenantVendor.php'
s = io.open(p, encoding='utf-8').read()

old = """        'free_freight_cents', // MARKER-SO-PLACEMENT"""
assert s.count(old) == 1, 'M1 fillable anchor'
s = s.replace(old, """        'free_freight_cents', // MARKER-SO-PLACEMENT
        'program_discount_pct', 'distributor_code', // MARKER-VENDOR-NET-COST""")

old = """        'free_freight_cents' => 'integer', // MARKER-SO-PLACEMENT"""
assert s.count(old) == 1, 'M2 casts anchor'
s = s.replace(old, """        'free_freight_cents' => 'integer', // MARKER-SO-PLACEMENT
        'program_discount_pct' => 'decimal:2', // MARKER-VENDOR-NET-COST""")

old = """    public function distributorCatalog(): BelongsTo"""
assert s.count(old) == 1, 'M3 relation anchor'
s = s.replace(old, """    /**
     * MARKER-VENDOR-NET-COST — apply this vendor's program discount.
     *
     * Null cost stays null: "we don't know what this costs" must not become
     * "it's free", which would make it win every lowest-price comparison.
     */
    public function netCostCents(?int $listCents): ?int
    {
        if ($listCents === null) {
            return null;
        }

        $pct = (float) ($this->program_discount_pct ?? 0);
        if ($pct <= 0) {
            return $listCents;
        }

        return (int) round($listCents * (1 - min($pct, 100) / 100));
    }

    public function distributorCatalog(): BelongsTo""")

io.open(p, 'w', encoding='utf-8').write(s)
print('patched', p)

# ---------------------------------------------------------------- controller
p = 'app/Http/Controllers/Tenant/VendorController.php'
s = io.open(p, encoding='utf-8').read()

old = """            'free_freight'      => ['nullable', 'numeric', 'min:0', 'max:100000'],"""
assert s.count(old) == 1, 'V1 validation anchor'
s = s.replace(old, """            'free_freight'      => ['nullable', 'numeric', 'min:0', 'max:100000'],
            // MARKER-VENDOR-NET-COST
            'program_discount_pct' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'distributor_code'     => ['nullable', 'string', 'max:32'],""")

old = """        unset($data['free_freight']);"""
assert s.count(old) == 1, 'V2 freight cast anchor'
s = s.replace(old, """        unset($data['free_freight']);

        // MARKER-VENDOR-NET-COST — blank means "no program", not zero.
        $pct = $request->input('program_discount_pct');
        $data['program_discount_pct'] = ($pct === null || $pct === '') ? null : (float) $pct;

        // Only accept a code the registry actually knows, so a typo can't
        // orphan the link from the importer.
        $code = strtolower(trim((string) $request->input('distributor_code')));
        $data['distributor_code'] = ($code !== '' && array_key_exists($code, (array) config('distributors', [])))
            ? $code
            : null;""")

io.open(p, 'w', encoding='utf-8').write(s)
print('patched', p)

# ---------------------------------------------------------------- importer
p = 'app/Services/Distributors/DistributorCatalogImportService.php'
s = io.open(p, encoding='utf-8').read()

old = """        return TenantVendor::firstOrCreate(
            ['tenant_id' => $tenantId, 'name' => $code],
            ['is_active' => true],
        );"""
assert s.count(old) == 1, 'I1 vendorFor anchor'
s = s.replace(old, """        // MARKER-VENDOR-NET-COST — prefer the explicit link.
        //
        // This used to match on NAME alone, so the vendor ended up literally
        // called "BTI" and a shop that already had "Bicycle Technologies
        // International" quietly got a second one, with every imported item
        // pointing at it. Matching on distributor_code first means a vendor
        // the shop linked by hand wins, whatever it's called.
        $linked = TenantVendor::where('tenant_id', $tenantId)
            ->where('distributor_code', strtolower($code))
            ->first();
        if ($linked) {
            return $linked;
        }

        // Fallback for installs that predate the link: match the old naming
        // convention and stamp the code, so this heals on first import.
        $byName = TenantVendor::where('tenant_id', $tenantId)
            ->whereRaw('LOWER(name) = ?', [strtolower($code)])
            ->first();
        if ($byName) {
            $byName->update(['distributor_code' => strtolower($code)]);
            return $byName;
        }

        return TenantVendor::create([
            'tenant_id'        => $tenantId,
            'name'             => $code,
            'distributor_code' => strtolower($code),
            'is_active'        => true,
        ]);""")

io.open(p, 'w', encoding='utf-8').write(s)
print('patched', p)

# ---------------------------------------------------------------- lowest_price
p = 'app/Services/Tenant/SpecialOrderService.php'
s = io.open(p, encoding='utf-8').read()

old = """        $vendorIds = \\App\\Models\\Tenant\\TenantVendor::where('tenant_id', $tenantId)
            ->where('is_active', true)->pluck('id');
        if ($vendorIds->isEmpty()) {
            return $none;
        }"""
assert s.count(old) == 1, 'S1 vendor ids anchor'
s = s.replace(old, """        // MARKER-VENDOR-NET-COST — keep the models, not just the ids: the
        // lowest-price rule needs each vendor's program discount to compare
        // net cost rather than list.
        $vendors = \\App\\Models\\Tenant\\TenantVendor::where('tenant_id', $tenantId)
            ->where('is_active', true)->get()->keyBy('id');
        $vendorIds = $vendors->keys();
        if ($vendorIds->isEmpty()) {
            return $none;
        }""")

old = """                $inStock = $priced->filter(fn ($r) => (int) ($r->live_avail ?? 0) > 0);
                $pool    = $inStock->isNotEmpty() ? $inStock : $priced;
                $pick    = $pool->sortBy(fn ($r) => $r->live_cost_cents ?? $r->unit_cost_cents)->first();"""
assert s.count(old) == 1, 'S2 sort anchor'
s = s.replace(old, """                $inStock = $priced->filter(fn ($r) => (int) ($r->live_avail ?? 0) > 0);
                $pool    = $inStock->isNotEmpty() ? $inStock : $priced;

                // MARKER-VENDOR-NET-COST — sort on what the shop actually
                // pays. Comparing list cost picked the wrong vendor whenever
                // one of them had a program and another didn't.
                $pick = $pool->sortBy(function ($r) use ($vendors) {
                    $list = $r->live_cost_cents ?? $r->unit_cost_cents;
                    $v    = $vendors->get($r->vendor_id);

                    return $v ? ($v->netCostCents($list) ?? $list) : $list;
                })->first();""")

io.open(p, 'w', encoding='utf-8').write(s)
print('patched', p)
PY

# ---------------------------------------------------------------- forms
python3 <<'PY'
import io

FIELDS = '''        {{-- MARKER-VENDOR-NET-COST --}}
        <div class="ia-form-group">
          <label class="ia-form-label">Program discount %</label>
          <input type="number" step="0.01" min="0" max="100" name="program_discount_pct" class="ia-input"
                 placeholder="e.g. 5" value="__PCT__">
          <div class="ia-form-hint">Flat percentage off this vendor's cost. Used when auto-assigning by lowest price, so the comparison reflects what you actually pay. Leave blank if there's no program.</div>
        </div>
        <div class="ia-form-group">
          <label class="ia-form-label">Catalog feed</label>
          <select name="distributor_code" class="ia-input">
            <option value="">Not a catalog distributor</option>
            @foreach(array_keys((array) config('distributors', [])) as $dcode)
              <option value="{{ $dcode }}" @selected(__SEL__ === $dcode)>{{ strtoupper($dcode) }}</option>
            @endforeach
          </select>
          <div class="ia-form-hint">Links this vendor to its catalog import, so imported items attach here instead of creating a duplicate vendor. Only one vendor per feed.</div>
        </div>
'''

for path, pct, sel, anchor in [
    ('resources/views/tenant/vendors/edit.blade.php',
     "{{ old('program_discount_pct', $vendor->program_discount_pct) }}",
     "old('distributor_code', $vendor->distributor_code)",
     """          <div class="ia-form-hint">Order total that earns free shipping. Leave blank if this vendor has none — the placement board only shows a freight bar when it is set.</div>
        </div>"""),
    ('resources/views/tenant/vendors/index.blade.php',
     "{{ old('program_discount_pct') }}",
     "old('distributor_code')",
     None),
]:
    s = io.open(path, encoding='utf-8').read()
    block = FIELDS.replace('__PCT__', pct).replace('__SEL__', sel)

    if anchor is None:
        # index.blade.php: anchor on its own freight input instead
        import re
        m = re.search(r'[ \t]*<input type="number" step="0\.01" min="0" name="free_freight".*?\n(?:.*?\n)?[ \t]*placeholder="e\.g\. 500\.00" value="\{\{ old\(\'free_freight\'\) \}\}">\n', s)
        assert m, 'F-index freight input not found'
        anchor = m.group(0)

    assert s.count(anchor) == 1, 'form anchor not unique in %s' % path
    s = s.replace(anchor, anchor + "\n" + block)
    io.open(path, 'w', encoding='utf-8').write(s)
    print('patched', path)
PY

echo
echo "--- blade sweep ---"
python3 - <<'PY'
import io, re
for f in ['resources/views/tenant/vendors/edit.blade.php', 'resources/views/tenant/vendors/index.blade.php']:
    s = re.sub(r'\{\{--.*?--\}\}', '', io.open(f, encoding='utf-8').read(), flags=re.S)
    print(f, 'glued:', len(re.findall(r'\w@(?:if|endif|foreach|endforeach|elseif|else|unless|php|endphp|selected)\b', s)))
    for a, b in [('@if','@endif'), ('@foreach','@endforeach')]:
        o = len(re.findall(r'\B'+a+r'\b', s)); c = len(re.findall(r'\B'+b+r'\b', s))
        print('   ', a, o, b, c, 'OK' if o == c else 'MISMATCH')
PY

echo "--- balance ---"
python3 - <<'PY'
import io
def bal(p):
    s = io.open(p, encoding='utf-8').read()
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
    return d, par
for f in ['app/Models/Tenant/TenantVendor.php', 'app/Http/Controllers/Tenant/VendorController.php',
          'app/Services/Distributors/DistributorCatalogImportService.php',
          'app/Services/Tenant/SpecialOrderService.php',
          'database/migrations/2026_08_02_000100_vendor_discount_and_distributor_link.php']:
    print(f, bal(f))
PY

echo
echo "apply-vendor-discount-and-distributor-link: OK"
