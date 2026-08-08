#!/bin/bash
# apply-appointment-create-asset.sh
#
# Adds asset (bike / item / whatever the tenant calls it) select-or-create to
# the STAFF create-appointment modal — the one gap vs. the public booking flow
# and the work-order detail screen.
#
# Behavior (only when the tenant has asset tracking on = multi_asset_enabled):
#   - A new section after Customer, labelled from asset_label_singular.
#   - Pick one of the selected customer's existing assets, or add a new one
#     inline (make & model + optional serial). New assets are saved to the
#     customer for next time.
#   - Single asset in the create modal by design; a note points staff to the
#     work-order screen for multi-asset tickets (which already supports it).
#   - Optional — an appointment can still be created with no asset (work-ups).
#
# Reuses the existing asset plumbing: the chosen/created asset flows through
# createAppointment -> persistAppointmentAssets (create/link) and the service
# line is tagged via buildBookingPlan's asset_client_key. No new tables.
#
# Files touched (all edits gated by MARKER-APPT-ASSET, so re-running is a no-op):
#   1. routes/web.php                                        (+1 ajax route)
#   2. app/Http/Controllers/Tenant/AppointmentController.php (endpoint + store wiring)
#   3. app/Services/BookingService.php                       (capture serial on new asset)
#   4. resources/views/tenant/appointments/_create_modal.blade.php (section + JS)
#
# Run from the repo root on the Mac:  bash apply-appointment-create-asset.sh

set -e

for p in routes/web.php app/Http/Controllers/Tenant/AppointmentController.php \
         app/Services/BookingService.php \
         resources/views/tenant/appointments/_create_modal.blade.php; do
  [ -f "$p" ] || { echo "ERROR: $p not found — run from the intake-license repo root." >&2; exit 1; }
done

python3 - <<'PYEOF'
import sys

def load(p):
    return open(p).read()

def save(p, s):
    open(p, "w").write(s)

def repl(src, old, new, label):
    n = src.count(old)
    if n != 1:
        print(f"ERROR [{label}]: expected exactly 1 match, found {n}.", file=sys.stderr)
        sys.exit(1)
    return src.replace(old, new)

MARK = "MARKER-APPT-ASSET"

# ─────────────────────────────────────────────────────────────────────────
# 1. routes/web.php
# ─────────────────────────────────────────────────────────────────────────
p = "routes/web.php"
s = load(p)
if MARK in s:
    print(f"SKIP {p} (already patched)")
else:
    anchor = "            Route::get('/appointments/picker-data', [TenantControllers\\AppointmentController::class, 'pickerData'])->name('appointments.picker-data');"
    add = anchor + "\n            Route::get('/appointments/customer-assets', [TenantControllers\\AppointmentController::class, 'customerAssets'])->name('appointments.customer-assets'); // MARKER-APPT-ASSET"
    s = repl(s, anchor, add, "route")
    save(p, s)
    print(f"OK   {p}")

# ─────────────────────────────────────────────────────────────────────────
# 2. AppointmentController.php
# ─────────────────────────────────────────────────────────────────────────
p = "app/Http/Controllers/Tenant/AppointmentController.php"
s = load(p)
if MARK in s:
    print(f"SKIP {p} (already patched)")
