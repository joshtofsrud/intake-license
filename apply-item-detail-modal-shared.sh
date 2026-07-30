#!/bin/bash
# item-detail-modal-shared — one item modal, both surfaces, with vendor stock.
#
#   Three things:
#
#   1. The item modal moves out of register/index.blade.php into a shared
#      partial. It was inline: style block, markup, and script. Both surfaces
#      now include the same file so they cannot drift.
#
#   2. The appointment / work-order part picker gets the same "i" button.
#      Both of its result renderers, since there are two.
#
#   3. Vendor inventory is added to the modal on both. Source is the latest
#      row in distributor_availability_snapshots for the item's variant —
#      not a live distributor call, which would hit HLC every time anyone
#      taps "i". The "as of" timestamp ships with it so a stale number can't
#      pretend to be current.
#
#   The partial is an IIFE exposing only window.IntakeItemModal. Nothing else
#   leaks: rimItem, rimSwap and openItemInfo were top-level globals in the
#   register and are now scoped inside it. That matters because this file is
#   included on pages that already carry a lot of script — a duplicate
#   top-level identifier is a parse-time error that silently kills an entire
#   block, which is exactly how the inventory chips broke this morning.
#
#   The register keeps a three-line openItemInfo() shim so its existing call
#   site is untouched. The thumbnail swap moved from an inline onclick to a
#   bound listener, so no global is needed for it either.
#
#   The primary button is per-surface: "Add to sale" on the register wires to
#   addToCart; on the appointment page it re-triggers the row's own click, so
#   the existing add_part path (including appointment_asset_id on the second
#   picker) stays the single way a part gets added.
# NO MIGRATION. Server: view:clear.
set -e
if [ -f resources/views/tenant/_item-detail-modal.blade.php ]; then
  echo "item-detail-modal-shared already applied — aborting."; exit 1
fi

# ---------------------------------------------------------------- controller
python3 - <<'IDM_0_EOF'
import io
p = 'app/Http/Controllers/Tenant/RegisterController.php'
s = io.open(p, encoding='utf-8').read()

old = """        return response()->json([
            'ok'          => true,
            'name'        => $item->name,"""
assert s.count(old) == 1, s.count(old)

new = """        // MARKER-ITEM-MODAL-VENDOR \u2014 what the distributor last reported for
        // this variant. Deliberately the stored snapshot, not a live call:
        // this endpoint fires every time someone taps "i", and hitting the
        // distributor that often to refresh a number that moves slowly is a
        // bad trade. checked_at travels with it so the age is visible.
        $vendor = [];
        $dc = $item->distributorCatalog;
        if ($dc && ! empty($dc->distributor_variant_no)) {
            $snap = \\Illuminate\\Support\\Facades\\DB::table('distributor_availability_snapshots')
                ->where('distributor_code', $dc->distributor_code)
                ->where('distributor_variant_no', $dc->distributor_variant_no)
                ->where(fn ($q) => $q->whereNull('tenant_id')->orWhere('tenant_id', $tenant->id))
                ->orderByDesc('checked_at')
                ->first(['distributor_code', 'avail', 'checked_at']);

            if ($snap) {
                $vendor[] = [
                    'distributor' => $snap->distributor_code,
                    'avail'       => $snap->avail === null ? null : (int) $snap->avail,
                    'checked_at'  => $snap->checked_at,
                ];
            }
        }

        return response()->json([
            'ok'          => true,
            'vendor'      => $vendor,
            'name'        => $item->name,"""
io.open(p, 'w', encoding='utf-8').write(s.replace(old, new))
print('controller vendor payload ok')
IDM_0_EOF

# ---------------------------------------------------------------- partial
cat > 'resources/views/tenant/_item-detail-modal.blade.php' <<'IDM_1_EOF'
{{-- MARKER-ITEM-MODAL-SHARED
     Item detail modal, shared by the register and the appointment part
     picker. Lifted out of register/index.blade.php so the two surfaces
     cannot drift.

     Everything is scoped inside one IIFE and only window.IntakeItemModal is
     exposed. This gets included on pages that already run a lot of script,
     and a duplicate top-level identifier is a parse-time error that discards
     the whole block without logging anything. --}}
