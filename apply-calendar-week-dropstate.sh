#!/bin/bash
# calendar-week-dropstate — stop injecting day-view UI into the week grid.
#
#   refreshEmptyState() was written for the day view and is called from both.
#   It looks for `.cal-dropoff-card` children; week cells hold `.cal-week-card`.
#   So in week view hasCards was ALWAYS false, and since week cells have no
#   `.cal-dropoff-empty` either, it appended one — meaning every drag injected
#   a "No appointments yet. Drag a card here to assign." block into a cell
#   that visibly had appointments in it, and they accumulated with each drag.
#
#   Second, separate gap: `.cal-week-cell-count` is server-rendered and nothing
#   touched it after a drop, so the little number in the corner of each cell
#   kept the count from page load. Same for the `is-full` shading.
#
#   Now the function branches on the container. Week cells get their real card
#   class, no injected placeholder, a rebuilt count badge and a recomputed
#   is-full state. Day columns keep exactly what they had.
#
#   The cap has to come from somewhere to rebuild the badge — it was only
#   present inside the badge's own text, which disappears when a cell empties.
#   The cell now carries data-cap, so the JS can always reconstruct "2/4"
#   rather than parsing its own output back.
#
#   Any placeholders already injected into a week grid vanish on reload; the
#   fix also clears strays whenever it touches a cell.
# NO MIGRATION. Server: view:clear (blade + public js).
set -e
if grep -q "MARKER-WEEK-DROPSTATE" public/js/tenant/calendar-dropoff.js; then
  echo "calendar-week-dropstate already applied — aborting."; exit 1
fi

# ------------------------------------------------------------------ blade
python3 - <<'CWD_0_EOF'
import io
p = 'resources/views/tenant/calendar/dropoff-week.blade.php'
s = io.open(p, encoding='utf-8').read()

old = """          <div class="cal-week-cell {{ $atCap ? 'is-full' : '' }} {{ $day->isToday() ? 'is-today' : '' }}"
               data-resource-id="{{ $r->id }}"
               data-date="{{ $day->format('Y-m-d') }}">"""
assert s.count(old) == 1, s.count(old)

new = """          {{-- MARKER-WEEK-DROPSTATE — data-cap so the drag handler can rebuild the
               count badge after a drop. The cap used to live only inside the
               badge's own text, which is gone the moment a cell empties. --}}
          <div class="cal-week-cell {{ $atCap ? 'is-full' : '' }} {{ $day->isToday() ? 'is-today' : '' }}"
               data-resource-id="{{ $r->id }}"
               data-date="{{ $day->format('Y-m-d') }}"
               data-cap="{{ $cap !== null ? $cap : '' }}">"""
io.open(p, 'w', encoding='utf-8').write(s.replace(old, new))
print('blade data-cap ok')
CWD_0_EOF

# ------------------------------------------------------------------ js
python3 - <<'CWD_1_EOF'
import io
p = 'public/js/tenant/calendar-dropoff.js'
s = io.open(p, encoding='utf-8').read()

old = """  function refreshEmptyState( colEl ) {
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
  }"""
assert s.count(old) == 1, s.count(old)

new = """  // MARKER-WEEK-DROPSTATE
  //
  // Called for both views. It used to assume the day view: it looked for
  // `.cal-dropoff-card` children, which week cells never have, so hasCards
  // was always false there and it appended a day-view "No appointments yet"
  // block into cells that plainly had appointments in them — once per drag,
  // accumulating. Week cells hold `.cal-week-card` and have no text
  // placeholder at all, just a flex spacer.
  function refreshEmptyState( colEl ) {
    if ( !colEl ) return;
    if ( colEl.classList.contains( 'cal-week-cell' ) ) {
      refreshWeekCell( colEl );
      return;
    }
    refreshDayColumn( colEl );
  }

  function refreshDayColumn( colEl ) {
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

  // Week cell: no text placeholder, a spacer when empty, and a count badge
  // that nothing was updating — the number in the corner kept whatever the
  // server rendered at page load, so it lied after every move.
  function refreshWeekCell( cellEl ) {
    // Clear any day-view placeholder a previous drag injected here.
    var stray = cellEl.querySelectorAll( ':scope > .cal-dropoff-empty' );
    for ( var i = 0; i < stray.length; i++ ) { stray[ i ].remove(); }

    var count   = cellEl.querySelectorAll( ':scope > .cal-week-card' ).length;
    var spacer  = cellEl.querySelector( ':scope > .cal-week-cell-empty' );
    var badge   = cellEl.querySelector( ':scope > .cal-week-cell-count' );
    var capAttr = cellEl.getAttribute( 'data-cap' );
    var cap     = ( capAttr === null || capAttr === '' ) ? null : parseInt( capAttr, 10 );

    if ( count > 0 ) {
      if ( spacer ) spacer.remove();
      if ( !badge ) {
        badge = document.createElement( 'div' );
        badge.className = 'cal-week-cell-count';
        cellEl.insertBefore( badge, cellEl.firstChild );
      }
      badge.innerHTML = count +
        ( cap !== null ? '<span class="cap-of">/' + cap + '</span>' : '' );
    } else {
      if ( badge ) badge.remove();
      if ( !spacer ) {
        var div = document.createElement( 'div' );
        div.className = 'cal-week-cell-empty';
        cellEl.appendChild( div );
      }
    }

    // Over-capacity shading has to follow the new count too.
    if ( cap !== null && count >= cap ) {
      cellEl.classList.add( 'is-full' );
    } else {
      cellEl.classList.remove( 'is-full' );
    }
  }"""

io.open(p, 'w', encoding='utf-8').write(s.replace(old, new))
print('js week-aware ok')
CWD_1_EOF

node --check public/js/tenant/calendar-dropoff.js && echo "calendar-dropoff.js parses"

echo
echo "calendar-week-dropstate applied."