else:
    # 2a. new endpoint before store()
    store_anchor = "    public function store(Request $request)\n    {"
    method = (
        "    // MARKER-APPT-ASSET — customer's assets for the create-appointment picker.\n"
        "    // Gated on multi_asset_enabled (the flag that turns on asset tracking).\n"
        "    public function customerAssets(Request $request): \\Illuminate\\Http\\JsonResponse\n"
        "    {\n"
        "        $tenant = tenant();\n"
        "        abort_unless((bool) ($tenant->multi_asset_enabled ?? false), 404);\n"
        "\n"
        "        $customer = TenantCustomer::where('tenant_id', $tenant->id)\n"
        "            ->where('id', (string) $request->query('customer_id'))\n"
        "            ->first();\n"
        "        if (! $customer) {\n"
        "            return response()->json(['assets' => []]);\n"
        "        }\n"
        "\n"
        "        $assets = \\App\\Models\\Tenant\\TenantCustomerAsset::where('tenant_id', $tenant->id)\n"
        "            ->where('customer_id', $customer->id)\n"
        "            ->whereNull('archived_at')\n"
        "            ->orderBy('name')\n"
        "            ->get(['id', 'name', 'identifier'])\n"
        "            ->map(fn ($a) => ['id' => (string) $a->id, 'name' => $a->name, 'identifier' => $a->identifier])\n"
        "            ->values()->all();\n"
        "\n"
        "        return response()->json(['assets' => $assets]);\n"
        "    }\n"
        "\n"
        + store_anchor
    )
    s = repl(s, store_anchor, method, "controller.endpoint")

    # 2b. validation keys
    val_anchor = (
        "            'items.*.service_item_id'      => ['required', 'string', 'uuid'],\n"
        "            'items.*.price_override_cents' => ['nullable', 'integer', 'min:0'],\n"
        "        ]);"
    )
    val_new = (
        "            'items.*.service_item_id'      => ['required', 'string', 'uuid'],\n"
        "            'items.*.price_override_cents' => ['nullable', 'integer', 'min:0'],\n"
        "            'items.*.asset_client_key'     => ['nullable', 'string', 'max:64'],\n"
        "            'assets'                       => ['nullable', 'array', 'max:1'],\n"
        "            'assets.*.client_key'          => ['nullable', 'string', 'max:64'],\n"
        "            'assets.*.customer_asset_id'   => ['nullable', 'string', 'uuid'],\n"
        "            'assets.*.name_snapshot'       => ['nullable', 'string', 'max:120'],\n"
        "            'assets.*.identifier'          => ['nullable', 'string', 'max:120'],\n"
        "        ]);"
    )
    s = repl(s, val_anchor, val_new, "controller.validation")

    # 2c. carry asset_client_key onto each item
    item_anchor = (
        "                    'price_override_cents' => $item['price_override_cents'] ?? null,\n"
        "                    'addon_ids'            => [],"
    )
    item_new = (
        "                    'price_override_cents' => $item['price_override_cents'] ?? null,\n"
        "                    'asset_client_key'     => $item['asset_client_key'] ?? null,\n"
        "                    'addon_ids'            => [],"
    )
    s = repl(s, item_anchor, item_new, "controller.item")

    # 2d. pass assets to createAppointment
    pay_anchor = "            }, $data['items']),\n            'payment_method'   => 'none',"
    pay_new = "            }, $data['items']),\n            'assets'           => $data['assets'] ?? [],\n            'payment_method'   => 'none',"
    s = repl(s, pay_anchor, pay_new, "controller.payload")

    save(p, s)
    print(f"OK   {p}")

# ─────────────────────────────────────────────────────────────────────────
# 3. BookingService.php — capture serial on a newly created asset
# ─────────────────────────────────────────────────────────────────────────
p = "app/Services/BookingService.php"
s = load(p)
if MARK in s:
    print(f"SKIP {p} (already patched)")
else:
    anchor = (
        "                    'name'                => $name,\n"
        "                    'last_seen_at'        => now(),\n"
        "                    'last_appointment_id' => $appointment->id,\n"
        "                ]);"
    )
    new = (
        "                    'name'                => $name,\n"
        "                    'identifier'          => (isset($entry['identifier']) && $entry['identifier'] !== '') ? (string) $entry['identifier'] : null, // MARKER-APPT-ASSET\n"
        "                    'last_seen_at'        => now(),\n"
        "                    'last_appointment_id' => $appointment->id,\n"
        "                ]);"
    )
    s = repl(s, anchor, new, "booking.identifier")
    save(p, s)
    print(f"OK   {p}")

# ─────────────────────────────────────────────────────────────────────────
# 4. _create_modal.blade.php
# ─────────────────────────────────────────────────────────────────────────
p = "resources/views/tenant/appointments/_create_modal.blade.php"
s = load(p)
if MARK in s:
    print(f"SKIP {p} (already patched)")