<style>
  .reg-info-btn,
  .item-info-btn{flex:none;width:22px;height:22px;border-radius:50%;border:0.5px solid var(--ia-border);background:none;color:var(--ia-text-muted);font:italic 700 11px Georgia,serif;cursor:pointer;margin:0 10px;align-self:center}
  .reg-info-btn:hover,
  .item-info-btn:hover{border-color:var(--ia-accent);color:var(--ia-accent)}
  #rim .rim-box{background:var(--ia-surface);border:0.5px solid var(--ia-border);border-radius:16px;width:min(680px,calc(100vw - 28px));max-height:88vh;overflow-y:auto}
  #rim .rim-head{display:flex;gap:18px;padding:22px 24px 18px;border-bottom:0.5px solid var(--ia-border)}
  #rim .rim-gal{flex:none;width:150px}
  #rim .rim-main{width:150px;height:150px;background:#fff;border-radius:12px;object-fit:contain;display:none}
  #rim .rim-main.ph{display:grid;place-items:center;color:#999;font-size:11px;background:var(--ia-surface-2,#222)}
  #rim .rim-thumbs{display:flex;gap:6px;margin-top:8px}
  #rim .rim-thumbs img{width:33px;height:33px;background:#fff;border-radius:7px;object-fit:contain;opacity:.55;cursor:pointer;border:1.5px solid transparent}
  #rim .rim-thumbs img.on{opacity:1;border-color:var(--ia-accent)}
  #rim .rim-brand{font-size:11px;letter-spacing:.1em;text-transform:uppercase;color:var(--ia-accent);font-weight:700}
  #rim h2{font-size:17px;line-height:1.35;margin:3px 0 4px;font-weight:700}
  #rim .rim-sub{font-size:12.5px;color:var(--ia-text-muted)}
  #rim .rim-price-row{display:flex;align-items:baseline;gap:14px;margin-top:12px;flex-wrap:wrap}
  #rim .rim-price{font:700 22px inherit;color:var(--ia-accent)}
  #rim .rim-cost{font-size:12px;color:var(--ia-text-muted)}
  #rim .rim-cost b{color:#8FD14F;font-weight:600}
  #rim .rim-badges{display:flex;gap:6px;margin-top:10px;flex-wrap:wrap}
  #rim .rim-badge{font-size:10.5px;border:0.5px solid var(--ia-border);border-radius:99px;padding:2px 9px;color:var(--ia-text-muted)}
  #rim .rim-badge.ok{color:#8FD14F;border-color:#8FD14F}
  #rim .rim-body{padding:6px 24px 8px}
  #rim .rim-sec{padding:14px 0;border-bottom:0.5px solid var(--ia-border)}
  #rim .rim-sec:last-child{border-bottom:0}
  #rim .rim-sec h3{font-size:10.5px;letter-spacing:.12em;text-transform:uppercase;color:var(--ia-text-muted);margin-bottom:9px;font-weight:600}
  /* MARKER-SPECS-GRID-PAIR — one grid child per attribute. Emitting the
     label and the value as separate children let an odd column count
     offset every pair by one. */
  #rim .rim-attrs{display:grid;grid-template-columns:repeat(auto-fill,minmax(180px,1fr));gap:6px 22px;font-size:12.5px}
  #rim .rim-attrs .pair{display:flex;gap:10px;justify-content:space-between;align-items:baseline;border-bottom:0.5px dotted var(--ia-border);padding-bottom:3px}
  #rim .rim-attrs .pair .v{text-align:right}
  #rim .rim-attrs .k{color:var(--ia-text-muted)}
  #rim table{width:100%;border-collapse:collapse;font-size:12.5px}
  #rim td{padding:5px 0;vertical-align:top}
  #rim td.k{color:var(--ia-text-muted);width:130px}
  #rim td.n{text-align:right;font-variant-numeric:tabular-nums}
  #rim .rim-asof{font-size:10.5px;color:var(--ia-text-muted);margin-top:6px}
  #rim .rim-foot{display:flex;gap:10px;padding:16px 24px 20px;border-top:0.5px solid var(--ia-border);position:sticky;bottom:0;background:var(--ia-surface)}
  #rim .rim-foot .grow{flex:1}
