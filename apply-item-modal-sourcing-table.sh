#!/bin/bash
# item-modal-sourcing-table — one table, with each vendor's own item number.
#
#   The modal had two tables answering overlapping questions. "Vendor
#   inventory" listed cost and availability per distributor; "Stock &
#   identifiers" listed shop stock, then a single SKU — which was whichever
#   distributor happened to be the item's primary source. On a matched item
#   that SKU is right for one vendor and wrong for the other, while the row
#   that needs it most, the second vendor, had none at all.
#
#   Now one "Sourcing & stock" table:
#     vendor · their item number · your cost · available
#   then the shop's own stock beneath a divider, then the identifiers that
#   genuinely belong to the product rather than to a vendor — UPC and
#   category. Those are the same whoever supplies it, so they stay
#   item-level.
#
#   The per-vendor number comes from that source's own catalog row
#   (distributor_variant_no), which is what you quote on a purchase order —
#   so ordering the same tire from HLC or BTI now shows the number each of
#   them actually wants.
# NO MIGRATION. Server: optimize:clear && view:clear
set -e
if grep -q "MARKER-SOURCING-TABLE" resources/views/tenant/_item-detail-modal.blade.php; then
  echo "item-modal-sourcing-table already applied — aborting."; exit 1
fi

# ------------------------------------------------------------------ controller
python3 - <<'IST_0_EOF'
import io
p = 'app/Http/Controllers/Tenant/RegisterController.php'
s = io.open(p, encoding='utf-8').read()

old = """            $vendor[] = [
                'distributor' => $src->distributor_code,"""
assert s.count(old) == 1, s.count(old)
new = """            $vendor[] = [
                'distributor' => $src->distributor_code,
                // MARKER-SOURCING-TABLE — that vendor's OWN item number, which
                // is what goes on a purchase order to them. The modal used to
                // show a single SKU taken from the item's primary source, so
                // on a matched item it was right for one vendor and absent
                // for the other.
                'sku'         => $src->vendor_sku
                    ?: ($row->distributor_variant_no ?? null),"""
s = s.replace(old, new)

# vendor_sku lives on the pivot; make sure it's selected
old = """            ->get(['distributor_code', 'distributor_catalog_id',
                   'live_cost_cents', 'unit_cost_cents', 'live_avail', 'live_checked_at']);"""
assert s.count(old) == 1, s.count(old)
new = """            ->get(['distributor_code', 'distributor_catalog_id', 'vendor_sku',
                   'live_cost_cents', 'unit_cost_cents', 'live_avail', 'live_checked_at']);"""
s = s.replace(old, new)

io.open(p, 'w', encoding='utf-8').write(s)
print('controller ok')
IST_0_EOF

# ------------------------------------------------------------------ partial
python3 - <<'IST_1_EOF'
import io
p = 'resources/views/tenant/_item-detail-modal.blade.php'
s = io.open(p, encoding='utf-8').read()

# --- one section instead of two -----------------------------------------
old = """      <div class="rim-sec" id="rim-sec-vendor" style="display:none"><h3>Vendor inventory</h3><table id="rim-vendor"></table><div class="rim-asof" id="rim-vendor-asof"></div></div>
      <div class="rim-sec"><h3>Stock &amp; identifiers</h3><table id="rim-table"></table></div>"""
assert s.count(old) == 1, s.count(old)
new = """      {{-- MARKER-SOURCING-TABLE — vendors, then the shop's own stock, then the
           identifiers that belong to the product rather than to a vendor. --}}
      <div class="rim-sec"><h3>Sourcing &amp; stock</h3>
        <table id="rim-vendor"></table>
        <table id="rim-table"></table>
        <div class="rim-asof" id="rim-vendor-asof"></div>
      </div>"""
s = s.replace(old, new)

# reset() no longer has a section to hide
old = """    el( 'rim-sec-vendor' ).style.display = 'none';
"""
assert s.count(old) == 1, s.count(old)
s = s.replace(old, "")

old = """    el( 'rim-sec-vendor' ).style.display = '';
"""
assert s.count(old) == 1, s.count(old)
s = s.replace(old, "")

