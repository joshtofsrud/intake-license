#!/bin/bash
# item-modal-all-vendors — show every distributor that carries the item.
#
#   itemInfo read $item->distributorCatalog — the ONE primary catalog row —
#   so the modal's Vendor inventory section showed a single distributor even
#   for an item the matcher linked across two. The whole point of matching is
#   that a shop can see both offers and choose; until now they couldn't see
#   the second one.
#
#   Now it walks tenant_item_vendors, which is where the importer records a
#   source per distributor and where the tenant sync writes live_cost_cents
#   and live_avail. Per source it reports:
#     · that distributor's cost, from the pivot (this is per-tenant data,
#       which is exactly why it lives there and not on the shared catalog)
#     · availability, preferring the pivot's live figure and falling back to
#       the stored snapshot
#     · when it was last checked
#     · whether it's the source the item's product info comes from
#
#   Sorted cheapest first so the useful comparison is the top line, with the
#   data source marked separately — those are different questions and a shop
#   shouldn't have to infer one from the other.
#
#   Still snapshot data, not a live call: this fires on every tap of "i".
# NO MIGRATION. Server: optimize:clear && view:clear
set -e
if grep -q "MARKER-MODAL-ALL-VENDORS" app/Http/Controllers/Tenant/RegisterController.php; then
  echo "item-modal-all-vendors already applied — aborting."; exit 1
fi

# ------------------------------------------------------------------ controller
python3 - <<'IAV_0_EOF'
import io
p = 'app/Http/Controllers/Tenant/RegisterController.php'
s = io.open(p, encoding='utf-8').read()

start = s.index("        // MARKER-ITEM-MODAL-VENDOR — what the distributor last reported for")
end   = s.index("        return response()->json([", start)

block = """        // MARKER-MODAL-ALL-VENDORS — every distributor that carries this item,
        // not just the one supplying its product info.
        //
        // Was $item->distributorCatalog, a single row, so a matched item
        // showed one vendor and the shop had nothing to compare. Sources live
        // on tenant_item_vendors: the importer writes one per distributor and
        // the tenant sync keeps live_cost_cents / live_avail there. Cost is
        // per-tenant, which is why it's on this pivot and deliberately null
        // on the shared catalog.
        //
        // Still the stored snapshot rather than a live call — this endpoint
        // fires every time anyone taps "i".
        $vendor = [];

        $sources = \\App\\Models\\Tenant\\TenantInventoryItemVendor::query()
            ->where('inventory_item_id', $item->id)
            ->whereNotNull('distributor_catalog_id')
            ->get(['distributor_code', 'distributor_catalog_id',
                   'live_cost_cents', 'unit_cost_cents', 'live_avail', 'live_checked_at']);

        $catalogRows = \\App\\Models\\PlatformDistributorCatalog::query()
            ->whereIn('id', $sources->pluck('distributor_catalog_id')->filter())
            ->get(['id', 'distributor_code', 'distributor_variant_no'])
            ->keyBy('id');

        foreach ($sources as $src) {
            $row = $catalogRows[$src->distributor_catalog_id] ?? null;

            $avail     = $src->live_avail;
            $checkedAt = $src->live_checked_at;

            // Fall back to the stored snapshot when the pivot has no live
            // figure — an item imported but never cost-synced has none.
            if ($avail === null && $row && ! empty($row->distributor_variant_no)) {
                $snap = \\Illuminate\\Support\\Facades\\DB::table('distributor_availability_snapshots')
                    ->where('distributor_code', $row->distributor_code)
                    ->where('distributor_variant_no', $row->distributor_variant_no)
                    ->where(fn ($q) => $q->whereNull('tenant_id')->orWhere('tenant_id', $tenant->id))
                    ->orderByDesc('checked_at')
                    ->first(['avail', 'checked_at']);

                if ($snap) {
                    $avail     = $snap->avail === null ? null : (int) $snap->avail;
                    $checkedAt = $snap->checked_at;
                }
            }

            $vendor[] = [
                'distributor' => $src->distributor_code,
                'avail'       => $avail === null ? null : (int) $avail,
                'cost_cents'  => $src->live_cost_cents ?? $src->unit_cost_cents,
                'checked_at'  => $checkedAt,
                // Which source supplies the name, description and specs —
                // a different question from which is cheapest.
                'is_source'   => $src->distributor_catalog_id === $item->distributor_catalog_id,
            ];
        }

        // Cheapest first; a source with no cost sorts last rather than as free.
        usort($vendor, fn ($a, $b) => ($a['cost_cents'] ?? PHP_INT_MAX) <=> ($b['cost_cents'] ?? PHP_INT_MAX));

"""

