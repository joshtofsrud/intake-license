#!/usr/bin/env bash
set -euo pipefail
# apply-notes-photo-lightbox.sh — MARKER-NOTEPHOTO
# Notes pad photos: bigger thumbs, and a lightbox instead of a new tab.
#
# BEFORE: each photo was `<a href="{url}" target="_blank"><img></a>` at 64px, so
# clicking dumped you onto a bare image URL and you had to navigate back.
# Thumbnails already existed — the ask was really about the click behaviour and
# the size.
#
#   thumbs 64px -> 88px (big enough to recognise a photo without pushing the
#                        note text down)
#   click       -> lightbox over the page; Esc or backdrop closes; left/right
#                  arrows move between photos ON THE SAME NOTE
#
# Styled to the notepad's own palette (#D9CDB0 border, #F6F0E1 paper, #3B3524
# ink) rather than the app's dark chrome, because this page is deliberately
# its own "old school pad" surface.
#
# No controller, route or storage change — the markup already has every URL it
# needs. Falls back to nothing worse than today if JS fails: the thumb is a
# button, so a dead script means a click that does nothing rather than a broken
# link.

VIEW=resources/views/tenant/notes/index.blade.php
[ -f "$VIEW" ] || { echo "MISSING $VIEW — run from the repo root"; exit 1; }

if grep -q "MARKER-NOTEPHOTO" "$VIEW"; then
  echo "Already applied (MARKER-NOTEPHOTO present) — no-op."
  exit 0
fi

python3 - "$VIEW" <<'PY'
import sys
path = sys.argv[1]
src = open(path).read()

def edit(old, new, label):
    global src
    n = src.count(old)
    if n != 1:
        print(f"FAIL {label}: anchor found {n} times"); sys.exit(1)
    src = src.replace(old, new, 1)
    print(f"ok   {label}")

# 1) thumb -> button, so a dead script can't leave a broken link
edit("""              <a href="{{ $u }}" target="_blank" rel="noopener"><img src="{{ $u }}" alt="" loading="lazy"></a>""",
"""              {{-- MARKER-NOTEPHOTO — opens in the lightbox below, not a new tab --}}
              <button type="button" class="np-shot" data-full="{{ $u }}" aria-label="Open photo">
                <img src="{{ $u }}" alt="" loading="lazy">
              </button>""",
"thumb markup")

# 2) bigger thumbs + button reset + lightbox styles
edit("""  .np-shots img { width:64px; height:64px; object-fit:cover; border-radius:6px; display:block;
                  border:1px solid #D9CDB0; }""",
"""  /* MARKER-NOTEPHOTO */
  .np-shot { padding:0; border:0; background:none; cursor:zoom-in; line-height:0; border-radius:6px; }
  .np-shot:focus-visible { outline:2px solid #A8622A; outline-offset:2px; }
  .np-shots img { width:88px; height:88px; object-fit:cover; border-radius:6px; display:block;
                  border:1px solid #D9CDB0; transition:filter .12s; }
  .np-shot:hover img { filter:brightness(1.05); }

  #np-lb { position:fixed; inset:0; z-index:1400; background:rgba(24,20,12,.86);
           display:flex; align-items:center; justify-content:center; padding:32px; }
  #np-lb[hidden] { display:none; }
  #np-lb img { max-width:100%; max-height:100%; border-radius:8px; border:3px solid #F6F0E1;
               box-shadow:0 24px 70px rgba(0,0,0,.6); }
  .np-lb-btn { position:absolute; background:#F6F0E1; color:#3B3524; border:1px solid #D9CDB0;
               border-radius:50%; width:40px; height:40px; font-size:19px; line-height:1;
               cursor:pointer; display:flex; align-items:center; justify-content:center; }
  .np-lb-btn:hover { background:#fff; }
  .np-lb-x    { top:20px; right:20px; }
  .np-lb-prev { left:20px;  top:50%; transform:translateY(-50%); }
  .np-lb-next { right:20px; top:50%; transform:translateY(-50%); }
  .np-lb-btn[hidden] { display:none; }
  .np-lb-count { position:absolute; bottom:20px; left:50%; transform:translateX(-50%);
                 color:#E8DFC8; font-size:12px; letter-spacing:.04em; }""",
"thumb + lightbox CSS")

# 3) the lightbox itself — INSIDE the content section. This view is
#    @extends + @section('content') ... @endsection, and Blade discards
#    anything after @endsection in an extending view.
anchor = "@endsection"
if src.count(anchor) != 1:
    print(f"FAIL lightbox: @endsection found {src.count(anchor)} times"); sys.exit(1)

block = """
{{-- MARKER-NOTEPHOTO — photo lightbox. Sits inside the content section: this
     view extends a layout, so anything after @endsection would be discarded. --}}
<div id="np-lb" hidden role="dialog" aria-modal="true" aria-label="Photo">
  <button type="button" class="np-lb-btn np-lb-x"    id="np-lb-x"    aria-label="Close">&times;</button>
  <button type="button" class="np-lb-btn np-lb-prev" id="np-lb-prev" aria-label="Previous photo">&#8249;</button>
  <img id="np-lb-img" src="" alt="">
  <button type="button" class="np-lb-btn np-lb-next" id="np-lb-next" aria-label="Next photo">&#8250;</button>
  <div class="np-lb-count" id="np-lb-count"></div>
</div>

<script>
(function () {
  var lb    = document.getElementById('np-lb');
  var img   = document.getElementById('np-lb-img');
  var prev  = document.getElementById('np-lb-prev');
  var next  = document.getElementById('np-lb-next');
  var count = document.getElementById('np-lb-count');
  if (!lb) { return; }

  var urls = [];
  var idx  = 0;

  function show(i) {
    if (!urls.length) { return; }
    idx = (i + urls.length) % urls.length;
    img.src = urls[idx];
    var many = urls.length > 1;
    prev.hidden = !many;
    next.hidden = !many;
    count.textContent = many ? (idx + 1) + ' of ' + urls.length : '';
  }

  function open(shot) {
    // Photos of THIS note only — arrows shouldn't wander into another note.
    var wrap = shot.closest('.np-shots');
    urls = Array.prototype.map.call(
      wrap ? wrap.querySelectorAll('.np-shot') : [shot],
      function (b) { return b.getAttribute('data-full'); }
    );
    show(urls.indexOf(shot.getAttribute('data-full')));
    lb.hidden = false;
    document.body.style.overflow = 'hidden';
  }

  function close() {
    lb.hidden = true;
    img.src = '';
    document.body.style.overflow = '';
  }

  document.addEventListener('click', function (e) {
    var shot = e.target.closest('.np-shot');
    if (shot) { open(shot); }
  });

  document.getElementById('np-lb-x').addEventListener('click', close);
  prev.addEventListener('click', function () { show(idx - 1); });
  next.addEventListener('click', function () { show(idx + 1); });

  // Backdrop only — clicking the photo itself shouldn't dismiss it.
  lb.addEventListener('click', function (e) { if (e.target === lb) { close(); } });

  document.addEventListener('keydown', function (e) {
    if (lb.hidden) { return; }
    if (e.key === 'Escape')     { close(); }
    if (e.key === 'ArrowLeft')  { show(idx - 1); }
    if (e.key === 'ArrowRight') { show(idx + 1); }
  });
})();
</script>

@endsection"""

src = src.replace(anchor, block, 1)
print("ok   lightbox markup + script")

open(path, 'w').write(src)
PY

echo ""
echo "SUCCESS — apply-notes-photo-lightbox applied."
echo "Deploy's optimize covers the view cache."