</style>
<div id="rim" style="display:none;position:fixed;inset:0;z-index:210;align-items:center;justify-content:center;background:rgba(0,0,0,.6)" onclick="if(event.target===this)this.style.display='none'">
  <div class="rim-box">
    <div class="rim-head">
      <div class="rim-gal">
        <img class="rim-main" id="rim-main" alt="">
        <div class="rim-main ph" id="rim-ph">no image</div>
        <div class="rim-thumbs" id="rim-thumbs"></div>
      </div>
      <div style="min-width:0">
        <div class="rim-brand" id="rim-brand"></div>
        <h2 id="rim-name"></h2>
        <div class="rim-sub" id="rim-sub"></div>
        <div class="rim-price-row">
          <span class="rim-price" id="rim-price"></span>
          <span class="rim-cost" id="rim-cost"></span>
        </div>
        <div class="rim-badges" id="rim-badges"></div>
      </div>
    </div>
    <div class="rim-body">
      <div class="rim-sec" id="rim-sec-desc" style="display:none"><h3>Description</h3><div id="rim-desc" style="font-size:12.5px;color:var(--ia-text-muted);line-height:1.55"></div></div>
      <div class="rim-sec" id="rim-sec-attrs" style="display:none"><h3>Specs</h3><div class="rim-attrs" id="rim-attrs"></div></div>
      {{-- MARKER-ITEM-MODAL-VENDOR --}}
      <div class="rim-sec" id="rim-sec-vendor" style="display:none"><h3>Vendor inventory</h3><table id="rim-vendor"></table><div class="rim-asof" id="rim-vendor-asof"></div></div>
      <div class="rim-sec"><h3>Stock &amp; identifiers</h3><table id="rim-table"></table></div>
    </div>
    <div class="rim-foot">
      <a class="ia-btn ia-btn--ghost" id="rim-edit" href="#" style="text-decoration:none">Edit item</a>
      <button type="button" class="ia-btn ia-btn--ghost" onclick="document.getElementById('rim').style.display='none'">Close</button>
      <button type="button" class="ia-btn ia-btn--primary grow" id="rim-add">Add</button>
    </div>
  </div>
