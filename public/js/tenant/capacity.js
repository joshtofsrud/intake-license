/**
 * Capacity admin — booking-mode-aware weekly defaults + date overrides.
 *
 * Driven by window.CAP_BOOT (set by the Blade via @push). All saves go to
 * the canonical tenant.capacity.store endpoint via op-dispatch:
 *    op=save_defaults       (weekly grid)
 *    op=save_override       (date one-off)
 *    op=delete_override     (remove date one-off)
 *
 * No more dependence on the broken IntakeAdmin.ajaxUrl global.
 *
 * Save shape (op=save_defaults):
 *    days[d][is_closed]              boolean
 *    days[d][open_time]              "HH:MM" (omitted when closed)
 *    days[d][close_time]             "HH:MM" (omitted when closed)
 *    days[d][slot_interval_minutes]  integer (omitted when closed)
 *    days[d][max]                    integer or empty (NULL = no shop override)
 */
( function () {
  'use strict';

  var boot = window.CAP_BOOT;
  if ( !boot ) {
    console.warn( 'capacity.js: CAP_BOOT missing — page boot data not provided' );
    return;
  }

  var DAY_LABELS = [ 'Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat' ];
  var DAY_LONG   = [ 'Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday' ];

  // ===================================================================
  // Boot
  // ===================================================================
  document.addEventListener( 'DOMContentLoaded', function () {
    renderDefaults();
    renderOverrides();
    bindAdvancedToggle();
    bindOverrideModal();
  } );

  // ===================================================================
  // Weekly defaults
  // ===================================================================
  function renderDefaults() {
    var list = document.getElementById( 'cap-defaults-list' );
    if ( !list ) return;

    var days = boot.defaults || [];
    list.innerHTML = days.map( renderDayRow ).join( '' );

    // Wire the closed-toggle checkbox per row
    list.querySelectorAll( '[data-cap-closed-toggle]' ).forEach( function ( cb ) {
      cb.addEventListener( 'change', function () {
        var row = cb.closest( '.cap-day-row' );
        if ( cb.checked ) row.classList.add( 'is-closed' );
        else               row.classList.remove( 'is-closed' );
        scheduleSave();
      } );
    } );

    // Wire any input change to a debounced save.
    // 'input' catches the clear-to-empty case that 'change' sometimes misses
    // on number inputs across browsers; 'blur' catches navigation-away.
    list.querySelectorAll( 'input' ).forEach( function ( input ) {
      input.addEventListener( 'input',  scheduleSave );
      input.addEventListener( 'change', scheduleSave );
      input.addEventListener( 'blur',   scheduleSave );
    } );
  }

  function renderDayRow( day ) {
    var d = day.day;
    var closed = !!day.is_closed;
    var maxVal = ( day.max === null || day.max === undefined ) ? '' : day.max;
    var isDropOff = boot.mode === 'drop_off';

    // Mode-aware fields:
    //  - drop_off mode: show "Daily cap" field. Field overrides the resource-cap-sum
    //    ceiling. Blank = use resource-cap-sum naturally.
    //  - time_slots mode: show "Slot interval" field. The grid math (open/close/interval
    //    × resources) governs primary capacity, so the daily cap field is hidden by
    //    default — it's an advanced override exposed via Show advanced toggle.
    var capField = isDropOff
      ? ''
        + '<div class="cap-day-max cap-day-fields-when-open">'
        +   '<input type="number" min="0" placeholder="No limit" data-field="max" data-day="' + d + '" value="' + maxVal + '" title="Optional daily cap. Leave blank to use the sum of staff caps from your Resources page.">'
        + '</div>'
      : '<div class="cap-day-max cap-day-fields-when-open cap-day-advanced-only">'
        +   '<input type="number" min="0" placeholder="No override" data-field="max" data-day="' + d + '" value="' + maxVal + '" title="Optional override on top of grid capacity. Rarely needed in time-slot mode.">'
        + '</div>';

    var intervalField = isDropOff
      ? '<div class="cap-day-interval cap-day-fields-when-open cap-day-advanced-only">'
        +   '<input type="number" min="5" max="240" step="5" data-field="slot_interval_minutes" data-day="' + d + '" value="' + ( day.slot_interval_minutes || 60 ) + '" title="Slot interval. Drop-off mode does not use this; visible under Advanced for completeness.">'
        + '</div>'
      : '<div class="cap-day-interval cap-day-fields-when-open">'
        +   '<input type="number" min="5" max="240" step="5" data-field="slot_interval_minutes" data-day="' + d + '" value="' + ( day.slot_interval_minutes || 60 ) + '" title="How long each bookable slot is, in minutes. Determines how many slots fit in the day.">'
        + '</div>';

    return ''
      + '<div class="cap-day-row ' + ( closed ? 'is-closed' : '' ) + '" data-day="' + d + '">'
      +   '<div class="cap-day-label">' + DAY_LONG[ d ] + '</div>'
      +   '<label class="cap-day-toggle">'
      +     '<input type="checkbox" data-cap-closed-toggle ' + ( closed ? 'checked' : '' ) + ' data-field="is_closed" data-day="' + d + '">'
      +     '<span>Closed</span>'
      +   '</label>'
      +   '<div class="cap-day-time cap-day-fields-when-open">'
      +     '<input type="time" data-field="open_time" data-day="' + d + '" value="' + ( day.open_time || '09:00' ) + '">'
      +     '<span>to</span>'
      +     '<input type="time" data-field="close_time" data-day="' + d + '" value="' + ( day.close_time || '17:00' ) + '">'
      +   '</div>'
      +   '<div class="cap-day-fields-when-closed">— closed —</div>'
      +   capField
      +   intervalField
      + '</div>';
  }

  function bindAdvancedToggle() {
    var btn = document.getElementById( 'cap-toggle-advanced' );
    if ( !btn ) return;
    btn.addEventListener( 'click', function () {
      var rows = document.querySelectorAll( '.cap-day-row' );
      var hide = btn.querySelector( '[data-when-hidden]' );
      var show = btn.querySelector( '[data-when-shown]' );
      var nowShown = rows.length && rows[ 0 ].dataset.showAdvanced === '1';
      rows.forEach( function ( row ) {
        row.dataset.showAdvanced = nowShown ? '0' : '1';
      } );
      if ( hide ) hide.style.display = nowShown ? ''      : 'none';
      if ( show ) show.style.display = nowShown ? 'none'  : '';
    } );
  }

  // Debounced save — collects all 7 days and posts as save_defaults.
  var saveTimer = null;
  function scheduleSave() {
    if ( saveTimer ) clearTimeout( saveTimer );
    saveTimer = setTimeout( saveDefaults, 600 );
  }

  function saveDefaults() {
    var fd = new FormData();
    fd.append( '_token', boot.csrf );
    fd.append( 'op', 'save_defaults' );

    document.querySelectorAll( '.cap-day-row' ).forEach( function ( row ) {
      var d = row.dataset.day;
      var closedInput = row.querySelector( '[data-field="is_closed"]' );
      var isClosed    = closedInput && closedInput.checked;
      fd.append( 'days[' + d + '][is_closed]', isClosed ? '1' : '0' );
      if ( !isClosed ) {
        row.querySelectorAll( '[data-field]' ).forEach( function ( input ) {
          var field = input.dataset.field;
          if ( field === 'is_closed' ) return;
          fd.append( 'days[' + d + '][' + field + ']', input.value );
        } );
      }
    } );

    setStatus( 'Saving…' );
    fetch( boot.saveUrl, {
      method:  'POST',
      body:    fd,
      headers: { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' },
    } )
      .then( function ( r ) { return r.json(); } )
      .then( function ( resp ) {
        if ( resp && resp.success ) setStatus( 'Saved' );
        else                        setStatus( 'Save failed', true );
      } )
      .catch( function ( err ) {
        console.error( 'Capacity save error:', err );
        setStatus( 'Save failed', true );
      } );
  }

  // ===================================================================
  // Date overrides
  // ===================================================================
  function renderOverrides() {
    var list = document.getElementById( 'cap-overrides-list' );
    if ( !list ) return;

    var overrides = boot.overrides || [];
    var empty = document.getElementById( 'cap-override-empty' );

    if ( overrides.length === 0 ) {
      list.innerHTML = '';
      if ( empty ) empty.style.display = '';
      return;
    }
    if ( empty ) empty.style.display = 'none';

    list.innerHTML = overrides.map( function ( ov ) {
      var dateLabel = formatDate( ov.date );
      var statusBadge = ov.is_closed
        ? '<span class="cap-override-status closed">Closed</span>'
        : '<span class="cap-override-status cap">Cap</span>';
      var capDisplay = ov.is_closed
        ? '—'
        : ( ov.max === null || ov.max === undefined ? 'Resource sum' : ov.max + ' max' );

      return ''
        + '<div class="cap-override-row" data-id="' + ov.id + '">'
        +   '<div><div class="cap-override-date">' + dateLabel + '</div></div>'
        +   '<div>' + statusBadge + '</div>'
        +   '<div class="cap-override-note">' + ( ov.note ? escapeHtml( ov.note ) : '' ) + '</div>'
        +   '<div class="cap-override-cap-display">' + capDisplay + '</div>'
        +   '<button type="button" class="ia-btn ia-btn--ghost ia-btn--sm" data-cap-delete-override="' + ov.id + '" title="Remove override">×</button>'
        + '</div>';
    } ).join( '' );

    list.querySelectorAll( '[data-cap-delete-override]' ).forEach( function ( btn ) {
      btn.addEventListener( 'click', function () {
        var id = btn.dataset.capDeleteOverride;
        if ( !confirm( 'Remove this date override?' ) ) return;
        deleteOverride( id );
      } );
    } );
  }

  function bindOverrideModal() {
    var addBtn  = document.getElementById( 'cap-add-override-btn' );
    var modal   = document.getElementById( 'cap-override-modal' );
    var closeBtn = document.getElementById( 'cap-override-close' );
    var cancelBtn = document.getElementById( 'cap-override-cancel' );
    var saveBtn   = document.getElementById( 'cap-override-save' );
    var dateInput = document.getElementById( 'ov-date' );
    var closedCb  = document.getElementById( 'ov-is-closed' );
    var maxInput  = document.getElementById( 'ov-max' );
    var maxGroup  = document.getElementById( 'ov-max-group' );
    var noteInput = document.getElementById( 'ov-note' );

    if ( !addBtn || !modal ) return;

    function openModal() {
      dateInput.value = '';
      closedCb.checked = false;
      maxInput.value = '';
      noteInput.value = '';
      maxGroup.style.display = '';
      modal.style.display = '';
    }
    function closeModal() {
      modal.style.display = 'none';
    }

    closedCb.addEventListener( 'change', function () {
      maxGroup.style.display = closedCb.checked ? 'none' : '';
    } );

    addBtn.addEventListener( 'click', openModal );
    closeBtn.addEventListener( 'click', closeModal );
    cancelBtn.addEventListener( 'click', closeModal );
    document.querySelector( '#cap-override-modal .cap-modal-back' )
      .addEventListener( 'click', closeModal );

    saveBtn.addEventListener( 'click', function () {
      if ( !dateInput.value ) {
        alert( 'Please pick a date.' );
        return;
      }
      var fd = new FormData();
      fd.append( '_token', boot.csrf );
      fd.append( 'op', 'save_override' );
      fd.append( 'date', dateInput.value );
      fd.append( 'is_closed', closedCb.checked ? '1' : '0' );
      if ( !closedCb.checked && maxInput.value !== '' ) {
        fd.append( 'max', maxInput.value );
      }
      fd.append( 'note', noteInput.value || '' );

      fetch( boot.saveUrl, {
        method: 'POST',
        body: fd,
        headers: { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' },
      } )
        .then( function ( r ) { return r.json(); } )
        .then( function ( resp ) {
          if ( resp && resp.success ) {
            // Push the new/updated override into boot state and re-render.
            var existing = ( boot.overrides || [] ).filter( function ( o ) {
              return o.date !== resp.date;
            } );
            existing.push( {
              id: resp.id,
              date: resp.date,
              max: resp.max,
              is_closed: !!resp.is_closed,
              note: resp.note,
            } );
            existing.sort( function ( a, b ) { return a.date.localeCompare( b.date ); } );
            boot.overrides = existing;
            renderOverrides();
            closeModal();
            setStatus( 'Saved' );
          } else {
            setStatus( 'Save failed', true );
          }
        } )
        .catch( function ( err ) {
          console.error( 'Override save error:', err );
          setStatus( 'Save failed', true );
        } );
    } );
  }

  function deleteOverride( id ) {
    var fd = new FormData();
    fd.append( '_token', boot.csrf );
    fd.append( 'op', 'delete_override' );
    fd.append( 'id', id );

    fetch( boot.saveUrl, {
      method: 'POST',
      body: fd,
      headers: { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' },
    } )
      .then( function ( r ) { return r.json(); } )
      .then( function ( resp ) {
        if ( resp && resp.success ) {
          boot.overrides = ( boot.overrides || [] ).filter( function ( o ) { return o.id !== id; } );
          renderOverrides();
          setStatus( 'Removed' );
        } else {
          setStatus( 'Delete failed', true );
        }
      } )
      .catch( function ( err ) {
        console.error( 'Override delete error:', err );
        setStatus( 'Delete failed', true );
      } );
  }

  // ===================================================================
  // Helpers
  // ===================================================================
  function setStatus( msg, isError ) {
    var el = document.getElementById( 'cap-status' );
    if ( !el ) return;
    el.textContent = msg;
    el.classList.add( 'show' );
    if ( isError ) el.style.color = '#d97a7a';
    else            el.style.color = '';
    clearTimeout( setStatus._t );
    setStatus._t = setTimeout( function () { el.classList.remove( 'show' ); }, 1800 );
  }

  function formatDate( ds ) {
    try {
      var d = new Date( ds + 'T00:00:00' );
      return d.toLocaleDateString( undefined, { weekday: 'short', month: 'short', day: 'numeric', year: 'numeric' } );
    } catch ( e ) { return ds; }
  }

  function escapeHtml( str ) {
    return String( str )
      .replace( /&/g, '&amp;' )
      .replace( /</g, '&lt;' )
      .replace( />/g, '&gt;' )
      .replace( /"/g, '&quot;' );
  }

}() );
