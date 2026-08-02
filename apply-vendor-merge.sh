#!/usr/bin/env bash
# apply-vendor-merge.sh
# MARKER-VENDOR-MERGE — linking a distributor to an existing vendor now
# absorbs the auto-created one instead of splitting the catalog.
#
# Without this, picking "Bicycle Technologies" for BTI moved the LINK but
# left every item row on the old auto-created "BTI" vendor. Next import
# attaches new items to the chosen vendor, old ones stay put, and the
# freight minimum and program discount only apply to half the catalog.
#
# ORDER IS NON-NEGOTIABLE: move everything, delete last.
# tenant_inventory_item_vendors is cascadeOnDelete — deleting a vendor
# does not orphan its item rows, it DESTROYS them, along with every vendor
# SKU, live cost and availability figure on them. Special orders, receive
# shipments and default-vendor pointers are nullOnDelete, so a premature
# delete silently erases which vendor a past order went to.
#
# SNAPSHOTS ARE SAFE and deliberately untouched: receive shipments carry
# their own distributor_name / distributor_code strings written at receive
# time, and the receiving screens display THOSE, not the joined vendor. A
# merge cannot rewrite receiving history. It only fills a BLANK
# distributor_code, never overwrites a populated one.
#
# THE CHOSEN VENDOR WINS. Its name, contact details, account number,
# freight minimum and discount survive. Fields fill from the absorbed
# vendor only where the survivor's are empty, so nothing the shop typed is
# overwritten. Two visible consequences, both correct but worth expecting:
# the placement board relabels from "BTI" to the real name, and past
# special orders show "via <new name>" since that reads the live relation.
#
# Confirmation first — the merge is irreversible, so saveKey redirects to a
# screen showing the counts and the surviving name before anything commits.
set -e

mkdir -p app/Services/Tenant
cat <<'EOF' > app/Services/Tenant/VendorMergeService.php
<?php

namespace App\Services\Tenant;

// MARKER-VENDOR-MERGE — absorb one vendor into another, wholesale.

use App\Models\Tenant\TenantInventoryItem;
use App\Models\Tenant\TenantInventoryItemVendor;
use App\Models\Tenant\TenantInventoryReceiveShipment;
use App\Models\Tenant\TenantSpecialOrder;
use App\Models\Tenant\TenantVendor;
use Illuminate\Support\Facades\DB;

class VendorMergeService
{
    /** Vendor columns worth inheriting when the survivor's is empty. */
    private const FILLABLE_BLANKS = [
        'contact_email', 'contact_phone', 'website', 'account_number',
        'notes', 'free_freight_cents', 'program_discount_pct',
    ];

    /** Pivot columns worth inheriting on a colliding row. */
    private const PIVOT_BLANKS = [
        'distributor_code', 'distributor_catalog_id', 'vendor_sku',
        'unit_cost_cents', 'live_cost_cents', 'live_avail',
        'live_checked_at', 'lead_time_days', 'last_ordered_at',
    ];

    /**
     * Which vendor is the importer currently using for this distributor?
     *
     * Defined by the item rows, not by the code stamp — that catches an
     * auto-created vendor whose distributor_code was cleared or never set.
     */
    public function currentSourceFor(string $tenantId, string $code, string $excludeVendorId): ?TenantVendor
    {
        $vendorIds = TenantVendor::where('tenant_id', $tenantId)
            ->where('id', '!=', $excludeVendorId)->pluck('id');

        if ($vendorIds->isEmpty()) {
            return null;
        }

        $id = TenantInventoryItemVendor::whereIn('vendor_id', $vendorIds)
            ->where('distributor_code', strtoupper($code))
            ->value('vendor_id');

        if (! $id) {
            // No items yet — fall back to the code stamp, then the old
            // naming convention the importer used.
            $id = TenantVendor::where('tenant_id', $tenantId)
                ->where('id', '!=', $excludeVendorId)
                ->where(fn ($q) => $q->where('distributor_code', strtolower($code))
                    ->orWhereRaw('LOWER(name) = ?', [strtolower($code)]))
                ->value('id');
        }

        return $id ? TenantVendor::find($id) : null;
    }