</div>
<script>
（function () {
  'use strict';

  var current = null;   // payload of the item on screen
  var opts    = {};     // { actionLabel, onAdd }

  function esc( s ) {
    return String( s == null ? '' : s ).replace( /[&<>"']/g, function ( c ) {
      return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;' }[ c ];
    } );
  }

  function money( cents ) {
    var n = ( +cents || 0 ) / 100;
    return '$' + n.toFixed( 2 ).replace( /\B(?=(\d{3})+(?!\d))/g, ',' );
  }

  function ago( iso ) {
    if ( !iso ) return '';
    var then = new Date( String( iso ).replace( ' ', 'T' ) );
    if ( isNaN( then.getTime() ) ) return '';
    var mins = Math.round( ( Date.now() - then.getTime() ) / 60000 );
    if ( mins < 2 )    return 'just now';
    if ( mins < 90 )   return mins + ' minutes ago';
    var hrs = Math.round( mins / 60 );
    if ( hrs < 36 )    return hrs + ' hours ago';
    return Math.round( hrs / 24 ) + ' days ago';
  }

  function el( id ) { return document.getElementById( id ); }

  function reset() {
    el( 'rim-name' ).textContent = 'Loading…';
    [ 'rim-brand', 'rim-sub', 'rim-price', 'rim-cost', 'rim-desc' ]
      .forEach( function ( x ) { el( x ).textContent = ''; } );
    [ 'rim-badges', 'rim-attrs', 'rim-table', 'rim-thumbs', 'rim-vendor' ]
      .forEach( function ( x ) { el( x ).innerHTML = ''; } );
    el( 'rim-vendor-asof' ).textContent = '';
    el( 'rim-main' ).style.display = 'none';
    el( 'rim-ph' ).style.display = 'grid';
    el( 'rim-sec-desc' ).style.display = 'none';
    el( 'rim-sec-attrs' ).style.display = 'none';
    el( 'rim-sec-vendor' ).style.display = 'none';
  }

  function paintGallery( images ) {
    if ( !images || !images.length ) return;
    var main = el( 'rim-main' );
    main.src = images[ 0 ];
    main.style.display = 'block';
    el( 'rim-ph' ).style.display = 'none';
    if ( images.length < 2 ) return;

    el( 'rim-thumbs' ).innerHTML = images.map( function ( u, i ) {
      return '<img src="' + esc( u ) + '" class="' + ( i === 0 ? 'on' : '' ) + '">';
    } ).join( '' );

    // Bound listener rather than an inline onclick, so the swap helper
    // doesn't have to be a global.
    el( 'rim-thumbs' ).querySelectorAll( 'img' ).forEach( function ( t ) {
      t.addEventListener( 'click', function () {
        el( 'rim-main' ).src = t.src;
        el( 'rim-thumbs' ).querySelectorAll( 'img' ).forEach( function ( o ) {
          o.classList.remove( 'on' );
        } );
        t.classList.add( 'on' );
      } );
    } );
  }

  // MARKER-ITEM-MODAL-VENDOR — what the distributor last told us, with its age.
  function paintVendor( vendor ) {
    if ( !vendor || !vendor.length ) return;
    var rows = '', newest = null;

    vendor.forEach( function ( v ) {
      var qty = ( v.avail === null || v.avail === undefined ) ? 'unknown' : v.avail;
      rows += '<tr style="border-top:0.5px solid var(--ia-border)">'
           +  '<td class="k">' + esc( v.distributor ) + '</td>'
           +  '<td class="n">' + esc( qty ) + '</td></tr>';
      if ( !newest || ( v.checked_at && v.checked_at > newest ) ) newest = v.checked_at;
    } );

    el( 'rim-vendor' ).innerHTML = rows;
    var when = ago( newest );
    el( 'rim-vendor-asof' ).textContent = when
      ? 'Last checked ' + when + '. Distributor stock is a snapshot, not live.'
      : 'Distributor stock is a snapshot, not live.';
    el( 'rim-sec-vendor' ).style.display = '';
  }

  async function open( id, options ) {
    opts = options || {};
    var modal = el( 'rim' );
    modal.style.display = 'flex';
    current = null;
    reset();

    try {
      var r = await fetch( '/admin/register/item/' + encodeURIComponent( id ) + '/info',
        { headers: { 'Accept': 'application/json' } } );
      var d = await r.json();
      if ( !d || !d.ok ) throw new Error( 'bad payload' );

      current = { type: 'product', source_id: id, name: d.name,
                  price_cents: d.price_cents, is_taxable: d.taxable };

      el( 'rim-brand' ).textContent = d.brand || '';
      el( 'rim-name' ).textContent  = d.name || '';
      el( 'rim-sub' ).textContent   = d.subtitle || '';
      el( 'rim-price' ).textContent = money( d.price_cents );

      if ( d.cost && d.cost.cost_cents ) {
        el( 'rim-cost' ).innerHTML = 'cost ' + money( d.cost.cost_cents )
          + ( d.cost.margin_pct !== null ? ' · margin <b>' + d.cost.margin_pct + '%</b>' : '' );
      }

      paintGallery( d.images || [] );

      var here = ( d.stock || [] ).reduce( function ( a, s ) { return a + ( s.count || 0 ); }, 0 );
      var badges = [ '<span class="rim-badge ' + ( here > 0 ? 'ok' : '' ) + '">' + here + ' in stock</span>',
                     '<span class="rim-badge">' + ( d.taxable ? 'taxable' : 'tax exempt' ) + '</span>' ];
      if ( d.sold_30d > 0 ) badges.push( '<span class="rim-badge">sold ' + ( +d.sold_30d ).toFixed( 0 ) + ' in 30d</span>' );
      el( 'rim-badges' ).innerHTML = badges.join( '' );

      if ( d.description ) {
        el( 'rim-desc' ).textContent = d.description;
        el( 'rim-sec-desc' ).style.display = '';
      }

      if ( d.attrs && d.attrs.length ) {
        el( 'rim-attrs' ).innerHTML = d.attrs.map( function ( a ) {
          return '<div class="pair"><span class="k">' + esc( a.name ) + '</span>'
               + '<span class="v">' + esc( a.value ) + '</span></div>';
        } ).join( '' );
        el( 'rim-sec-attrs' ).style.display = '';
      }

      paintVendor( d.vendor );

      var rows = [];
      ( d.stock || [] ).forEach( function ( s ) {
        rows.push( '<td class="k">' + esc( s.location ) + '</td><td class="n">' + s.count + '</td>' );
      } );
      if ( d.sku )      rows.push( '<td class="k">SKU</td><td class="n" style="font-family:ui-monospace,monospace;font-size:12px">' + esc( d.sku ) + '</td>' );
      if ( d.upc )      rows.push( '<td class="k">UPC</td><td class="n" style="font-family:ui-monospace,monospace;font-size:12px">' + esc( d.upc ) + '</td>' );
      if ( d.category ) rows.push( '<td class="k">Category</td><td class="n">' + esc( d.category ) + '</td>' );
      el( 'rim-table' ).innerHTML = rows.map( function ( r2 ) {
        return '<tr style="border-top:0.5px solid var(--ia-border)">' + r2 + '</tr>';
      } ).join( '' );

      el( 'rim-edit' ).href = d.edit_url || '#';

      var add = el( 'rim-add' );
      add.textContent = ( opts.actionLabel || 'Add' ) + ' — ' + money( d.price_cents );
      add.style.display = opts.onAdd ? '' : 'none';
      add.onclick = function () {
        modal.style.display = 'none';
        if ( opts.onAdd ) opts.onAdd( current );
      };
    } catch ( e ) {
      el( 'rim-name' ).textContent = 'Could not load item.';
    }
  }

  window.IntakeItemModal = { open: open };
}() );
</script>
IDM_1_EOF

