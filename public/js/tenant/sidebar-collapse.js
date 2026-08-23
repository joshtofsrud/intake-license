/* MARKER-SIDEBAR-COLLAPSE — toggle + persistence.
   The pre-paint class is set inline in the layout; this only handles the
   click, the keyboard shortcut, and writing the choice back. */
(function () {
  'use strict';

  var btn = document.getElementById('ia-sb-collapse');
  if (!btn) return;

  var KEY = 'ia-sidebar-collapsed';
  var root = document.documentElement;

  function sync() {
    var collapsed = root.classList.contains('ia-sb-collapsed');
    btn.setAttribute('aria-expanded', collapsed ? 'false' : 'true');
    btn.setAttribute('aria-label', collapsed ? 'Expand sidebar' : 'Collapse sidebar');
    btn.setAttribute('title', (collapsed ? 'Expand sidebar' : 'Collapse sidebar') + '  [');
  }

  function toggle() {
    var collapsed = root.classList.toggle('ia-sb-collapsed');
    try { localStorage.setItem(KEY, collapsed ? '1' : '0'); } catch (e) {}
    sync();
    // Anything measuring its own width (the builder canvas, charts) needs a
    // nudge — the grid column changed without the window resizing.
    window.dispatchEvent(new Event('resize'));
  }

  btn.addEventListener('click', toggle);

  document.addEventListener('keydown', function (e) {
    if (e.key !== '[' || e.metaKey || e.ctrlKey || e.altKey) return;
    var t = e.target;
    if (t && (t.matches('input, textarea, select, [contenteditable]') || t.isContentEditable)) return;
    e.preventDefault();
    toggle();
  });

  sync();
})();
