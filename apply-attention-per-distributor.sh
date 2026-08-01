#!/bin/bash
# attention-per-distributor — stop the attention page claiming everything is HLC.
#
#   The page is titled "HLC Catalog", says "Checks HLC for changes nightly",
#   labels renames "Renamed by HLC" and writes "HLC no longer publishes …" —
#   while its query is not distributor-scoped at all. It lists every flag for
#   the tenant, so with BTI connected it has been attributing BTI's findings
#   to HLC.
#
#   Fixed by naming the distributor that actually raised each flag rather
#   than by scoping the page to one. The flag carries distributor_catalog_id
#   and the view already resolves $cat, so the real code is available per
#   row. A single queue across distributors is the right shape — a shop wants
#   one list of things needing a decision, not one list per vendor — it just
#   has to say where each item came from.
#
#   Each row now shows the distributor as a badge beside the item, and the
#   "no longer publishes" line names it. Where no single distributor applies
#   (the page heading, the nightly-check line, the rename badge) the wording
#   becomes plural instead of picking one.
# NO MIGRATION. Server: view:clear
set -e
if grep -q "MARKER-ATTENTION-PER-DIST" resources/views/tenant/distributors/attention.blade.php; then
  echo "attention-per-distributor already applied — aborting."; exit 1
fi

python3 - <<'APD_0_EOF'
import io
p = 'resources/views/tenant/distributors/attention.blade.php'
s = io.open(p, encoding='utf-8').read()

# --- heading -------------------------------------------------------------
old = """  <h1 style="font-size:20px;font-weight:600;margin-bottom:14px">HLC Catalog</h1>"""
assert s.count(old) == 1, ('heading', s.count(old))
new = """  {{-- MARKER-ATTENTION-PER-DIST — this queue spans every connected
       distributor, so it can't be titled after one of them. --}}
  <h1 style="font-size:20px;font-weight:600;margin-bottom:14px">Distributor catalogs</h1>"""
s = s.replace(old, new)

# --- nightly line --------------------------------------------------------
old = """      <div class="d">Checks HLC for changes nightly at 5:00 am</div>"""
assert s.count(old) == 1, ('nightly', s.count(old))
new = """      <div class="d">Checks your connected distributors nightly at 5:00 am</div>"""
s = s.replace(old, new)

# --- rename badge --------------------------------------------------------
old = """      'title_changed' => ['at-b-title','Renamed by HLC'],"""
assert s.count(old) == 1, ('badge', s.count(old))
new = """      'title_changed' => ['at-b-title','Renamed by distributor'],"""
s = s.replace(old, new)

# --- vanished line names the distributor ---------------------------------
old = """                  HLC no longer publishes {{ $what }} for this item"""
assert s.count(old) == 1, ('vanished', s.count(old))
new = """                  {{ $cat?->distributor_code ?: 'The distributor' }} no longer publishes {{ $what }} for this item"""
s = s.replace(old, new)

# --- per-row distributor badge -------------------------------------------
old = """              [$bc, $bl] = $badge($f->reason);
              $item = $f->item; $d = $f->detail ?? []; $cat = $item?->distributorCatalog;"""
assert s.count(old) == 1, ('row vars', s.count(old))
new = """              [$bc, $bl] = $badge($f->reason);
              $item = $f->item; $d = $f->detail ?? []; $cat = $item?->distributorCatalog;
              // MARKER-ATTENTION-PER-DIST — which distributor raised this one.
              $flagDist = $cat?->distributor_code;"""
s = s.replace(old, new)

io.open(p, 'w', encoding='utf-8').write(s)
print('view ok')
APD_0_EOF

# --- show the badge next to the item name --------------------------------
python3 - <<'APD_1_EOF'
import io
p = 'resources/views/tenant/distributors/attention.blade.php'
s = io.open(p, encoding='utf-8').read()

old = """                <div class="at-dim at-mono" style="font-size:11px">{{ $item->sku ?? '' }} \u00b7 {{ $item->computed_stock_count ?? 0 }} in stock</div>"""
assert s.count(old) == 1, ('item cell', s.count(old))
new = """                <div class="at-dim at-mono" style="font-size:11px">{{ $item->sku ?? '' }} \u00b7 {{ $item->computed_stock_count ?? 0 }} in stock@if($flagDist) \u00b7 <span style="font-weight:700">{{ $flagDist }}</span>@endif</div>"""
s = s.replace(old, new)

io.open(p, 'w', encoding='utf-8').write(s)
print('row badge ok')
APD_1_EOF

echo
echo "attention-per-distributor applied."
