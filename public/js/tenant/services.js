/**
 * Intake SaaS — Services WYSIWYG Editor
 * Three-panel: tiers | catalog canvas | settings panel
 */
( function () {
  'use strict';

  var d        = window.IntakeSVData || {};
  var catalog  = d.catalog  || [];
  var tiers    = d.tiers    || [];
  var addons   = d.addons   || [];
  var ajaxUrl  = d.ajaxUrl  || '';
  var currency = d.currency || '$';
  var csrf     = d.csrf     || '';

  var sel = null; // { type: 'tier'|'category'|'item', catIdx, itemIdx, tierId }
  var pendingAddCatIdx = null;
  var saveTimer = null;

  // =========================================================================
  // Boot
  // =========================================================================
  document.addEventListener( 'DOMContentLoaded', function () {
    renderTierList();
    renderCatalog();
    bindAddTier();
    bindAddCategory();
  } );

  // =========================================================================
  // Tier list (left panel)
  // =========================================================================
  function renderTierList() {
    var list = document.getElementById( 'sv-tier-list' );
    if ( ! list ) return;
    list.innerHTML = '';

    if ( tiers.length === 0 ) {
      list.innerHTML = '<p style="font-size:13px;opacity:.35;padding:4px">No tiers yet.</p>';
      return;
    }

    tiers.forEach( function ( tier, ti ) {
      var row = mk( 'div', 'sv-tier-row' + ( ! tier.is_active ? ' sv-tier-inactive' : '' ) );
      if ( sel && sel.type === 'tier' && sel.tierId === tier.id ) row.classList.add( 'is-selected' );
      var dot  = mk( 'span', 'sv-tier-dot' );
      var name = mk( 'span', 'sv-tier-name' );
      name.textContent = tier.name;
      row.appendChild( dot );
      row.appendChild( name );
      row.addEventListener( 'click', function () { selectTier( ti ); } );
      list.appendChild( row );
    } );
  }

  function selectTier( ti ) {
    sel = { type: 'tier', tierId: tiers[ ti ].id, tierIdx: ti };
    renderTierList();
    renderTierSettings( ti );
  }

  function renderTierSettings( ti ) {
    var panel = document.getElementById( 'sv-settings-panel' );
    if ( ! panel ) return;
    var tier = tiers[ ti ];
    panel.innerHTML = '';

    field( panel, 'Tier name *', function ( wrap ) {
      var inp = mkInput( tier.name );
      inp.addEventListener( 'input', function () { tier.name = inp.value; } );
      inp.addEventListener( 'change', function () { saveTier( ti ); } );
      wrap.appendChild( inp );
    } );

    toggle( panel, 'Active', tier.is_active, function ( v ) {
      tier.is_active = v;
      saveTier( ti );
    } );

    var delBtn = mk( 'button', 'ia-btn ia-btn--danger ia-btn--sm' );
    delBtn.style.width = '100%';
    delBtn.style.marginTop = '16px';
    delBtn.textContent = 'Delete tier';
    delBtn.addEventListener( 'click', function () {
      if ( ! confirm( 'Delete this tier? Prices for this tier will be removed.' ) ) return;
      ajax( 'DELETE', ajaxUrl + '/' + tier.id, { op: 'delete_tier' }, function () {
        tiers.splice( ti, 1 );
        sel = null;
        renderAll();
        document.getElementById( 'sv-settings-panel' ).innerHTML = '<p class="sv-settings-empty">Select a tier, category, or item to edit.</p>';
      } );
    } );
    panel.appendChild( delBtn );
  }

  function saveTier( ti ) {
    var tier = tiers[ ti ];
    ajax( 'POST', ajaxUrl, { op: 'save_tier', id: tier.id, name: tier.name, is_active: tier.is_active ? 1 : 0 }, function ( data ) {
      if ( data.id && ! tier.id ) { tier.id = data.id; tier.slug = data.slug; }
      setStatus( 'Saved ✓' );
    } );
  }

  function bindAddTier() {
    var btn = document.getElementById( 'sv-add-tier' );
    if ( ! btn ) return;
    btn.addEventListener( 'click', function () {
      var name = prompt( 'Tier name:' );
      if ( ! name ) return;
      ajax( 'POST', ajaxUrl, { op: 'save_tier', name: name, is_active: 1 }, function ( data ) {
        tiers.push( { id: data.id, name: name, slug: data.slug, is_active: true, sort_order: tiers.length } );
        renderTierList();
        setStatus( 'Tier created ✓' );
      } );
    } );
  }

  // =========================================================================
  // Catalog canvas (center panel)
  // =========================================================================
  function renderCatalog() {
    var canvas = document.getElementById( 'sv-catalog' );
    if ( ! canvas ) return;
    canvas.innerHTML = '';

    if ( catalog.length === 0 ) {
      canvas.innerHTML = '<p style="font-size:13px;opacity:.35;padding:8px">No categories yet. Click "+ Category" to start.</p>';
      return;
    }

    catalog.forEach( function ( cat, ci ) {
      var section = mk( 'div', 'sv-cat-section' );

      // Category header
      var head   = mk( 'div', 'sv-cat-head' );
      var catName = mk( 'span', 'sv-cat-name' );
      catName.textContent = cat.name;
      catName.addEventListener( 'click', function () { selectCategory( ci ); } );

      var addBtn = mk( 'button', 'ia-btn ia-btn--ghost ia-btn--sm' );
      addBtn.textContent = '+ Item';
      addBtn.addEventListener( 'click', function () { promptAddItem( ci ); } );

      head.appendChild( catName );
      head.appendChild( addBtn );
      section.appendChild( head );

      // Item grid
      var grid = mk( 'div', 'sv-item-grid' );
      grid.setAttribute( 'data-cat-idx', ci );

      cat.items.forEach( function ( item, ii ) {
        var card = buildItemCard( ci, ii, item );
        grid.appendChild( card );
      } );

      // Add item card
      var addCard = mk( 'div', 'sv-add-card' );
      addCard.textContent = '+ Add item';
      addCard.addEventListener( 'click', function () { promptAddItem( ci ); } );
      grid.appendChild( addCard );

      section.appendChild( grid );
      canvas.appendChild( section );

      makeSortable( grid, ci );
    } );
  }

  function buildItemCard( ci, ii, item ) {
    var card = mk( 'div', 'sv-item-card' + ( ! item.is_active ? ' is-inactive' : '' ) );
    card.setAttribute( 'data-item-idx', ii );
    if ( sel && sel.type === 'item' && sel.catIdx === ci && sel.itemIdx === ii ) {
      card.classList.add( 'is-selected' );
    }

    var nameEl = mk( 'div', 'sv-item-name' );
    nameEl.textContent = item.name;

    var priceEl = mk( 'div', 'sv-item-price' );
    var prices = tiers.map( function ( t ) {
      var c = item.tier_prices[ t.id ];
      return c != null ? t.name + ': ' + fmtMoney( c ) : null;
    } ).filter( Boolean );
    priceEl.textContent = prices.length ? prices[ 0 ] : 'No price set';

    card.appendChild( nameEl );
    card.appendChild( priceEl );
    card.addEventListener( 'click', function ( e ) {
      if ( e.target.classList.contains( 'sv-drag-handle' ) ) return;
      selectItem( ci, ii );
    } );
    return card;
  }

  function selectCategory( ci ) {
    sel = { type: 'category', catIdx: ci };
    renderCatalog();
    renderCategorySettings( ci );
  }

  function selectItem( ci, ii ) {
    sel = { type: 'item', catIdx: ci, itemIdx: ii };
    renderCatalog();
    renderItemSettings( ci, ii );
  }

  function promptAddItem( ci ) {
    if ( tiers.length === 0 ) { alert( 'Create at least one tier before adding items.' ); return; }
    var name = prompt( 'Item name:' );
    if ( ! name ) return;
    ajax( 'POST', ajaxUrl, {
      op: 'save_item', name: name, category_id: catalog[ ci ].id, is_active: 1,
    }, function ( data ) {
      catalog[ ci ].items.push( {
        id: data.id, name: name, slug: data.slug, description: '',
        image_url: '', is_active: true, sort_order: catalog[ ci ].items.length, tier_prices: {},
      } );
      var newIdx = catalog[ ci ].items.length - 1;
      renderCatalog();
      selectItem( ci, newIdx );
      setStatus( 'Item created ✓' );
    } );
  }

  function bindAddCategory() {
    var btn = document.getElementById( 'sv-add-category' );
    if ( ! btn ) return;
    btn.addEventListener( 'click', function () {
      var name = prompt( 'Category name:' );
      if ( ! name ) return;
      ajax( 'POST', ajaxUrl, { op: 'save_category', name: name, is_active: 1 }, function ( data ) {
        catalog.push( { id: data.id, name: name, slug: data.slug, is_active: true, sort_order: catalog.length, items: [] } );
        renderCatalog();
        selectCategory( catalog.length - 1 );
        setStatus( 'Category created ✓' );
      } );
    } );
  }

  // =========================================================================
  // Settings panels
  // =========================================================================
  function renderCategorySettings( ci ) {
    var panel = document.getElementById( 'sv-settings-panel' );
    if ( ! panel ) return;
    var cat = catalog[ ci ];
    panel.innerHTML = '';

    field( panel, 'Category name *', function ( wrap ) {
      var inp = mkInput( cat.name );
      inp.addEventListener( 'input', function () { cat.name = inp.value; } );
      inp.addEventListener( 'change', function () {
        ajax( 'POST', ajaxUrl, { op: 'save_category', id: cat.id, name: cat.name, is_active: cat.is_active ? 1 : 0 }, function () {
          setStatus( 'Saved ✓' ); renderCatalog();
        } );
      } );
      wrap.appendChild( inp );
    } );

    toggle( panel, 'Visible on booking form', cat.is_active, function ( v ) {
      cat.is_active = v;
      ajax( 'POST', ajaxUrl, { op: 'save_category', id: cat.id, name: cat.name, is_active: v ? 1 : 0 }, function () {
        setStatus( 'Saved ✓' ); renderCatalog();
      } );
    } );

    var delBtn = mk( 'button', 'ia-btn ia-btn--danger ia-btn--sm' );
    delBtn.style.width = '100%'; delBtn.style.marginTop = '16px';
    delBtn.textContent = 'Delete category';
    delBtn.addEventListener( 'click', function () {
      if ( ! confirm( 'Delete this category and all its items?' ) ) return;
      ajax( 'DELETE', ajaxUrl + '/' + cat.id, { op: 'delete_category' }, function () {
        catalog.splice( ci, 1 ); sel = null; renderAll();
        panel.innerHTML = '<p class="sv-settings-empty">Select a tier, category, or item to edit.</p>';
      } );
    } );
    panel.appendChild( delBtn );
  }

  function renderItemSettings( ci, ii ) {
    var panel = document.getElementById( 'sv-settings-panel' );
    if ( ! panel ) return;
    var item = catalog[ ci ].items[ ii ];
    panel.innerHTML = '';

    field( panel, 'Item name *', function ( wrap ) {
      var inp = mkInput( item.name );
      inp.addEventListener( 'input', function () { item.name = inp.value; } );
      inp.addEventListener( 'change', function () { saveItem( ci, ii ); } );
      wrap.appendChild( inp );
    } );

    field( panel, 'Description', function ( wrap ) {
      var ta = document.createElement( 'textarea' );
      ta.className = 'ia-input'; ta.rows = 3; ta.style.resize = 'vertical';
      ta.value = item.description || '';
      ta.addEventListener( 'input', function () { item.description = ta.value; } );
      ta.addEventListener( 'change', function () { saveItem( ci, ii ); } );
      wrap.appendChild( ta );
    } );

    // Tier prices
    if ( tiers.length > 0 ) {
      var pricesLabel = mk( 'div', 'sv-settings-label' );
      pricesLabel.textContent = 'Pricing by tier';
      pricesLabel.style.marginTop = '8px';
      panel.appendChild( pricesLabel );

      tiers.forEach( function ( tier ) {
        var row = mk( 'div', 'sv-tier-price-row' );
        var lbl = mk( 'span', 'sv-tier-price-label' );
        lbl.textContent = tier.name;
        var inp = document.createElement( 'input' );
        inp.type = 'number'; inp.min = '0'; inp.step = '1';
        inp.className = 'sv-tier-price-input';
        inp.placeholder = 'No price';
        var cents = item.tier_prices[ tier.id ];
        inp.value = cents != null ? ( cents / 100 ).toFixed( 2 ) : '';
        inp.addEventListener( 'change', function () {
          var val = inp.value.trim();
          item.tier_prices[ tier.id ] = val === '' ? null : Math.round( parseFloat( val ) * 100 );
          saveItem( ci, ii );
        } );
        row.appendChild( lbl );
        row.appendChild( inp );
        panel.appendChild( row );
      } );
    }

    toggle( panel, 'Visible on booking form', item.is_active, function ( v ) {
      item.is_active = v;
      saveItem( ci, ii );
    } );

    var delBtn = mk( 'button', 'ia-btn ia-btn--danger ia-btn--sm' );
    delBtn.style.width = '100%'; delBtn.style.marginTop = '16px';
    delBtn.textContent = 'Delete item';
    delBtn.addEventListener( 'click', function () {
      if ( ! confirm( 'Delete this item?' ) ) return;
      ajax( 'DELETE', ajaxUrl + '/' + item.id, { op: 'delete_item' }, function () {
        catalog[ ci ].items.splice( ii, 1 ); sel = null; renderAll();
        panel.innerHTML = '<p class="sv-settings-empty">Select a tier, category, or item to edit.</p>';
      } );
    } );
    panel.appendChild( delBtn );
  }

  function saveItem( ci, ii ) {
    var item = catalog[ ci ].items[ ii ];
    var payload = {
      op: 'save_item', id: item.id, name: item.name,
      category_id: catalog[ ci ].id,
      description: item.description || '',
      is_active: item.is_active ? 1 : 0,
    };
    // Attach tier_prices as tier_prices[id]=cents
    Object.keys( item.tier_prices ).forEach( function ( tid ) {
      var c = item.tier_prices[ tid ];
      payload[ 'tier_prices[' + tid + ']' ] = c != null ? c : '';
    } );
    ajax( 'POST', ajaxUrl, payload, function () {
      setStatus( 'Saved ✓' ); renderCatalog();
    } );
  }

  // =========================================================================
  // Drag and drop (items within a category)
  // =========================================================================
  function makeSortable( container, ci ) {
    container.querySelectorAll( '.sv-item-card' ).forEach( function ( card ) {
      card.addEventListener( 'mousedown', function ( e ) {
        if ( e.target.tagName === 'BUTTON' ) return;
        e.preventDefault();
        card.classList.add( 'is-dragging' );
        var onMove = function ( e2 ) {
          var target = document.elementFromPoint( e2.clientX, e2.clientY );
          var targetCard = target ? target.closest( '.sv-item-card' ) : null;
          if ( targetCard && targetCard !== card && targetCard.parentNode === container ) {
            var all  = Array.from( container.querySelectorAll( '.sv-item-card' ) );
            var di   = all.indexOf( card );
            var oi   = all.indexOf( targetCard );
            if ( di < oi ) container.insertBefore( card, targetCard.nextSibling );
            else           container.insertBefore( card, targetCard );
          }
        };
        var onUp = function () {
          document.removeEventListener( 'mousemove', onMove );
          document.removeEventListener( 'mouseup', onUp );
          card.classList.remove( 'is-dragging' );
          saveItemOrder( ci, container );
        };
        document.addEventListener( 'mousemove', onMove );
        document.addEventListener( 'mouseup', onUp );
      } );
    } );
  }

  function saveItemOrder( ci, container ) {
    var order = [];
    var newItems = [];
    container.querySelectorAll( '.sv-item-card' ).forEach( function ( card ) {
      var ii    = parseInt( card.getAttribute( 'data-item-idx' ), 10 );
      var item  = catalog[ ci ].items[ ii ];
      if ( item ) { order.push( item.id ); newItems.push( item ); }
    } );
    catalog[ ci ].items = newItems;
    if ( order.length ) {
      ajax( 'POST', ajaxUrl, { op: 'reorder_items', item_order: order.join( ',' ) }, null );
    }
  }

  // =========================================================================
  // Helpers
  // =========================================================================
  function renderAll() { renderTierList(); renderCatalog(); }

  function setStatus( msg ) {
    var el = document.getElementById( 'sv-status' );
    if ( el ) { el.textContent = msg; clearTimeout( saveTimer ); saveTimer = setTimeout( function () { el.textContent = ''; }, 2500 ); }
  }

  function fmtMoney( cents ) {
    return currency + ( cents / 100 ).toFixed( 2 );
  }

  function mk( tag, cls ) {
    var el = document.createElement( tag );
    if ( cls ) el.className = cls;
    return el;
  }

  function mkInput( val ) {
    var inp = document.createElement( 'input' );
    inp.type = 'text'; inp.className = 'ia-input'; inp.value = val || '';
    return inp;
  }

  function field( parent, label, builder ) {
    var wrap = mk( 'div', 'sv-settings-field' );
    var lbl  = mk( 'div', 'sv-settings-label' );
    lbl.textContent = label;
    wrap.appendChild( lbl );
    builder( wrap );
    parent.appendChild( wrap );
  }

  function toggle( parent, label, active, onChange ) {
    var row = mk( 'div', 'sv-toggle-row' );
    var lbl = mk( 'span', 'sv-toggle-label' );
    lbl.textContent = label;
    var btn = mk( 'button', 'sv-toggle' + ( active ? ' on' : '' ) );
    btn.setAttribute( 'type', 'button' );
    btn.addEventListener( 'click', function () {
      var v = ! btn.classList.contains( 'on' );
      btn.classList.toggle( 'on', v );
      onChange( v );
    } );
    row.appendChild( lbl );
    row.appendChild( btn );
    parent.appendChild( row );
  }

  function ajax( method, url, data, callback ) {
    var fd = new FormData();
    fd.append( '_token', csrf );
    if ( method === 'DELETE' ) { fd.append( '_method', 'DELETE' ); method = 'POST'; }
    Object.keys( data ).forEach( function ( k ) {
      if ( Array.isArray( data[ k ] ) ) {
        data[ k ].forEach( function ( v ) { fd.append( k + '[]', v ); } );
      } else if ( data[ k ] != null ) {
        fd.append( k, data[ k ] );
      }
    } );
    fetch( url, { method: method, body: fd, headers: { 'X-Requested-With': 'XMLHttpRequest' } } )
      .then( function ( r ) { return r.json(); } )
      .then( function ( resp ) { if ( callback ) callback( resp ); } )
      .catch( function ( err ) { console.error( 'Services ajax error:', err ); } );
  }

}() );
