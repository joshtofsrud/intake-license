{{-- MARKER-SHOP-TI-ACCORDION: the Text + image scripts on shop sites, copied
     unchanged from marketing/sections/text_image so both sites behave the same.
     Each guards itself, so including it for several sections runs it once. --}}
@if($tiJsAcc ?? false)
<script>
  // one listener for every accordion on the page (and any the builder preview redraws).
  (function () {
    if (window.__tiAcc) { window.__tiAcc.scan(); return; }
    var reduce = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
    function items(acc) { return Array.prototype.slice.call(acc.querySelectorAll('.ti-item')); }
    function show(acc, idx) {
      var imgs = acc.querySelectorAll('.ti-stage img[data-i]'); if (!imgs.length) return;
      var target = null;
      imgs.forEach(function (im) { if (+im.getAttribute('data-i') === idx) target = im; });
      if (!target) return; // no image for this item: keep the last one showing
      imgs.forEach(function (im) { im.classList.toggle('is-on', im === target); });
    }
    function setOpen(acc, idx, on) {
      var multi = acc.getAttribute('data-ti-multi') === '1';
      items(acc).forEach(function (it) {
        var i = +it.getAttribute('data-i');
        var open = i === idx ? on : (multi ? it.classList.contains('is-open') : false);
        it.classList.toggle('is-open', open); it.classList.remove('is-run');
        it.querySelector('.ti-head').setAttribute('aria-expanded', open ? 'true' : 'false');
      });
      if (on) show(acc, idx);
    }
    function tick(acc) {
      clearTimeout(acc.__tiT);
      var secs = +acc.getAttribute('data-ti-auto');
      if (!secs || reduce || acc.__tiStop || acc.__tiPause) return;
      var list = items(acc), cur = list.findIndex(function (it) { return it.classList.contains('is-open'); });
      if (cur < 0) { setOpen(acc, 0, true); cur = 0; }
      var el = list[cur]; void el.offsetWidth; el.classList.add('is-run');
      acc.__tiT = setTimeout(function () { setOpen(acc, (cur + 1) % list.length, true); tick(acc); }, secs * 1000);
    }
    // which item is open follows the scroll position.
    var scrollers = [], raf = 0;
    function scrollOn(acc) {
      // no phone exclusion; only a block too tall for the screen is left out.
      return acc.getAttribute('data-ti-scroll') === '1' && !reduce && !acc.__tiNoFit && acc.closest('.ti-track');
    }
    function drive() {
      raf = 0;
      scrollers.forEach(function (acc) {
        if (!scrollOn(acc)) { acc.classList.remove('is-scroll'); var t0 = acc.closest('.ti-track'); if (t0) { t0.style.height = ''; t0.__h = null; } return; }
        acc.classList.add('is-scroll');
        // the block must fit on screen with its tallest item open.
        var tr = acc.closest('.ti-track'), pn = tr.querySelector('.ti-pin');
        if (need(acc, pn) > window.innerHeight - 92) {
          acc.__tiNoFit = true; tr.classList.add('ti-nofit');
          acc.classList.remove('is-scroll'); tr.style.height = ''; tr.__h = null; pn.style.top = ''; pn.__top = null;
          items(acc).forEach(function (it) { it.style.removeProperty('--p'); });
          return;
        }
        var g = geo(acc), list = items(acc), n = list.length;
        if (g.total <= 0) return;
        var r = g.track.getBoundingClientRect();
        var prog = Math.min(Math.max((g.top - r.top) / g.total, 0), 0.9999), pos = prog * n, idx = Math.floor(pos);
        if (acc.__tiCur !== idx) { acc.__tiCur = idx; setOpen(acc, idx, true); }
        list.forEach(function (it, k) { it.style.setProperty('--p', k === idx ? (pos - idx).toFixed(3) : '0'); });
      });
    }
    // size the track from the content: the block sticks
    // centred on screen (below the nav), and the track is the block's own height
    // plus one stretch of scrolling per item.
    function geo(acc) {
      var track = acc.closest('.ti-track'), pin = track.querySelector('.ti-pin');
      var vh = window.innerHeight, h = pin.offsetHeight, n = items(acc).length;
      var len = parseFloat(track.getAttribute('data-ti-len')) || 0.75;
      var top = Math.max(80, Math.round((vh - h) / 2));
      if (pin.__top !== top) { pin.style.top = top + 'px'; pin.__top = top; }
      var want = Math.round(h + n * len * vh);
      if (track.__h !== want) { track.style.height = want + 'px'; track.__h = want; }
      return { track: track, top: top, total: want - h };
    }
    // block height with the TALLEST item open: the
    // open panels are swapped for the biggest panel's full height.
    function need(acc, pin) {
      var cur = 0, max = 0;
      acc.querySelectorAll('.ti-item .ti-panel > div').forEach(function (d) { cur += d.offsetHeight; max = Math.max(max, d.scrollHeight); });
      return pin.offsetHeight - cur + max;
    }
    function queue() { if (!raf) raf = window.requestAnimationFrame(drive); }
    window.addEventListener('scroll', queue, { passive: true });
    var rzT = 0;
    window.addEventListener('resize', function () {
      // a turned phone or resized window gets a fresh fit check
      clearTimeout(rzT);
      rzT = setTimeout(function () {
        scrollers.forEach(function (acc) { acc.__tiNoFit = false; var t = acc.closest('.ti-track'); if (t) t.classList.remove('ti-nofit'); });
        queue();
      }, 150);
    });
    // a picture that loads late can make the block taller
    document.addEventListener('load', function (e) { if (e.target && e.target.closest && e.target.closest('[data-ti-acc]')) queue(); }, true);
    function init(acc) {
      if (acc.__tiReady) return; acc.__tiReady = true;
      if (acc.getAttribute('data-ti-scroll') === '1') { scrollers.push(acc); queue(); }
      var sec = acc.closest('section'); if (sec && +acc.getAttribute('data-ti-auto')) sec.classList.add('ti-auto');
      acc.addEventListener('mouseenter', function () { acc.__tiPause = true; clearTimeout(acc.__tiT); items(acc).forEach(function (it) { it.classList.remove('is-run'); }); });
      acc.addEventListener('mouseleave', function () { acc.__tiPause = false; tick(acc); });
      tick(acc);
    }
    document.addEventListener('click', function (e) {
      var h = e.target.closest && e.target.closest('[data-ti-acc] .ti-head'); if (!h) return;
      var acc = h.closest('[data-ti-acc]'), it = h.closest('.ti-item');
      if (scrollOn(acc)) {
        // clicking an item scrolls to its stretch, so click and scroll never disagree.
        var g = geo(acc), n = items(acc).length;
        var top = g.track.getBoundingClientRect().top + window.pageYOffset - g.top;
        window.scrollTo({ top: top + g.total * (+it.getAttribute('data-i') + 0.05) / n, behavior: 'smooth' });
        return;
      }
      acc.__tiStop = true; clearTimeout(acc.__tiT);
      setOpen(acc, +it.getAttribute('data-i'), !it.classList.contains('is-open'));
    });
    function scan() { document.querySelectorAll('[data-ti-acc]').forEach(init); }
    window.__tiAcc = { scan: scan };
    scan();
    if (window.MutationObserver) new MutationObserver(scan).observe(document.body, { childList: true, subtree: true });
  })();
