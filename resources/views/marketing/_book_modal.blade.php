{{-- any link or button whose address is "#book" opens the
     call scheduler in a pop-up instead of leaving the page. "#book" uses the
     first active booking type; "#book:slug" picks one (e.g. #book:demo).
     The scheduler page is the normal /book/… page, shown without the site
     header and footer inside the pop-up. --}}
@php
    $bmDefault = \App\Models\PlatformBookingType::activeOrdered()->value('slug') ?: 'demo';
@endphp
<style>
  .bm-back { position: fixed; inset: 0; z-index: 9000; background: rgba(0,0,0,.6); backdrop-filter: blur(6px); -webkit-backdrop-filter: blur(6px); display: none; align-items: center; justify-content: center; padding: 20px; }
  .bm-back.open { display: flex; animation: bmIn .2s ease; }
  @keyframes bmIn { from { opacity: 0; } to { opacity: 1; } }
  .bm-panel { position: relative; width: min(760px, 100%); height: min(860px, 92vh); background: var(--mk-bg, #0c0c0c); border: .5px solid rgba(255,255,255,.12); border-radius: 18px; overflow: hidden; box-shadow: 0 30px 80px rgba(0,0,0,.6); }
  .bm-panel iframe { width: 100%; height: 100%; border: 0; display: block; background: transparent; }
  .bm-x { position: absolute; top: 10px; right: 10px; z-index: 2; width: 34px; height: 34px; border-radius: 50%; border: 0; background: rgba(255,255,255,.1); color: #fff; font-size: 20px; line-height: 1; cursor: pointer; }
  .bm-x:hover { background: rgba(255,255,255,.18); }
  .bm-load { position: absolute; inset: 0; display: flex; align-items: center; justify-content: center; color: rgba(255,255,255,.5); font-size: 13px; }
  @media (max-width: 600px) { .bm-back { padding: 0; } .bm-panel { width: 100%; height: 100%; border-radius: 0; border: 0; } }
  html.bm-lock, html.bm-lock body { overflow: hidden; }
</style>
<div class="bm-back" id="bm-back" role="dialog" aria-modal="true" aria-label="Book a call">
  <div class="bm-panel">
    <button type="button" class="bm-x" id="bm-x" aria-label="Close">×</button>
    <div class="bm-load" id="bm-load">Loading the scheduler…</div>
    <iframe id="bm-frame" title="Book a call" loading="lazy"></iframe>
  </div>
</div>
<script>
(function () {
  if (window.self !== window.top) return;            // never nest inside the pop-up
  var back = document.getElementById('bm-back'), frame = document.getElementById('bm-frame'), load = document.getElementById('bm-load');
  var DEFAULT = @json($bmDefault), lastFocus = null;
  function open(slug) {
    lastFocus = document.activeElement;
    load.style.display = 'flex';
    frame.src = '/book/' + encodeURIComponent(slug || DEFAULT);
    back.classList.add('open');
    document.documentElement.classList.add('bm-lock');
    document.getElementById('bm-x').focus();
  }
  function close() {
    back.classList.remove('open');
    document.documentElement.classList.remove('bm-lock');
    frame.src = 'about:blank';
    if (lastFocus && lastFocus.focus) lastFocus.focus();
  }
  frame.addEventListener('load', function () { if (frame.src !== 'about:blank') load.style.display = 'none'; });
  document.addEventListener('click', function (e) {
    var a = e.target.closest && e.target.closest('a[href^="#book"]');
    if (!a) return;
    var m = a.getAttribute('href').match(/^#book(?::([a-z0-9-]+))?$/i);
    if (!m) return;
    e.preventDefault();
    open(m[1]);
  });
  document.getElementById('bm-x').addEventListener('click', close);
  back.addEventListener('click', function (e) { if (e.target === back) close(); });
  document.addEventListener('keydown', function (e) { if (e.key === 'Escape' && back.classList.contains('open')) close(); });
  if (/^#book(:[a-z0-9-]+)?$/i.test(location.hash)) open((location.hash.split(':')[1]) || null);   // shareable link
})();
</script>