    /** Counts for the confirmation screen. Touches nothing. */
    public function preview(TenantVendor $source, TenantVendor $target): array
    {
        $sourceItemIds = TenantInventoryItemVendor::where('vendor_id', $source->id)
            ->pluck('inventory_item_id');

        $collides = TenantInventoryItemVendor::where('vendor_id', $target->id)
            ->whereIn('inventory_item_id', $sourceItemIds)->count();

        return [
            'source_name'    => $source->name,
            'target_name'    => $target->name,
            'items'          => $sourceItemIds->count(),
            'items_collide'  => $collides,
            'special_orders' => TenantSpecialOrder::where('vendor_id', $source->id)->count(),
            'shipments'      => TenantInventoryReceiveShipment::where('vendor_id', $source->id)->count(),
            'default_for'    => TenantInventoryItem::where('default_vendor_id', $source->id)->count(),
            'inherits'       => collect(self::FILLABLE_BLANKS)
                ->filter(fn ($f) => blank($target->{$f}) && filled($source->{$f}))
                ->values()->all(),
        ];
    }

    /**
     * Absorb $source into $target. One transaction: any failure and both
     * vendors are left exactly as they were.
     */
    public function merge(TenantVendor $source, TenantVendor $target, string $code): array
    {
        $result = $this->preview($source, $target);

        DB::transaction(function () use ($source, $target, $code) {
            // 1. Item rows. The unique index on (inventory_item_id,
            //    vendor_id) blocks a blind move, so a colliding row donates
            //    its blanks to the survivor and is dropped.
            $targetByItem = TenantInventoryItemVendor::where('vendor_id', $target->id)
                ->get()->keyBy('inventory_item_id');

            TenantInventoryItemVendor::where('vendor_id', $source->id)
                ->get()
                ->each(function (TenantInventoryItemVendor $row) use ($target, $targetByItem) {
                    $existing = $targetByItem->get($row->inventory_item_id);

                    if (! $existing) {
                        $row->update(['vendor_id' => $target->id]);
                        return;
                    }

                    $fill = [];
                    foreach (self::PIVOT_BLANKS as $f) {
                        if (blank($existing->{$f}) && filled($row->{$f})) {
                            $fill[$f] = $row->{$f};
                        }
                    }
                    // Preferred is sticky: if either row was preferred, keep it.
                    if ($row->is_preferred && ! $existing->is_preferred) {
                        $fill['is_preferred'] = true;
                    }
                    if ($fill) {
                        $existing->update($fill);
                    }

                    $row->delete();
                });

            // 2. History and pointers. No unique constraints, plain repoint.
            TenantSpecialOrder::where('vendor_id', $source->id)
                ->update(['vendor_id' => $target->id]);

            TenantInventoryReceiveShipment::where('vendor_id', $source->id)
                ->update(['vendor_id' => $target->id]);

            // Fill a BLANK distributor_code only — the snapshot on a receipt
            // is what the receiving screens show, and it is not ours to rewrite.
            TenantInventoryReceiveShipment::where('vendor_id', $target->id)
                ->whereNull('distributor_code')
                ->update(['distributor_code' => strtoupper($code)]);

            TenantInventoryItem::where('default_vendor_id', $source->id)
                ->update(['default_vendor_id' => $target->id]);

            // 3. Inherit blanks. Never overwrite something the shop typed.
            $fill = [];
            foreach (self::FILLABLE_BLANKS as $f) {
                if (blank($target->{$f}) && filled($source->{$f})) {
                    $fill[$f] = $source->{$f};
                }
            }
            $fill['distributor_code'] = strtolower($code);
            $target->update($fill);

            // 4. Nothing references the source now, so the cascade on
            //    tenant_inventory_item_vendors has nothing left to take.
            $source->delete();
        });

        return $result;
    }
}
EOF
echo "created app/Services/Tenant/VendorMergeService.php"

python3 <<'PY'
import io

# ---------------------------------------------------------------- controller
p = 'app/Http/Controllers/Tenant/DistributorController.php'
s = io.open(p, encoding='utf-8').read()