# the heredoc guards the JS from the shell; restore the IIFE opener
python3 - <<'IDM_2_EOF'
import io
p = 'resources/views/tenant/_item-detail-modal.blade.php'
s = io.open(p, encoding='utf-8').read()
assert s.count('\uff08function () {') == 1
io.open(p, 'w', encoding='utf-8').write(s.replace('\uff08function () {', '( function () {'))
print('partial written')
IDM_2_EOF

# ---------------------------------------------------------------- register
python3 - <<'IDM_3_EOF'
import io
p = 'resources/views/tenant/register/index.blade.php'
s = io.open(p, encoding='utf-8').read()

a = s.index('  .reg-info-btn{flex:none;')
styleStart = s.rindex('<style>', 0, a)
b = s.index('function rimSwap(el) {')
scriptEnd = s.index('</script>', b) + len('</script>')

block = s[styleStart:scriptEnd]
assert 'id="rim"' in block and 'openItemInfo' in block and 'rimSwap' in block, 'unexpected block'
assert block.count('<script>') == 1, block.count('<script>')

replacement = """{{-- MARKER-ITEM-MODAL-SHARED — the item modal moved to a shared partial so
     the appointment part picker can use the same one. --}}
@include('tenant._item-detail-modal')
<script>
// MARKER-ITEM-MODAL-SHARED — thin shim. The register's info button already
// calls openItemInfo(); keeping the name means that call site is untouched.
function openItemInfo( id ) {
  window.IntakeItemModal.open( id, {
    actionLabel: 'Add to sale',
    onAdd: function ( item ) { addToCart( item ); },
  } );
}
</script>"""

s = s[:styleStart] + replacement + s[scriptEnd:]
io.open(p, 'w', encoding='utf-8').write(s)
print('register swapped to partial')
IDM_3_EOF

# ---------------------------------------------------------------- appointment
python3 - <<'IDM_4_EOF'
import io
p = 'resources/views/tenant/appointments/show-multi-asset.blade.php'
s = io.open(p, encoding='utf-8').read()

# include the partial once, right before the closing of the content section
anchor = '@endsection'
first = s.index(anchor)
s = s[:first] + """{{-- MARKER-ITEM-MODAL-SHARED --}}
@include('tenant._item-detail-modal')

""" + s[first:]

# --- both result renderers get the button -------------------------------
old_row = """          html += '<div class="ma-part-picker-result" data-id="' + it.id + '">' +
                  '  <div class="name">' + escapeHtml(it.name) + '</div>' +
                  '  <div class="meta">' +
                  '    <span>' + (it.sku ? escapeHtml(it.sku) + ' · ' : '') + (it.price_display || '$0.00') + '</span>' +
                  '    <span>' + (it.stock > 0 ? it.stock + ' in stock' : (it.allow_oversell ? 'Oversell ok' : 'Out of stock')) + '</span>' +
                  '  </div>' +
                  '</div>';"""