else:
    # 4a. asset section HTML (between Customer and Service)
    sec_anchor = "        </div>\n\n        {{-- SEQUENTIAL-PICKER v1 --}}"
    sec_block = (
        "        </div>\n\n"
        "        {{-- MARKER-APPT-ASSET — single-asset select/create; multi lives on the work order page --}}\n"
        "        @if($currentTenant->multi_asset_enabled)\n"
        "        @php $aSing = strtolower($currentTenant->asset_label_singular ?: 'item'); $aPlur = strtolower($currentTenant->asset_label_plural ?: ($aSing.'s')); @endphp\n"
        "        <div class=\"appt-section\" id=\"appt-asset-section\">\n"
        "          <div class=\"appt-section-h\">{{ ucfirst($aSing) }}</div>\n"
        "          <div id=\"appt-asset-need-customer\" style=\"font-size:11px;opacity:.55\">Choose a customer to pick or add a {{ $aSing }}.</div>\n"
        "          <select id=\"appt-asset-select\" class=\"appt-input\" style=\"display:none\"\n"
        "                  onchange=\"var n=document.getElementById('appt-asset-new'); if(n) n.style.display=(this.value==='__new__'?'block':'none');\"></select>\n"
        "          <div id=\"appt-asset-new\" style=\"display:none; margin-top:8px\">\n"
        "            <div class=\"appt-row\">\n"
        "              <input type=\"text\" id=\"appt-asset-name\" class=\"appt-input\" placeholder=\"Make &amp; model\">\n"
        "              <input type=\"text\" id=\"appt-asset-id\"   class=\"appt-input\" placeholder=\"Serial (optional)\">\n"
        "            </div>\n"
        "            <div style=\"font-size:11px;opacity:.55;margin-top:6px\">Saved to the customer for next time.</div>\n"
        "          </div>\n"
        "          <p style=\"font-size:11px;opacity:.55;margin-top:8px\">For multi-{{ $aPlur }} work orders, create the appointment and add {{ $aPlur }} on the work order screen.</p>\n"
        "        </div>\n"
        "        @endif\n\n"
        "        {{-- SEQUENTIAL-PICKER v1 --}}"
    )
    s = repl(s, sec_anchor, sec_block, "modal.section")

    # 4b. assetsCfg after the routes object
    cfg_anchor = "    weekTimes:         \"{{ route('tenant.appointments.week-times') }}\",\n  };"
    cfg_new = (
        "    weekTimes:         \"{{ route('tenant.appointments.week-times') }}\",\n  };\n\n"
        "  // MARKER-APPT-ASSET — asset picker config (label + endpoint from the tenant)\n"
        "  var assetsCfg = {\n"
        "    enabled: {{ $currentTenant->multi_asset_enabled ? 'true' : 'false' }},\n"
        "    singular: @json(strtolower($currentTenant->asset_label_singular ?: 'item')),\n"
        "    url: \"{{ route('tenant.appointments.customer-assets') }}\",\n"
        "  };"
    )
    s = repl(s, cfg_anchor, cfg_new, "modal.cfg")

    # 4c. clearCustomer -> add assetReset() + define asset helpers after it
    clr_anchor = (
        "  function clearCustomer() {\n"
        "    state.customerId = null;\n"
        "    el('appt-cust-attached').style.display = 'none';\n"
        "    el('appt-cust-search-wrap').style.display = 'block';\n"
        "    el('appt-cust-search').value = '';\n"
        "    el('appt-cust-search').focus();\n"
        "  }"
    )
    clr_new = (
        "  function clearCustomer() {\n"
        "    state.customerId = null;\n"
        "    el('appt-cust-attached').style.display = 'none';\n"
        "    el('appt-cust-search-wrap').style.display = 'block';\n"
        "    el('appt-cust-search').value = '';\n"
        "    el('appt-cust-search').focus();\n"
        "    assetReset();\n"
        "  }\n\n"
        "  // ── MARKER-APPT-ASSET — single asset select/create ──\n"
        "  function assetOpt(v, label){ var o=document.createElement('option'); o.value=v; o.textContent=label; return o; }\n"
        "  function assetReset(){\n"
        "    if(!assetsCfg.enabled) return;\n"
        "    var need=el('appt-asset-need-customer'); if(need) need.style.display='block';\n"
        "    var sel=el('appt-asset-select'); if(sel){ sel.innerHTML=''; sel.style.display='none'; }\n"
        "    var nw=el('appt-asset-new'); if(nw) nw.style.display='none';\n"
        "    var nm=el('appt-asset-name'); if(nm) nm.value='';\n"
        "    var ni=el('appt-asset-id'); if(ni) ni.value='';\n"
        "  }\n"
        "  function assetNewOnly(){\n"
        "    if(!assetsCfg.enabled) return;\n"
        "    var need=el('appt-asset-need-customer'); if(need) need.style.display='none';\n"
        "    var sel=el('appt-asset-select'); if(sel) sel.style.display='none';\n"
        "    var nw=el('appt-asset-new'); if(nw) nw.style.display='block';\n"
        "  }\n"
        "  function assetLoadFor(customerId){\n"
        "    if(!assetsCfg.enabled) return;\n"
        "    assetReset();\n"
        "    var need=el('appt-asset-need-customer'); if(need) need.style.display='none';\n"
        "    fetch(assetsCfg.url + '?customer_id=' + encodeURIComponent(customerId), { headers:{'Accept':'application/json'}, credentials:'same-origin' })\n"
        "      .then(function(r){ return r.json(); })\n"
        "      .then(function(d){\n"
        "        var assets=(d && d.assets) || [];\n"
        "        var sel=el('appt-asset-select');\n"
        "        if(assets.length && sel){\n"
        "          sel.innerHTML='';\n"
        "          sel.appendChild(assetOpt('', 'Select ' + assetsCfg.singular + '\\u2026'));\n"
        "          assets.forEach(function(a){ sel.appendChild(assetOpt(a.id, a.name + (a.identifier ? ' \\u00b7 ' + a.identifier : ''))); });\n"
        "          sel.appendChild(assetOpt('__new__', '+ Add new ' + assetsCfg.singular));\n"
        "          sel.style.display='block';\n"
        "          var nw=el('appt-asset-new'); if(nw) nw.style.display='none';\n"
        "        } else {\n"
        "          assetNewOnly();\n"
        "        }\n"
        "      })\n"
        "      .catch(function(){ assetNewOnly(); });\n"
        "  }"
    )
    s = repl(s, clr_anchor, clr_new, "modal.helpers")

    # 4d. attachCustomer -> load that customer's assets
    att_anchor = (
        "    el('appt-cust-attached').style.display = 'flex';\n"
        "    el('appt-cust-search-wrap').style.display = 'none';\n"
        "  }"
    )
    att_new = (
        "    el('appt-cust-attached').style.display = 'flex';\n"
        "    el('appt-cust-search-wrap').style.display = 'none';\n"
        "    assetLoadFor(c.id);\n"
        "  }"
    )
    s = repl(s, att_anchor, att_new, "modal.attach")

    # 4e. new-customer path -> add-new only (no existing assets yet)
    nm_anchor = (
        "      box.style.display = 'none';\n"
        "      el('appt-cust-new-fields').style.display = 'block';\n"
        "      var parts = query.split(/\\s+/);"
    )
    nm_new = (
        "      box.style.display = 'none';\n"
        "      el('appt-cust-new-fields').style.display = 'block';\n"
        "      assetNewOnly();\n"
        "      var parts = query.split(/\\s+/);"
    )
    s = repl(s, nm_anchor, nm_new, "modal.newcust")

    # 4f. submit -> inject the asset payload
    sub_anchor = "    var csrfMeta = document.querySelector('meta[name=\"csrf-token\"]');\n    fetch(routes.store, {"
    sub_new = (
        "    if (assetsCfg.enabled) {\n"
        "      var _an = el('appt-asset-name'), _ai = el('appt-asset-id'), _as = el('appt-asset-select');\n"
        "      var _nw = el('appt-asset-new');\n"
        "      var _newVisible = _nw && _nw.style.display !== 'none';\n"
        "      var _name = _an ? _an.value.trim() : '';\n"
        "      var _selVal = _as ? _as.value : '';\n"
        "      if (_newVisible && _name) {\n"
        "        payload.assets = [{ client_key: 'a1', name_snapshot: _name, identifier: (_ai && _ai.value.trim()) || null }];\n"
        "        payload.items[0].asset_client_key = 'a1';\n"
        "      } else if (_selVal && _selVal !== '__new__') {\n"
        "        payload.assets = [{ client_key: 'a1', customer_asset_id: _selVal }];\n"
        "        payload.items[0].asset_client_key = 'a1';\n"
        "      }\n"
        "    }\n\n"
        "    var csrfMeta = document.querySelector('meta[name=\"csrf-token\"]');\n    fetch(routes.store, {"
    )
    s = repl(s, sub_anchor, sub_new, "modal.submit")

    save(p, s)
    print(f"OK   {p}")

print("\nAll edits applied.")
PYEOF

echo ""
echo "Syntax-checking PHP (Blade skipped — php can't fully lint it):"
for f in routes/web.php app/Http/Controllers/Tenant/AppointmentController.php app/Services/BookingService.php; do
  if php -l "$f" >/dev/null 2>&1; then echo "OK   php -l  $f"; else echo "ERROR php -l  $f"; php -l "$f"; exit 1; fi
done

echo ""
echo "SUCCESS. Deploy, then run: php artisan optimize:clear  (route + view cache)."
echo "Test: create an appointment for a customer with saved assets, and for a new customer."