</script>
@endif
@if($tiJsLb ?? false)
<script>
  // one lightbox for every Text + image section that has "Click image to enlarge" on.
  (function () {
    if (window.__tiLb) return;
    var box, img, cap, prev, next, closeBtn, list = [], at = 0, lastFocus = null, x0 = null;
    function build() {
      box = document.createElement('div');
      box.className = 'ti-lb'; box.setAttribute('role', 'dialog'); box.setAttribute('aria-modal', 'true'); box.setAttribute('aria-label', 'Image');
      box.innerHTML = '<style>'
        + '.ti-lb{position:fixed;inset:0;z-index:9999;background:rgba(5,5,5,.92);display:flex;align-items:center;justify-content:center;opacity:0;pointer-events:none;transition:opacity .25s ease}'
        + '.ti-lb.on{opacity:1;pointer-events:auto}'
        + '.ti-lb figure{margin:0;max-width:92vw;text-align:center;transform:scale(.97);transition:transform .25s ease}'
        + '.ti-lb.on figure{transform:none}'
        + '.ti-lb img{max-width:92vw;max-height:82vh;border-radius:10px;display:block;margin:0 auto;transition:opacity .2s ease}'
        + '.ti-lb figcaption{color:rgba(255,255,255,.75);font-size:14px;margin-top:14px;font-family:inherit}'
        + '.ti-lb button{position:absolute;background:rgba(255,255,255,.08);border:0;border-radius:50%;width:44px;height:44px;cursor:pointer;color:#fff}'
        + '.ti-lb button:hover{background:rgba(255,255,255,.16)}'
        + '.ti-lb .x{top:20px;right:20px}'
        + '.ti-lb .x span{position:absolute;left:13px;top:21px;width:18px;height:2px;border-radius:2px;background:#fff;transition:transform .25s ease}'
        + '.ti-lb .x span:nth-child(1){transform:translateY(-5px)}.ti-lb .x span:nth-child(2){transform:translateY(5px)}'
        + '.ti-lb.on .x span:nth-child(1){transform:rotate(45deg)}.ti-lb.on .x span:nth-child(2){transform:rotate(-45deg)}'
        + '.ti-lb .pv{left:20px;top:50%;margin-top:-22px}.ti-lb .nx{right:20px;top:50%;margin-top:-22px}'
        + '.ti-lb .pv::before,.ti-lb .nx::before{content:"";position:absolute;left:17px;top:16px;width:10px;height:10px;border-left:2px solid #fff;border-bottom:2px solid #fff;transform:rotate(45deg)}'
        + '.ti-lb .nx::before{left:14px;transform:rotate(-135deg)}'
        + '@media (prefers-reduced-motion:reduce){.ti-lb,.ti-lb *{transition:none!important}}'
        + '</style><button type="button" class="x" aria-label="Close"><span></span><span></span></button>'
        + '<button type="button" class="pv" aria-label="Previous image"></button><button type="button" class="nx" aria-label="Next image"></button>'
        + '<figure><img alt=""><figcaption></figcaption></figure>';
      document.body.appendChild(box);
      img = box.querySelector('img'); cap = box.querySelector('figcaption');
      prev = box.querySelector('.pv'); next = box.querySelector('.nx'); closeBtn = box.querySelector('.x');
      closeBtn.addEventListener('click', close);
      prev.addEventListener('click', function () { go(at - 1); });
      next.addEventListener('click', function () { go(at + 1); });
      box.addEventListener('click', function (e) { if (e.target === box) close(); });
      box.addEventListener('touchstart', function (e) { x0 = e.touches[0].clientX; }, { passive: true });
      box.addEventListener('touchend', function (e) {
        if (x0 === null) return; var dx = e.changedTouches[0].clientX - x0; x0 = null;
        if (Math.abs(dx) > 40 && list.length > 1) go(at + (dx < 0 ? 1 : -1));
      });
      document.addEventListener('keydown', function (e) {
        if (!box.classList.contains('on')) return;
        if (e.key === 'Escape') close();
        else if (e.key === 'ArrowLeft' && list.length > 1) go(at - 1);
        else if (e.key === 'ArrowRight' && list.length > 1) go(at + 1);
      });
    }
    function go(i) {
      at = (i + list.length) % list.length;
      img.src = list[at].src; img.alt = list[at].alt || '';
      cap.textContent = list[at].cap || ''; cap.hidden = !list[at].cap;
    }
    function open(items, i) {
      if (!box) build();
      list = items; lastFocus = document.activeElement;
      prev.hidden = next.hidden = list.length < 2;
      go(i);
      box.classList.add('on'); document.documentElement.style.overflow = 'hidden';
      closeBtn.focus();
    }
    function close() {
      box.classList.remove('on'); document.documentElement.style.overflow = '';
      if (lastFocus && lastFocus.focus) lastFocus.focus();
    }
    document.addEventListener('click', function (e) {
      var t = e.target.closest && e.target.closest('[data-ti-lb] img'); if (!t) return;
      var sec = t.closest('[data-ti-lb]');
      var stage = sec.querySelectorAll('.ti-stage img[data-i]');
      var items, i = 0;
      if (stage.length) {
        items = Array.prototype.map.call(stage, function (im) { return { src: im.currentSrc || im.src, alt: im.alt, cap: im.getAttribute('data-cap') || '', i: +im.getAttribute('data-i') }; });
        var item = t.closest('.ti-item');
        var want = item ? +item.getAttribute('data-i') : +t.getAttribute('data-i');
        items.forEach(function (it, k) { if (it.i === want) i = k; });
      } else {
        items = [{ src: t.currentSrc || t.src, alt: t.alt, cap: t.alt || '' }];
      }
      e.preventDefault();
      open(items, i);
    });
    window.__tiLb = true;
  })();
</script>
@endif