# --- vendor rows gain a SKU column --------------------------------------
old = """      rows += '<tr style="border-top:0.5px solid var(--ia-border)">'
           +  '<td class="k">' + esc( v.distributor ) + tags + '</td>'
           +  '<td class="n">' + cost + '</td>'
           +  '<td class="n">' + esc( qty ) + '</td></tr>';
      if ( !newest || ( v.checked_at && v.checked_at > newest ) ) newest = v.checked_at;
    } );

    rows = '<tr><td class="k" style="font-size:10px;letter-spacing:.08em;text-transform:uppercase;color:var(--ia-text-muted)">Vendor</td>'
         + '<td class="n" style="font-size:10px;letter-spacing:.08em;text-transform:uppercase;color:var(--ia-text-muted)">Your cost</td>'
         + '<td class="n" style="font-size:10px;letter-spacing:.08em;text-transform:uppercase;color:var(--ia-text-muted)">Available</td></tr>'
         + rows;"""
assert s.count(old) == 1, s.count(old)
new = """      rows += '<tr style="border-top:0.5px solid var(--ia-border)">'
           +  '<td class="k">' + esc( v.distributor ) + tags + '</td>'
           +  '<td style="font-family:ui-monospace,monospace;font-size:11.5px;color:var(--ia-text-muted)">'
           +  esc( v.sku || '—' ) + '</td>'
           +  '<td class="n">' + cost + '</td>'
           +  '<td class="n">' + esc( qty ) + '</td></tr>';
      if ( !newest || ( v.checked_at && v.checked_at > newest ) ) newest = v.checked_at;
    } );

    var head = 'font-size:10px;letter-spacing:.08em;text-transform:uppercase;color:var(--ia-text-muted)';
    rows = '<tr><td style="' + head + '">Vendor</td>'
         + '<td style="' + head + '">Their item no.</td>'
         + '<td class="n" style="' + head + '">Your cost</td>'
         + '<td class="n" style="' + head + '">Available</td></tr>'
         + rows;"""
s = s.replace(old, new)

# --- shop stock + product identifiers, spanning the same 4 columns -------
old = """      var rows = [];
      ( d.stock || [] ).forEach( function ( s ) {
        rows.push( '<td class="k">' + esc( s.location ) + '</td><td class="n">' + s.count + '</td>' );
      } );
      if ( d.sku )      rows.push( '<td class="k">SKU</td><td class="n" style="font-family:ui-monospace,monospace;font-size:12px">' + esc( d.sku ) + '</td>' );
      if ( d.upc )      rows.push( '<td class="k">UPC</td><td class="n" style="font-family:ui-monospace,monospace;font-size:12px">' + esc( d.upc ) + '</td>' );
      if ( d.category ) rows.push( '<td class="k">Category</td><td class="n">' + esc( d.category ) + '</td>' );
      el( 'rim-table' ).innerHTML = rows.map( function ( r2 ) {
        return '<tr style="border-top:0.5px solid var(--ia-border)">' + r2 + '</tr>';
      } ).join( '' );"""
assert s.count(old) == 1, s.count(old)
new = """      // MARKER-SOURCING-TABLE — the shop's own stock and the identifiers that
      // belong to the PRODUCT. A vendor's item number is per vendor and now
      // lives in the row above; UPC and category are the same whoever
      // supplies it, so they stay here.
      var rows = [];
      ( d.stock || [] ).forEach( function ( s ) {
        rows.push( '<td class="k">' + esc( s.location ) + '</td><td class="n">' + s.count + '</td>' );
      } );
      if ( d.upc )      rows.push( '<td class="k">UPC</td><td class="n" style="font-family:ui-monospace,monospace;font-size:12px">' + esc( d.upc ) + '</td>' );
      if ( d.category ) rows.push( '<td class="k">Category</td><td class="n">' + esc( d.category ) + '</td>' );

      var header = rows.length
        ? '<tr><td colspan="2" style="padding-top:12px;font-size:10px;letter-spacing:.08em;'
          + 'text-transform:uppercase;color:var(--ia-text-muted)">Your shop</td></tr>'
        : '';

      el( 'rim-table' ).innerHTML = header + rows.map( function ( r2 ) {
        return '<tr style="border-top:0.5px solid var(--ia-border)">' + r2 + '</tr>';
      } ).join( '' );"""
s = s.replace(old, new)

io.open(p, 'w', encoding='utf-8').write(s)
print('partial ok')
IST_1_EOF

echo
echo "item-modal-sourcing-table applied."