s = s[:start] + block + s[end:]
io.open(p, 'w', encoding='utf-8').write(s)
print('controller ok')
IAV_0_EOF

# ------------------------------------------------------------------ partial
python3 - <<'IAV_1_EOF'
import io
p = 'resources/views/tenant/_item-detail-modal.blade.php'
s = io.open(p, encoding='utf-8').read()

old = """  // MARKER-ITEM-MODAL-VENDOR — what the distributor last told us, with its age.
  function paintVendor( vendor ) {
    if ( !vendor || !vendor.length ) return;
    var rows = '', newest = null;

    vendor.forEach( function ( v ) {
      var qty = ( v.avail === null || v.avail === undefined ) ? 'unknown' : v.avail;
      rows += '<tr style="border-top:0.5px solid var(--ia-border)">'
           +  '<td class="k">' + esc( v.distributor ) + '</td>'
           +  '<td class="n">' + esc( qty ) + '</td></tr>';
      if ( !newest || ( v.checked_at && v.checked_at > newest ) ) newest = v.checked_at;
    } );"""
assert s.count(old) == 1, s.count(old)

new = """  // MARKER-MODAL-ALL-VENDORS — one line per distributor that carries the item.
  // Cost comes from the per-tenant pivot, so it's this shop's cost, not a
  // list price. "info" marks which source supplies the product data, which is
  // a separate question from which is cheapest.
  function paintVendor( vendor ) {
    if ( !vendor || !vendor.length ) return;
    var rows = '', newest = null;

    vendor.forEach( function ( v, i ) {
      var qty = ( v.avail === null || v.avail === undefined ) ? 'unknown' : v.avail;
      var cost = ( v.cost_cents === null || v.cost_cents === undefined )
        ? '&mdash;' : money( v.cost_cents );

      var tags = '';
      if ( i === 0 && vendor.length > 1 && v.cost_cents !== null && v.cost_cents !== undefined ) {
        tags += ' <span style="font-size:9.5px;font-weight:700;color:#8FD14F;letter-spacing:.04em">CHEAPEST</span>';
      }
      if ( v.is_source ) {
        tags += ' <span style="font-size:9.5px;font-weight:700;color:var(--ia-text-muted);letter-spacing:.04em">INFO</span>';
      }

      rows += '<tr style="border-top:0.5px solid var(--ia-border)">'
           +  '<td class="k">' + esc( v.distributor ) + tags + '</td>'
           +  '<td class="n">' + cost + '</td>'
           +  '<td class="n">' + esc( qty ) + '</td></tr>';
      if ( !newest || ( v.checked_at && v.checked_at > newest ) ) newest = v.checked_at;
    } );

    rows = '<tr><td class="k" style="font-size:10px;letter-spacing:.08em;text-transform:uppercase;color:var(--ia-text-muted)">Vendor</td>'
         + '<td class="n" style="font-size:10px;letter-spacing:.08em;text-transform:uppercase;color:var(--ia-text-muted)">Your cost</td>'
         + '<td class="n" style="font-size:10px;letter-spacing:.08em;text-transform:uppercase;color:var(--ia-text-muted)">Available</td></tr>'
         + rows;"""
s = s.replace(old, new)

io.open(p, 'w', encoding='utf-8').write(s)
print('partial ok')
IAV_1_EOF

php -l app/Http/Controllers/Tenant/RegisterController.php

echo
echo "item-modal-all-vendors applied."