old = """        // MARKER-DIST-VENDOR-PROMPT — bind the distributor to a vendor the shop
        // already has, so the importer stops inventing a duplicate.
        $vendorId = trim((string) ($data['vendor_id'] ?? ''));
        if ($vendorId !== '') {
            $target = \\App\\Models\\Tenant\\TenantVendor::where('tenant_id', tenant()->id)
                ->where('id', $vendorId)->first();

            if ($target) {"""
assert s.count(old) == 1, 'C1 vendor link block anchor'
s = s.replace(old, """        // MARKER-VENDOR-MERGE — if another vendor is already carrying this
        // distributor's items, linking without absorbing it splits the
        // catalog. Send the shop to a confirmation screen instead.
        $vendorId = trim((string) ($data['vendor_id'] ?? ''));
        if ($vendorId !== '') {
            $target = \\App\\Models\\Tenant\\TenantVendor::where('tenant_id', tenant()->id)
                ->where('id', $vendorId)->first();

            if ($target) {
                $merge  = app(\\App\\Services\\Tenant\\VendorMergeService::class);
                $source = $merge->currentSourceFor(tenant()->id, $code, $target->id);

                if ($source) {
                    $sub->save();

                    return redirect()->route('tenant.distributors.vendor_merge', [
                        'code' => $code, 'source' => $source->id, 'target' => $target->id,
                    ]);
                }
            }

            if ($target) {""")

old = """    public function movePriority(Request $request): RedirectResponse"""
assert s.count(old) == 1, 'C2 method insert anchor'
s = s.replace(old, """    /**
     * MARKER-VENDOR-MERGE — show what absorbing the old vendor will do.
     *
     * The merge is irreversible and deletes a vendor, so the counts and the
     * surviving name are the sanity check that the right target was picked.
     */
    public function vendorMerge(Request $request): View
    {
        $this->guard();

        $code   = strtoupper((string) $request->query('code'));
        $source = \\App\\Models\\Tenant\\TenantVendor::where('tenant_id', tenant()->id)
            ->findOrFail($request->query('source'));
        $target = \\App\\Models\\Tenant\\TenantVendor::where('tenant_id', tenant()->id)
            ->findOrFail($request->query('target'));

        return view('tenant.distributors.vendor-merge', [
            'code'    => $code,
            'source'  => $source,
            'target'  => $target,
            'preview' => app(\\App\\Services\\Tenant\\VendorMergeService::class)->preview($source, $target),
        ]);
    }

    public function vendorMergeRun(Request $request): RedirectResponse
    {
        $this->guard();

        $code   = strtoupper((string) $request->input('code'));
        $source = \\App\\Models\\Tenant\\TenantVendor::where('tenant_id', tenant()->id)
            ->findOrFail($request->input('source'));
        $target = \\App\\Models\\Tenant\\TenantVendor::where('tenant_id', tenant()->id)
            ->findOrFail($request->input('target'));

        $res = app(\\App\\Services\\Tenant\\VendorMergeService::class)->merge($source, $target, $code);

        return redirect()->route('tenant.distributors.connection')->with(
            'status',
            "Merged {$res['source_name']} into {$res['target_name']} — {$res['items']} items, "
            . "{$res['special_orders']} special orders and {$res['shipments']} receipts moved."
        );
    }

    public function movePriority(Request $request): RedirectResponse""")

io.open(p, 'w', encoding='utf-8').write(s)
print('patched', p)

# ---------------------------------------------------------------- routes
p = 'routes/web.php'
s = io.open(p, encoding='utf-8').read()

old = """            Route::prefix('distributors')->name('distributors.')->group(function () {"""
assert s.count(old) == 1, 'R1 distributor group anchor'
s = s.replace(old, """            Route::prefix('distributors')->name('distributors.')->group(function () {
                // MARKER-VENDOR-MERGE — confirm before absorbing a vendor.
                Route::get('/vendor-merge',  [TenantControllers\\DistributorController::class, 'vendorMerge'])->name('vendor_merge');
                Route::post('/vendor-merge', [TenantControllers\\DistributorController::class, 'vendorMergeRun'])->name('vendor_merge.run');""")

io.open(p, 'w', encoding='utf-8').write(s)
print('patched', p)
PY

