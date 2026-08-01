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
      {{-- MARKER-SOURCING-TABLE — vendors, then the shop's own stock, then the
           identifiers that belong to the product rather than to a vendor. --}}
      <div class="rim-sec"><h3>Sourcing &amp; stock</h3>
        <table id="rim-vendor"></table>
        <table id="rim-table"></table>
        <div class="rim-asof" id="rim-vendor-asof"></div>
      </div>
    </div>
    <div class="rim-foot">
      <a class="ia-btn ia-btn--ghost" id="rim-edit" href="#" style="text-decoration:none">Edit item</a>
      <button type="button" class="ia-btn ia-btn--ghost" onclick="document.getElementById('rim').style.display='none'">Close</button>
      <button type="button" class="ia-btn ia-btn--primary grow" id="rim-add">Add</button>
    </div>
  </div>
</div>
<script>
( function () {
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

  // MARKER-MODAL-ALL-VENDORS — one line per distributor that carries the item.
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
         + rows;

    el( 'rim-vendor' ).innerHTML = rows;
    var when = ago( newest );
    el( 'rim-vendor-asof' ).textContent = when
      ? 'Last checked ' + when + '. Distributor stock is a snapshot, not live.'
      : 'Distributor stock is a snapshot, not live.';
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

      // MARKER-SOURCING-TABLE — the shop's own stock and the identifiers that
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
