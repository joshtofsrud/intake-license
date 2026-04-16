/**
 * Intake SaaS — Capacity Editor JS
 * Two-panel: 7-day defaults with spinners + proportional bars | date overrides
 */
( function () {
  'use strict';

  var d         = window.IntakeCapData || {};
  var defaults  = d.defaults  || [];   // [{ id, day, max }, ...]
  var overrides = d.overrides || [];   // [{ id, date, max, note }, ...]
  var ajaxUrl   = d.ajaxUrl   || '';
  var csrf      = d.csrf      || '';

  var DAY_NAMES = [ 'Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday' ];

  document.addEventListener( 'DOMContentLoaded', function () {
    renderDays();
    renderOverrides();
    bindSave();
    bindAddOverride();
  } );

  // =========================================================================
  // 7-day defaults
  // =========================================================================
  function renderDays() {
    var container = document.getElementById( 'cap-days' );
    if ( ! container ) return;
    container.innerHTML = '';

    var maxVal = Math.max.apply( null, defaults.map( function ( d ) { return d.max; } ).concat( [ 1 ] ) );

    defaults.forEach( function ( rule ) {
      var card = document.createElement( 'div' );
      card.className = 'cap-day-card';

      var name = document.createElement( 'div' );
      name.className = 'cap-day-name';
      name.textContent = DAY_NAMES[ rule.day ] || 'Day ' + rule.day;

      var barWrap = document.createElement( 'div' );
      barWrap.className = 'cap-bar-wrap';
      var bar = document.createElement( 'div' );
      bar.className = 'cap-bar';
      bar.style.width = ( rule.max / maxVal * 100 ).toFixed( 1 ) + '%';
      barWrap.appendChild( bar );

      var spinner = document.createElement( 'div' );
      spinner.className = 'cap-spinner';

      var minusBtn = document.createElement( 'button' );
      minusBtn.className = 'cap-spinner-btn';
      minusBtn.textContent = '−';
      minusBtn.type = 'button';

      var valEl = document.createElement( 'span' );
      valEl.className = 'cap-spinner-val';
      valEl.textContent = rule.max;

      var plusBtn = document.createElement( 'button' );
      plusBtn.className = 'cap-spinner-btn';
      plusBtn.textContent = '+';
      plusBtn.type = 'button';

      var slotLabel = document.createElement( 'span' );
      slotLabel.className = 'cap-slot-label';
      slotLabel.textContent = rule.max === 1 ? '1 slot' : rule.max + ' slots';

      function update( newVal ) {
        rule.max = Math.max( 0, newVal );
        valEl.textContent = rule.max;
        slotLabel.textContent = rule.max === 1 ? '1 slot' : rule.max + ' slots';
        var newMax = Math.max.apply( null, defaults.map( function ( d ) { return d.max; } ).concat( [ 1 ] ) );
        // Update all bars proportionally
        document.querySelectorAll( '.cap-bar' ).forEach( function ( b, i ) {
          b.style.width = ( defaults[ i ].max / newMax * 100 ).toFixed( 1 ) + '%';
        } );
      }

      minusBtn.addEventListener( 'click', function () { update( rule.max - 1 ); } );
      plusBtn.addEventListener( 'click',  function () { update( rule.max + 1 ); } );

      spinner.appendChild( minusBtn );
      spinner.appendChild( valEl );
      spinner.appendChild( plusBtn );

      card.appendChild( name );
      card.appendChild( barWrap );
      card.appendChild( spinner );
      card.appendChild( slotLabel );
      container.appendChild( card );
    } );
  }

  function bindSave() {
    var btn = document.getElementById( 'cap-save-btn' );
    if ( ! btn ) return;
    btn.addEventListener( 'click', function () {
      btn.disabled = true;
      btn.textContent = 'Saving…';

      var payload = { op: 'save_defaults' };
      defaults.forEach( function ( rule ) {
        payload[ 'days[' + rule.day + ']' ] = rule.max;
      } );

      post( payload, function ( resp ) {
        btn.disabled = false;
        btn.textContent = 'Save defaults';
        setStatus( resp.success ? 'Saved ✓' : 'Error saving.' );
      } );
    } );
  }

  // =========================================================================
  // Date overrides
  // =========================================================================
  function renderOverrides() {
    var list = document.getElementById( 'ov-list' );
    if ( ! list ) return;
    list.innerHTML = '';

    if ( overrides.length === 0 ) {
      list.innerHTML = '<p style="font-size:13px;opacity:.4">No date overrides yet.</p>';
      return;
    }

    overrides.forEach( function ( ov ) {
      list.appendChild( buildOverrideRow( ov ) );
    } );
  }

  function buildOverrideRow( ov ) {
    var row = document.createElement( 'div' );
    row.className = 'cap-override-row';
    row.setAttribute( 'data-id', ov.id );

    var dateEl = document.createElement( 'div' );
    dateEl.className = 'cap-override-date';
    dateEl.textContent = formatDate( ov.date );

    var noteEl = document.createElement( 'div' );
    noteEl.className = 'cap-override-note';
    noteEl.style.flex = '1';
    noteEl.textContent = ov.note || '';

    var maxEl = document.createElement( 'div' );
    maxEl.className = 'cap-override-max';
    maxEl.textContent = ov.max + ' slots';

    var delBtn = document.createElement( 'button' );
    delBtn.className = 'ia-btn ia-btn--ghost ia-btn--sm ia-btn--icon';
    delBtn.type = 'button';
    delBtn.title = 'Delete override';
    delBtn.innerHTML = '&#x2715;';
    delBtn.addEventListener( 'click', function () {
      post( { op: 'delete_override', id: ov.id }, function ( resp ) {
        if ( resp.success ) {
          row.remove();
          overrides = overrides.filter( function ( o ) { return o.id !== ov.id; } );
          if ( overrides.length === 0 ) {
            document.getElementById( 'ov-list' ).innerHTML = '<p style="font-size:13px;opacity:.4">No date overrides yet.</p>';
          }
        }
      } );
    } );

    row.appendChild( dateEl );
    row.appendChild( noteEl );
    row.appendChild( maxEl );
    row.appendChild( delBtn );
    return row;
  }

  function bindAddOverride() {
    var addBtn  = document.getElementById( 'ov-add-btn' );
    var dateInp = document.getElementById( 'ov-date' );
    var maxInp  = document.getElementById( 'ov-max' );
    var noteInp = document.getElementById( 'ov-note' );
    var errEl   = document.getElementById( 'ov-error' );
    var list    = document.getElementById( 'ov-list' );

    if ( ! addBtn ) return;

    addBtn.addEventListener( 'click', function () {
      var date = dateInp.value;
      var max  = parseInt( maxInp.value, 10 );

      if ( ! date ) { showErr( errEl, 'Please select a date.' ); return; }
      if ( isNaN( max ) || max < 0 ) { showErr( errEl, 'Max bookings must be 0 or more.' ); return; }
      hideErr( errEl );

      addBtn.disabled = true;
      addBtn.textContent = 'Saving…';

      post( {
        op:   'save_override',
        date: date,
        max:  max,
        note: noteInp.value.trim(),
      }, function ( resp ) {
        addBtn.disabled  = false;
        addBtn.textContent = 'Add override';

        if ( ! resp.success ) { showErr( errEl, resp.message || 'Error.' ); return; }

        // Remove empty state
        var empty = list.querySelector( 'p' );
        if ( empty ) empty.remove();

        // Add row (insert sorted by date)
        var newOv = { id: resp.id, date: resp.date, max: resp.max, note: resp.note };
        overrides.push( newOv );
        overrides.sort( function ( a, b ) { return a.date.localeCompare( b.date ); } );
        list.innerHTML = '';
        overrides.forEach( function ( ov ) { list.appendChild( buildOverrideRow( ov ) ); } );

        // Reset form
        dateInp.value  = '';
        maxInp.value   = '0';
        noteInp.value  = '';
      } );
    } );
  }

  // =========================================================================
  // Helpers
  // =========================================================================
  function formatDate( dateStr ) {
    try {
      var d = new Date( dateStr + 'T00:00:00' );
      return d.toLocaleDateString( undefined, { weekday: 'short', month: 'short', day: 'numeric', year: 'numeric' } );
    } catch ( e ) { return dateStr; }
  }

  function setStatus( msg ) {
    var el = document.getElementById( 'cap-status' );
    if ( el ) el.textContent = msg;
  }

  function showErr( el, msg ) { if ( el ) { el.textContent = msg; el.style.display = ''; } }
  function hideErr( el )       { if ( el ) el.style.display = 'none'; }

  function post( data, callback ) {
    var fd = new FormData();
    fd.append( '_token', csrf );
    Object.keys( data ).forEach( function ( k ) { fd.append( k, data[ k ] ); } );
    fetch( ajaxUrl, { method: 'POST', body: fd, headers: { 'X-Requested-With': 'XMLHttpRequest' } } )
      .then( function ( r ) { return r.json(); } )
      .then( callback )
      .catch( function ( err ) { console.error( 'Capacity ajax error:', err ); } );
  }

}() );