cat <<'EOF' > resources/views/tenant/distributors/vendor-merge.blade.php
@extends('layouts.tenant.app')
@php $pageTitle = 'Link vendor'; @endphp

@section('content')

<div class="ia-page-head">
  <div class="ia-page-head-left">
    <h1 class="ia-page-title">Link {{ strtoupper($code) }} to {{ $target->name }}</h1>
    <p class="ia-page-subtitle">{{ $source->name }} will be absorbed and deleted.</p>
  </div>
</div>

<div style="background:var(--ia-surface);border:.5px solid var(--ia-border);border-radius:var(--ia-r-lg);padding:22px;max-width:620px">

  <p style="font-size:13.5px;line-height:1.7;margin-bottom:16px">
    <strong>{{ $target->name }}</strong> keeps its name, contact details, account
    number, free-freight minimum and program discount. Everything below moves
    onto it from <strong>{{ $source->name }}</strong>.
  </p>

  <div style="border:.5px solid var(--ia-border);border-radius:var(--ia-r-md);overflow:hidden;margin-bottom:16px">
    @foreach ([
      ['Catalog items',   $preview['items']],
      ['Special orders',  $preview['special_orders']],
      ['Receipts',        $preview['shipments']],
      ['Default vendor on', $preview['default_for']],
    ] as $row)
      <div style="display:flex;justify-content:space-between;padding:10px 14px;border-bottom:.5px solid var(--ia-border);font-size:13px">
        <span>{{ $row[0] }}</span>
        <span style="font-variant-numeric:tabular-nums">{{ number_format($row[1]) }}</span>
      </div>
    @endforeach
  </div>

  @if ($preview['items_collide'] > 0)
    <p style="font-size:12px;color:var(--ia-text-dim);line-height:1.6;margin-bottom:14px">
      {{ number_format($preview['items_collide']) }} of those items already list
      {{ $target->name }} as a source. Those rows are kept, filling in anything
      they were missing from the {{ $source->name }} row.
    </p>
  @endif

  @if (count($preview['inherits']))
    <p style="font-size:12px;color:var(--ia-text-dim);line-height:1.6;margin-bottom:14px">
      {{ $target->name }} will inherit these blank fields from {{ $source->name }}:
      {{ implode(', ', str_replace('_', ' ', $preview['inherits'])) }}.
    </p>
  @endif

  <p style="font-size:12px;color:var(--ia-text-dim);line-height:1.6;margin-bottom:18px">
    Receiving history is unaffected — each receipt keeps the distributor name
    recorded when it was received. This can't be undone.
  </p>

  <form method="POST" action="{{ route('tenant.distributors.vendor_merge.run') }}"
        style="display:flex;gap:10px;align-items:center;flex-wrap:wrap">
    @csrf
    <input type="hidden" name="code" value="{{ $code }}">
    <input type="hidden" name="source" value="{{ $source->id }}">
    <input type="hidden" name="target" value="{{ $target->id }}">
    <button class="ia-btn ia-btn--primary" type="submit">
      Merge into {{ $target->name }}
    </button>
    <a class="ia-btn" href="{{ route('tenant.distributors.connection') }}">Cancel</a>
  </form>
</div>

@endsection
EOF
echo "created resources/views/tenant/distributors/vendor-merge.blade.php"

echo
echo "--- blade sweep ---"
python3 - <<'PY'
import io, re
for f in ['resources/views/tenant/distributors/vendor-merge.blade.php',
          'resources/views/tenant/distributors/connection.blade.php']:
    s = re.sub(r'\{\{--.*?--\}\}', '', io.open(f, encoding='utf-8').read(), flags=re.S)
    print(f, 'glued:', len(re.findall(r'\w@(?:if|endif|foreach|endforeach|elseif|else|unless|php|endphp)\b', s)))
    for a, b in [('@if','@endif'), ('@foreach','@endforeach'), ('@section','@endsection')]:
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
for f in ['app/Services/Tenant/VendorMergeService.php',
          'app/Http/Controllers/Tenant/DistributorController.php',
          'routes/web.php']:
    print(f, bal(f))
PY

echo
echo "apply-vendor-merge: OK"
