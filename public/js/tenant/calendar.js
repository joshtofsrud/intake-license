/**
 * Calendar admin page — client-side interactivity.
 *
 * Vanilla JS, matches the booking.js pattern. Reads window.IntakeAdmin
 * for CSRF/tenant context. No framework dependencies.
 *
 * Current responsibilities:
 *   - Position + update the "now" line when viewing today
 *   - Tick the now-line every 60 seconds
 *
 * Future (S3c+): date navigation via fetch (skip full page reload),
 * drag-to-reschedule (S5), click-empty-to-book (S3c).
 */
(function () {
  'use strict';

  // ==========================================================================
  // Now-line positioning
  // ==========================================================================
  //
  // The shell element carries open_min, close_min, px_per_min, and is_today
  // as data attributes — rendered server-side. JS just reads them and
  // positions the now-line at the correct vertical offset.
  //
  // Why 60s tick? Finer granularity (e.g. per-second) is wasted render cycles;
  // coarser (e.g. 5 min) creates visible lag at minute boundaries. 60s is the
  // sweet spot at scale.
  // --------------------------------------------------------------------------

  function initNowLine() {
    var shell = document.querySelector('.ia-cal-shell');
    if (!shell) return;

    var isToday = shell.getAttribute('data-cal-is-today') === '1';
    if (!isToday) return;

    var nowLine = document.getElementById('ia-cal-now-line');
    var nowLabel = document.getElementById('ia-cal-now-label');
    if (!nowLine || !nowLabel) return;

    var openMin  = parseInt(shell.getAttribute('data-cal-open-min'),  10);
    var closeMin = parseInt(shell.getAttribute('data-cal-close-min'), 10);
    var pxPerMin = parseFloat(shell.getAttribute('data-cal-px-per-min'));

    if (isNaN(openMin) || isNaN(closeMin) || isNaN(pxPerMin) || pxPerMin <= 0) {
      return;
    }

    function update() {
      var now = new Date();
      var nowMin = now.getHours() * 60 + now.getMinutes();

      // Hide the line if we're outside business hours — showing it at the
      // very top or bottom edge is misleading.
      if (nowMin < openMin || nowMin > closeMin) {
        nowLine.style.display = 'none';
        return;
      }

      var topPx = Math.round((nowMin - openMin) * pxPerMin);
      nowLine.style.display = 'block';
      nowLine.style.top = topPx + 'px';

      // Format time label as "2:15pm" — tight, readable at 10px type.
      var h = now.getHours();
      var m = now.getMinutes();
      var ampm = h < 12 ? 'am' : 'pm';
      var h12  = h === 0 ? 12 : (h > 12 ? h - 12 : h);
      nowLabel.textContent = h12 + ':' + (m < 10 ? '0' + m : m) + ampm;
    }

    update();
    // Tick every 60 seconds. At scale this runs on thousands of open
    // calendar tabs but is cheap — a few DOM reads/writes, no network.
    setInterval(update, 60 * 1000);
  }

  // ==========================================================================
  // Boot
  // ==========================================================================
  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', initNowLine);
  } else {
    initNowLine();
  }

  console.log('[calendar] module loaded');
})();
