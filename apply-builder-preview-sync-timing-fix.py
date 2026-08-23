#!/usr/bin/env python3
"""Fix the builder/preview bridge: it ran before the sections existed.

apply-builder-preview-sync.py anchored the bridge script on the splash
overlay block, which sits ~80 lines ABOVE the section loop in
public/layout.blade.php. The script executes inline while the document is
still parsing, so querySelectorAll('[data-pb-section]') matched nothing
and the function returned on its first line. Everything looked correct in
the source and did nothing in the browser.

Fix: wait for DOMContentLoaded before wiring anything (handling the case
where the document has already finished parsing, so this stays correct if
the block is ever moved below the sections).
Run from repo root: python3 apply-builder-preview-sync-timing-fix.py
"""
import sys

PUB = 'resources/views/public/layout.blade.php'

s = open(PUB).read()

if 'MARKER-BUILDER-SYNC-TIMING' in s:
    print("SKIP (already applied)"); sys.exit(0)

old = """<script>
(function () {
  var wraps = Array.prototype.slice.call(document.querySelectorAll('[data-pb-section]'));
  if (!wraps.length) return;
"""

new = """<script>
(function () {
  // MARKER-BUILDER-SYNC-TIMING — this block sits above the section loop, so
  // at parse time there are no wrappers to bind to. Wait for the document.
  function boot() {
  var wraps = Array.prototype.slice.call(document.querySelectorAll('[data-pb-section]'));
  if (!wraps.length) return;
"""

if old not in s:
    print("FAIL: bridge script anchor not found — was apply-builder-preview-sync.py applied?")
    sys.exit(1)

s = s.replace(old, new, 1)

# Close the new inner function and start it at the right moment.
old_tail = """  post({ source: 'pb-preview', type: 'ready' });
})();
</script>"""

new_tail = """  post({ source: 'pb-preview', type: 'ready' });
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', boot);
  } else {
    boot();
  }
})();
</script>"""

if old_tail not in s:
    print("FAIL: bridge script tail not found"); sys.exit(1)

s = s.replace(old_tail, new_tail, 1)
open(PUB, 'w').write(s)
print("OK: bridge waits for DOMContentLoaded")
print("Done. No migration needed. view:clear after deploy.")