assert s.count(old_row) == 1, s.count(old_row)
new_row = """          // MARKER-ITEM-MODAL-SHARED — "i" opens the shared item modal.
          html += '<div class="ma-part-picker-result" data-id="' + it.id + '">' +
                  '  <div style="display:flex;align-items:center;gap:8px">' +
                  '    <div style="flex:1;min-width:0">' +
                  '      <div class="name">' + escapeHtml(it.name) + '</div>' +
                  '      <div class="meta">' +
                  '        <span>' + (it.sku ? escapeHtml(it.sku) + ' · ' : '') + (it.price_display || '$0.00') + '</span>' +
                  '        <span>' + (it.stock > 0 ? it.stock + ' in stock' : (it.allow_oversell ? 'Oversell ok' : 'Out of stock')) + '</span>' +
                  '      </div>' +
                  '    </div>' +
                  '    <button type="button" class="item-info-btn" data-info-id="' + it.id + '" title="Item details" aria-label="Item details">i</button>' +
                  '  </div>' +
                  '</div>';"""
s = s.replace(old_row, new_row)

old_row2 = """            html += '<div class="ma-part-picker-result" data-id="' + it.id + '">' +"""
assert s.count(old_row2) == 1, s.count(old_row2)
# the second renderer is indented two further spaces; rebuild it the same way
start2 = s.index(old_row2)
end2 = s.index("'</div>';", start2) + len("'</div>';")
block2 = s[start2:end2]
assert 'allow_oversell' in block2, 'unexpected second renderer'
new_row2 = """            // MARKER-ITEM-MODAL-SHARED — "i" opens the shared item modal.
            html += '<div class="ma-part-picker-result" data-id="' + it.id + '">' +
                    '  <div style="display:flex;align-items:center;gap:8px">' +
                    '    <div style="flex:1;min-width:0">' +
                    '      <div class="name">' + escapeHtml(it.name) + '</div>' +
                    '      <div class="meta">' +
                    '        <span>' + (it.sku ? escapeHtml(it.sku) + ' · ' : '') + (it.price_display || '$0.00') + '</span>' +
                    '        <span>' + (it.stock > 0 ? it.stock + ' in stock' : (it.allow_oversell ? 'Oversell ok' : 'Out of stock')) + '</span>' +
                    '      </div>' +
                    '    </div>' +
                    '    <button type="button" class="item-info-btn" data-info-id="' + it.id + '" title="Item details" aria-label="Item details">i</button>' +
                    '  </div>' +
                    '</div>';"""
s = s[:start2] + new_row2 + s[end2:]

# --- wire the buttons on both pickers -----------------------------------
old_wire = """      // Wire selection
      results.querySelectorAll('.ma-part-picker-result').forEach(function(el) {
        el.addEventListener('click', async function() {"""
assert s.count(old_wire) == 1, s.count(old_wire)
new_wire = """      // MARKER-ITEM-MODAL-SHARED — stopPropagation matters: the button sits
      // inside the row, and the row's own click is what adds the part.
      results.querySelectorAll('.item-info-btn').forEach(function(btn) {
        btn.addEventListener('click', function(ev) {
          ev.stopPropagation();
          var row = btn.closest('.ma-part-picker-result');
          window.IntakeItemModal.open(btn.dataset.infoId, {
            actionLabel: 'Add to this bike',
            onAdd: function() { if (row) row.click(); },
          });
        });
      });

      // Wire selection
      results.querySelectorAll('.ma-part-picker-result').forEach(function(el) {
        el.addEventListener('click', async function() {"""
s = s.replace(old_wire, new_wire)

old_wire2 = """        results.querySelectorAll('.ma-part-picker-result').forEach(function(el) {
          el.addEventListener('click', async function() {"""
assert s.count(old_wire2) == 1, s.count(old_wire2)
new_wire2 = """        // MARKER-ITEM-MODAL-SHARED
        results.querySelectorAll('.item-info-btn').forEach(function(btn) {
          btn.addEventListener('click', function(ev) {
            ev.stopPropagation();
            var row = btn.closest('.ma-part-picker-result');
            window.IntakeItemModal.open(btn.dataset.infoId, {
              actionLabel: 'Add to this bike',
              onAdd: function() { if (row) row.click(); },
            });
          });
        });

        results.querySelectorAll('.ma-part-picker-result').forEach(function(el) {
          el.addEventListener('click', async function() {"""
s = s.replace(old_wire2, new_wire2)

io.open(p, 'w', encoding='utf-8').write(s)
print('appointment picker ok')
IDM_4_EOF

php -l app/Http/Controllers/Tenant/RegisterController.php

echo
echo "item-detail-modal-shared applied."
