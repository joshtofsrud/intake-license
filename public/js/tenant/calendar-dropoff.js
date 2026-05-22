/**
 * Drop-off calendar — drag-to-reschedule + drag-to-reassign + click-to-open-drawer.
 *
 * Two view modes (window.CAL_DROPOFF_BOOT.view):
 *   - 'day':  per-resource swimlanes within today; drag between columns
 *             reassigns the appointment to a different resource.
 *   - 'week': resource rows × day columns; drag between cells changes
 *             EITHER date OR resource (or both, if dragged diagonally).
 *
 * Click on a card opens the shared appointment drawer (window.ApptDrawer.open).
 * Drag and click are disambiguated via SortableJS lifecycle: onEnd flips a
 * short-lived flag that the click handler checks, so a drag-end doesn't fire
 * a drawer open.
 */
( function () {
  'use strict';

  var boot = window.CAL_DROPOFF_BOOT;
  if ( !boot ) return;

  // Drag-vs-click flag. Set true by Sortable's onEnd; cleared on next tick.
  // The click event fires AFTER onEnd in Sortable's lifecycle, so this works.
  var justDragged = false;

  document.addEventListener( 'DOMContentLoaded', function () {
    if ( !window.Sortable ) {
      console.warn( 'calendar-dropoff: SortableJS not loaded' );
      return;
    }

    if ( boot.view === 'day' )  initDayView();
    if ( boot.view === 'week' ) initWeekView();

    initClickHandler();
  } );

  // ------------------------------------------------------------------
  // Day view: each resource column is a Sortable list. Cards drag
  // between columns to reassign resource_id; date stays fixed.
  // ------------------------------------------------------------------
  function initDayView() {
    var columns = document.querySelectorAll( '.cal-dropoff-col-body' );
    columns.forEach( function ( col ) {
      Sortable.create( col, {
        group: 'cal-dropoff-day',
        animation: 150,
        ghostClass: 'sortable-ghost',
        chosenClass: 'sortable-chosen',
        onEnd: function ( evt ) {
          markDragged();
          if ( evt.from === evt.to && evt.oldIndex === evt.newIndex ) return;
          var card        = evt.item;
          var apptId      = card.dataset.appointmentId;
          var newResource = evt.to.dataset.resourceId;
          var date        = evt.to.dataset.date;
          reschedule( apptId, date, newResource, card, evt.from, evt.to );
        }
      } );
    } );
  }

  // ------------------------------------------------------------------
  // Week view: every (resource × day) cell is a Sortable. Cards drag
  // anywhere in the grid; both date and resource may change in one drop.
  // ------------------------------------------------------------------
  function initWeekView() {
    var cells = document.querySelectorAll( '.cal-week-cell' );
    cells.forEach( function ( cell ) {
      Sortable.create( cell, {
        group: 'cal-dropoff-week',
        animation: 150,
        ghostClass: 'sortable-ghost',
        chosenClass: 'sortable-chosen',
        emptyInsertThreshold: 12,
        onEnd: function ( evt ) {
          markDragged();
          if ( evt.from === evt.to && evt.oldIndex === evt.newIndex ) return;
          var card        = evt.item;
          var apptId      = card.dataset.appointmentId;
          var newResource = evt.to.dataset.resourceId;
          var newDate     = evt.to.dataset.date;
          reschedule( apptId, newDate, newResource, card, evt.from, evt.to );
        }
      } );
    } );
  }

  // Mark that a drag just ended. Clear on the next macrotask so the
  // click event that immediately follows is suppressed, but a subsequent
  // intentional click (even within the same second) goes through.
  function markDragged() {
    justDragged = true;
    setTimeout( function () { justDragged = false; }, 0 );
  }

  // ------------------------------------------------------------------
  // Click handler — open drawer on card click. Suppressed if a drag
  // just ended. Modifier keys (cmd/ctrl/shift, middle-click) fall
  // through so power users can still get default behavior if we ever
  // add an href.
  // ------------------------------------------------------------------
  function initClickHandler() {
    document.addEventListener( 'click', function ( e ) {
      if ( justDragged ) return;
      if ( e.metaKey || e.ctrlKey || e.shiftKey || e.button === 1 ) return;

      var card = e.target.closest( '.cal-dropoff-card, .cal-week-card' );
      if ( !card ) return;

      var apptId = card.dataset.appointmentId;
      if ( !apptId ) return;

      e.preventDefault();

      if ( !window.ApptDrawer || typeof window.ApptDrawer.open !== 'function' ) {
        console.warn( 'calendar-dropoff: ApptDrawer not loaded' );
        return;
      }

      var fullUrl = card.dataset.fullUrl || null;
      window.ApptDrawer.open( apptId, fullUrl );
    } );
  }

  // MARKER-PATCH-113
  // Helpers for the empty-state ghost ("No appointments yet"). The column
  // body already contains the placeholder DIV (rendered by Blade when the
  // column is empty), but SortableJS just shuffles cards in/out without
  // touching the placeholder. We toggle it imperatively here.
  function refreshEmptyState( colEl ) {
    if ( !colEl ) return;
    var placeholder = colEl.querySelector( ':scope > .cal-dropoff-empty' );
    var hasCards = colEl.querySelector( ':scope > .cal-dropoff-card' ) !== null;
    if ( hasCards && placeholder ) {
      placeholder.remove();
    } else if ( !hasCards && !placeholder ) {
      var div = document.createElement( 'div' );
      div.className = 'cal-dropoff-empty';
      div.innerHTML =
        '<div>No appointments yet.</div>' +
        '<div class="cal-dropoff-empty-hint-desktop">Drag a card here to assign.</div>' +
        '<div class="cal-dropoff-empty-hint-mobile">Tap + below to add one.</div>';
      colEl.appendChild( div );
    }
  }

  function reschedule( apptId, newDate, newResourceId, cardEl, fromCol, toCol ) {
    var fd = new FormData();
    fd.append( '_token', boot.csrf );
    fd.append( 'appointment_id', apptId );
    fd.append( 'new_date', newDate );
    if ( newResourceId ) fd.append( 'new_resource_id', newResourceId );

    // Update empty-state ghosts immediately - optimistic UI. If the request
    // fails, we'll reload to restore truth.
    refreshEmptyState( fromCol );
    refreshEmptyState( toCol );

    fetch( boot.rescheduleUrl, {
      method:  'POST',
      body:    fd,
      headers: { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' },
    } )
      .then( function ( r ) { return r.json(); } )
      .then( function ( resp ) {
        if ( resp && resp.success ) {
          if ( window.IntakeToast ) {
            window.IntakeToast.success( 'Appointment moved.' );
          }
          return;
        }
        var msg = ( resp && resp.message ) ? resp.message : 'unknown error';
        if ( window.IntakeToast ) {
          window.IntakeToast.error( 'Could not move that appointment: ' + msg );
        } else {
          alert( 'Could not move that appointment: ' + msg );
        }
        window.location.reload();
      } )
      .catch( function ( err ) {
        console.error( 'Drop-off reschedule failed:', err );
        if ( window.IntakeToast ) {
          window.IntakeToast.error( 'Network error. Reloading to restore the calendar.' );
        } else {
          alert( 'Network error. Reloading to restore the calendar.' );
        }
        window.location.reload();
      } );
  }

}() );
