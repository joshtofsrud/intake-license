#!/bin/bash
# apply-inbox-mobile.sh
#
# MARKER-INBOX-MOBILE — the inbox on a phone. Josh: "messages scroll
# endlessly, probably could be laid out better as well."
#
#  1. SEARCH + FILTER PILLS STICK below the app header (which is itself
#     sticky at 52px on <=1023px, so that's the offset). They used to scroll
#     away with the list, so changing filter or searching meant scrolling
#     back to the top of a long inbox — most of what "scrolls endlessly"
#     actually feels like.
#
#  2. THE LIST RENDERS IN CHUNKS OF 20 with a "Show more" button. Same
#     reasoning as MARKER-HIST-MOBILE: the filter pills and search are
#     server-side GETs here, but the rendered list is whatever the server
#     returned, so capping what is PAINTED changes no behaviour and ends the
#     endless scroll. Hidden rows are display:none, not removed — the
#     selected-thread link and every href still work.
#
#  3. THE 100-ROW CEILING BECOMES VISIBLE. The controller loads ->limit(100)
#     with nothing in the view saying so; past 100 conversations the older
#     ones simply vanish with no explanation. When the list is at the cap,
#     a line now says so and points at search. This does NOT fix the cap
#     (that needs cursor paging server-side, its own patch) — it stops the
#     page lying about being complete.
#
#  4. HEADER CONDENSED ON MOBILE. Title + subtitle + a full-width primary
#     button ate the top of the viewport before a single conversation. The
#     subtitle hides and the button sits beside the title, worth about two
#     more rows on screen.
#
# Row heights, type sizes and bubble styling are deliberately untouched —
# MARKER-PATCH-434 set those to match an approved mockup.
set -e

MARKER="MARKER-INBOX-MOBILE"
V="resources/views/tenant/inbox/index.blade.php"

[ -f "$V" ] || { echo "ERROR: missing $V — run from the repo root"; exit 1; }
if grep -q "$MARKER" "$V" 2>/dev/null; then
  echo "ok: already applied ($MARKER present) — no-op"
  exit 0
fi

python3 - <<'PY'
import io
p = 'resources/views/tenant/inbox/index.blade.php'
src = io.open(p, encoding='utf-8').read()

# ---------------------------------------------------------------
# 1. Styles
# ---------------------------------------------------------------
a = "  .ib-new-meta { display:flex; align-items:center; gap:14px; flex-wrap:wrap; margin:10px 0 8px; }"
assert src.count(a) == 1, 'style anchor'
src = src.replace(a, a + """

  /* MARKER-INBOX-MOBILE ---------------------------------------------- */
  .ib-more { display:none; width:100%; margin:10px 0 4px; padding:12px;
    background:transparent; border:.5px solid var(--ia-border);
    border-radius:var(--ia-r-md); color:var(--ia-text); font-size:13px;
    font-family:inherit; cursor:pointer; }
  .ib-more:hover { background:rgba(127,127,127,.06); }
  .ib-more.on { display:block; }
  .ib-capnote { display:none; padding:12px 16px; font-size:12px; opacity:.5;
    text-align:center; line-height:1.5; }
  .ib-capnote.on { display:block; }

  @media (max-width: 980px) {
    /* the app header is sticky at 52px on <=1023px; sit directly under it */
    .ib-sticky { position:sticky; top:52px; z-index:20;
      background:var(--ia-bg); padding-top:8px; }
    /* the list scroller must not clip the sticky child */
    .ib-scroll { overflow:visible !important; }

    /* header: title and action on one line, subtitle gone */
    .ia-page-head { align-items:center; gap:10px; }
    .ia-page-subtitle { display:none; }
    .ia-page-actions .ia-btn { white-space:nowrap; padding:9px 14px; font-size:13px; }
  }""", 1)

# ---------------------------------------------------------------
# 2. Wrap search + pills in the sticky strip
# ---------------------------------------------------------------
b = """    {{-- MARKER-INBOX-SEARCH --}}
    <form class="ib-search\""""
assert src.count(b) == 1, 'search form anchor'
src = src.replace(b, """    {{-- MARKER-INBOX-MOBILE — search and pills stay reachable while the
         list scrolls; they used to scroll away with it. --}}
    <div class="ib-sticky">
    {{-- MARKER-INBOX-SEARCH --}}
    <form class="ib-search\"""", 1)

c = """    <div style="overflow-y:auto;flex:1">
      @forelse($threads as $t)"""
assert src.count(c) == 1, 'list container anchor'
src = src.replace(c, """    </div>{{-- /ib-sticky MARKER-INBOX-MOBILE --}}
    <div class="ib-scroll" id="ib-scroll" style="overflow-y:auto;flex:1">
      @forelse($threads as $t)""", 1)

# ---------------------------------------------------------------
# 3. Show-more + cap notice after the list
# ---------------------------------------------------------------
d = """      @endforelse
    </div>
  </div>"""
assert src.count(d) == 1, 'list close anchor'
src = src.replace(d, """      @endforelse

      {{-- MARKER-INBOX-MOBILE --}}
      <button type="button" class="ib-more" id="ib-more"></button>

      {{-- MARKER-INBOX-MOBILE — the controller stops at 100. Saying so beats
           a list that quietly ends. --}}
      @if($threads->count() >= 100)
        <div class="ib-capnote on">
          Showing the 100 most recent conversations. Search to reach older ones.
        </div>
      @endif
    </div>
  </div>""", 1)

# ---------------------------------------------------------------
# 4. Chunking script
# ---------------------------------------------------------------
e = """@section('content')"""
assert src.count(e) == 1
src = src.replace(e, e, 1)  # no-op, kept for clarity of intent

f = "@endsection"
assert src.count(f) >= 1
# append the script INSIDE the content section — a <script> after @endsection
# in a template that extends a layout is silently discarded.
idx = src.rindex(f)
src = src[:idx] + """{{-- MARKER-INBOX-MOBILE — cap what is painted, not what is loaded. The
     rows stay in the DOM (display:none) so every href and the selected-thread
     highlight keep working. --}}
<script>
(function () {
  var CHUNK = 20;
  var scroll = document.getElementById('ib-scroll');
  var btn = document.getElementById('ib-more');
  if (!scroll || !btn) return;

  var rows = Array.prototype.slice.call(scroll.querySelectorAll('.ib-thread'));
  var shown = CHUNK;

  function paint() {
    rows.forEach(function (row, i) {
      row.style.display = i < shown ? '' : 'none';
    });
    var remaining = rows.length - shown;
    btn.classList.toggle('on', remaining > 0);
    if (remaining > 0) {
      btn.textContent = 'Show ' + Math.min(CHUNK, remaining) + ' more \\u00b7 ' + remaining + ' not shown';
    }
  }

  // A thread opened from a link must never be one of the hidden ones.
  var sel = scroll.querySelector('.ib-thread.is-sel');
  if (sel) {
    var at = rows.indexOf(sel);
    if (at >= shown) shown = Math.ceil((at + 1) / CHUNK) * CHUNK;
  }

  btn.addEventListener('click', function () {
    shown += CHUNK;
    paint();
  });

  paint();
})();
</script>

""" + src[idx:]

io.open(p, 'w', encoding='utf-8').write(src)
print('ok: inbox sticky controls, chunked list, cap notice, condensed header')
PY

echo ""
echo "== inbox mobile applied =="
echo "Post-deploy: php artisan optimize:clear"
