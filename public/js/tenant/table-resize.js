/* MARKER-TABLE-RESIZE — drag-to-resize columns for tables marked
   data-resizable-table="<key>". Widths persist per browser under that key.

   Notes for whoever reads this next:
   - The table switches to table-layout:fixed on first run, because `auto`
     ignores the widths you set and re-solves the layout on every render.
   - Widths are stored as pixels against the column count. If a column is
     added or removed later the stored entry no longer matches and is
     discarded, rather than restoring a layout that no longer fits.
   - Nothing here touches the server; this is a per-person view preference. */
(function () {
  var MIN = 70;

  function keyFor(table) {
    return 'intake.cols.' + (table.dataset.resizableTable || 'table');
  }

  function load(table, count) {
    try {
      var raw = localStorage.getItem(keyFor(table));
      if (!raw) return null;
      var saved = JSON.parse(raw);
      if (!saved || saved.n !== count || !Array.isArray(saved.w)) return null;
      return saved.w;
    } catch (e) { return null; }
  }

  function save(table, widths) {
    try {
      localStorage.setItem(keyFor(table), JSON.stringify({ n: widths.length, w: widths }));
    } catch (e) { /* private mode: resizing still works, it just won't be remembered */ }
  }

  function clear(table) {
    try { localStorage.removeItem(keyFor(table)); } catch (e) {}
  }

  function init(table) {
    var heads = Array.prototype.slice.call(table.querySelectorAll('thead th'));
    if (heads.length < 2) return;

    var stored = load(table, heads.length);

    // measure BEFORE switching to fixed layout, or every column reads the same
    var widths = heads.map(function (th, i) {
      return (stored && stored[i]) ? stored[i] : Math.round(th.getBoundingClientRect().width);
    });

    table.style.tableLayout = 'fixed';
    table.style.width = 'auto';
    table.style.minWidth = '100%';

    function apply() {
      heads.forEach(function (th, i) { th.style.width = widths[i] + 'px'; });
    }
    apply();

    var wrap = table.closest('[data-resizable-wrap]') || table.parentNode;
    var reset = wrap.parentNode.querySelector('[data-reset-columns]');
    function showReset() { if (reset) reset.hidden = false; }
    if (stored) showReset();

    heads.forEach(function (th, i) {
      if (i === heads.length - 1) return; // nothing to drag against past the last one

      var handle = document.createElement('span');
      handle.className = 'ia-col-resize';
      handle.tabIndex = 0;
      handle.setAttribute('role', 'separator');
      handle.setAttribute('aria-orientation', 'vertical');
      handle.setAttribute('aria-label', 'Resize ' + (th.textContent || '').trim() + ' column');
      th.appendChild(handle);

      var startX = 0, startW = 0, dragging = false;

      function move(e) {
        if (!dragging) return;
        var x = (e.touches ? e.touches[0].clientX : e.clientX);
        widths[i] = Math.max(MIN, Math.round(startW + (x - startX)));
        apply();
      }
      function end() {
        if (!dragging) return;
        dragging = false;
        document.body.style.userSelect = '';
        document.body.style.cursor = '';
        save(table, widths);
        showReset();
        document.removeEventListener('mousemove', move);
        document.removeEventListener('mouseup', end);
        document.removeEventListener('touchmove', move);
        document.removeEventListener('touchend', end);
      }
      function start(e) {
        dragging = true;
        startX = (e.touches ? e.touches[0].clientX : e.clientX);
        startW = widths[i];
        document.body.style.userSelect = 'none';
        document.body.style.cursor = 'col-resize';
        document.addEventListener('mousemove', move);
        document.addEventListener('mouseup', end);
        document.addEventListener('touchmove', move, { passive: false });
        document.addEventListener('touchend', end);
        e.preventDefault();
        e.stopPropagation();
      }

      handle.addEventListener('mousedown', start);
      handle.addEventListener('touchstart', start, { passive: false });

      // double-click resets just this column to what the content wants
      handle.addEventListener('dblclick', function (e) {
        e.preventDefault();
        e.stopPropagation();
        th.style.width = '';
        table.style.tableLayout = 'auto';
        widths[i] = Math.round(th.getBoundingClientRect().width);
        table.style.tableLayout = 'fixed';
        apply();
        save(table, widths);
      });

      // dragging is not usable for everyone
      handle.addEventListener('keydown', function (e) {
        var step = e.shiftKey ? 24 : 8;
        if (e.key === 'ArrowLeft')  { widths[i] = Math.max(MIN, widths[i] - step); }
        else if (e.key === 'ArrowRight') { widths[i] = widths[i] + step; }
        else { return; }
        e.preventDefault();
        apply();
        save(table, widths);
        showReset();
      });
    });

    if (reset) {
      reset.addEventListener('click', function (e) {
        e.preventDefault();
        clear(table);
        location.reload();
      });
    }
  }

  function boot() {
    document.querySelectorAll('table[data-resizable-table]').forEach(function (t) {
      if (!t._colResizeReady) { t._colResizeReady = true; init(t); }
    });
  }
  if (document.readyState === 'loading') { document.addEventListener('DOMContentLoaded', boot); }
  else { boot(); }
})();
