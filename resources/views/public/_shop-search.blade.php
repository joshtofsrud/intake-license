{{-- MARKER-SHOP-SEARCH-V2: the shop search in the site's menu bar.
     On a computer it is a search box in the bar; on a phone a button that opens it.
     As the shopper types: suggestions, category tiles, a promotion and products
     with photos. Empty box: recent searches (kept in their own browser), popular
     searches and categories. Colours come from the site's own look (--p-*).
     Include with: ['ssId' => unique id, 'ssLink' => menu link colour] --}}
@php
  $ssTenant  = $currentTenant ?? tenant();
  $ssDisplay = \App\Support\ShopSearch\SearchConfig::display($ssTenant);
@endphp
<div class="p-ss" id="{{ $ssId }}" data-ss>
  <button type="button" class="p-ss-btn" aria-label="Search the shop" data-ss-open style="color: {{ $ssLink }}">
    <svg width="17" height="17" viewBox="0 0 17 17" fill="none" aria-hidden="true"><circle cx="7.2" cy="7.2" r="5.4" stroke="currentColor" stroke-width="1.7"/><path d="M11.5 11.5L15.5 15.5" stroke="currentColor" stroke-width="1.7" stroke-linecap="round"/></svg>
  </button>
  <div class="p-ss-box">
    <form class="p-ss-field" action="/shop" method="GET" role="search">
      <svg class="p-ss-ic" width="16" height="16" viewBox="0 0 17 17" fill="none" aria-hidden="true"><circle cx="7.2" cy="7.2" r="5.4" stroke="currentColor" stroke-width="1.7"/><path d="M11.5 11.5L15.5 15.5" stroke="currentColor" stroke-width="1.7" stroke-linecap="round"/></svg>
      <input type="search" name="q" placeholder="{{ $ssDisplay['placeholder'] }}" autocomplete="off" aria-label="Search the shop" aria-autocomplete="list" aria-controls="{{ $ssId }}-dd" data-ss-in>
      <button type="button" class="p-ss-x" aria-label="Close search" data-ss-close>✕</button>
    </form>
    <div class="p-ss-dd" id="{{ $ssId }}-dd" role="listbox" data-ss-dd></div>
  </div>
</div>

@once
<style>
  .p-ss { position: relative; display: flex; align-items: center; --p-muted: color-mix(in srgb, var(--p-text, #111) 62%, transparent); }
  .p-ss-btn { display: none; background: none; border: 0; padding: 8px; cursor: pointer; opacity: .8; }
  .p-ss-btn:hover { opacity: 1; }
  .p-ss-box { position: relative; }
  .p-ss-field { display: flex; align-items: center; gap: 8px; width: 250px; transition: width .2s; background: color-mix(in srgb, var(--p-text, #111) 6%, var(--p-bg, #fff)); color: var(--p-text, #111); border: 1px solid color-mix(in srgb, var(--p-text, #111) 12%, transparent); border-radius: 99px; padding: 0 8px 0 14px; height: 40px; margin: 0; }
  .p-ss.open .p-ss-field, .p-ss-field:focus-within { width: 340px; border-color: color-mix(in srgb, var(--p-text, #111) 30%, transparent); }
  .p-ss-ic { flex: none; opacity: .55; }
  .p-ss-field input { flex: 1; min-width: 0; border: 0; background: none; outline: none; font: inherit; font-size: 14px; color: inherit; padding: 0; height: 100%; }
  .p-ss-field input::-webkit-search-cancel-button { display: none; }
  .p-ss-x { display: none; border: 0; background: none; color: inherit; cursor: pointer; font-size: 14px; opacity: .6; padding: 6px; }
  .p-ss-dd { display: none; position: absolute; top: calc(100% + 10px); right: 0; width: min(780px, calc(100vw - 32px)); max-height: min(640px, calc(100vh - 120px)); overflow: auto; z-index: 400; text-align: left;
    background: var(--p-bg, #fff); color: var(--p-text, #111); border: 1px solid color-mix(in srgb, var(--p-text, #111) 12%, transparent); border-radius: var(--p-card-r, 16px); box-shadow: 0 24px 60px rgba(0,0,0,.22); font-family: var(--p-font-body, inherit); }
  .p-ss.open .p-ss-dd.has { display: block; }
  .p-ss-dd a { color: inherit; text-decoration: none; cursor: pointer; }
  .p-ss-cols { display: grid; grid-template-columns: 230px 1fr; }
  .p-ss-cols > div { padding: 14px 16px 16px; }
  .p-ss-cols > .l { border-right: 1px solid color-mix(in srgb, var(--p-text, #111) 9%, transparent); }
  .p-ss-dd h6 { margin: 10px 0 6px; font-size: 11px; text-transform: uppercase; letter-spacing: .09em; font-weight: 700; color: var(--p-muted, #777); }
  .p-ss-dd h6:first-child { margin-top: 2px; }
  .p-ss-sug { display: flex; align-items: center; gap: 9px; padding: 7px 8px; border-radius: 8px; font-size: 14px; }
  .p-ss-sug .i { opacity: .45; width: 14px; text-align: center; flex: none; }
  .p-ss-sug:hover, .p-ss-dd .on { background: color-mix(in srgb, var(--p-text, #111) 7%, transparent); }
  .p-ss-dd mark { background: none; color: inherit; font-weight: 700; }
  .p-ss-tiles { display: grid; grid-template-columns: repeat(auto-fill, minmax(96px, 1fr)); gap: 8px; }
  .p-ss-l .p-ss-tiles { grid-template-columns: 1fr 1fr; }
  .p-ss-tile { display: flex; flex-direction: column; gap: 5px; font-size: 12.5px; font-weight: 600; border-radius: 10px; padding: 4px; }
  .p-ss-tile .p { aspect-ratio: 4/3; border-radius: 8px; overflow: hidden; background: var(--p-tile-bg, #fff); border: 1px solid color-mix(in srgb, var(--p-text, #111) 8%, transparent); display: grid; place-items: center; }
  .p-ss-tile .p img { width: 100%; height: 100%; object-fit: cover; }
  .p-ss-tile .p i { font-style: normal; font-size: 20px; font-weight: 700; color: #bbb; }
  .p-ss-tile small { color: var(--p-muted, #777); font-weight: 500; }
  .p-ss-promo { display: flex; gap: 12px; align-items: center; border-radius: 12px; padding: 8px; margin-bottom: 10px; background: color-mix(in srgb, var(--p-accent, #BEF264) 14%, var(--p-bg, #fff)); border: 1px solid color-mix(in srgb, var(--p-accent, #BEF264) 40%, transparent); }
  .p-ss-promo img { width: 92px; height: 58px; object-fit: cover; border-radius: 8px; flex: none; }
  .p-ss-promo b { display: block; font-size: 14px; line-height: 1.25; }
  .p-ss-promo span { display: block; font-size: 12.5px; color: var(--p-muted, #777); margin-top: 2px; }
  .p-ss-promo .go { margin-left: auto; font-size: 12.5px; font-weight: 700; white-space: nowrap; padding-right: 6px; }
  .p-ss-grid { display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: 10px; }
  .p-ss-prod { display: flex; flex-direction: column; gap: 5px; border-radius: 10px; padding: 6px; }
  .p-ss-prod .im { position: relative; aspect-ratio: var(--p-tile-ratio, 1); background: var(--p-tile-bg, #fff); border-radius: 8px; border: 1px solid color-mix(in srgb, var(--p-text, #111) 8%, transparent); overflow: hidden; }
  .p-ss-prod .im img { width: 100%; height: 100%; object-fit: contain; padding: 8px; }
  .p-ss-prod .im em { position: absolute; top: 6px; left: 6px; font-style: normal; font-size: 10px; font-weight: 700; background: #e5484d; color: #fff; border-radius: 99px; padding: 2px 7px; }
  .p-ss-prod .t { font-size: 13px; line-height: 1.3; display: -webkit-box; -webkit-line-clamp: 2; -webkit-box-orient: vertical; overflow: hidden; }
  .p-ss-prod .pr { font-size: 13px; font-weight: 700; }
  .p-ss-prod .pr s { font-weight: 500; color: var(--p-muted, #777); margin-left: 5px; }
  .p-ss-prod .out { font-size: 11px; color: var(--p-muted, #777); }
  .p-ss-more { display: block; text-align: center; padding: 12px; font-size: 13.5px; font-weight: 650; border-top: 1px solid color-mix(in srgb, var(--p-text, #111) 9%, transparent); }
  .p-ss-corr { padding: 10px 16px; font-size: 13px; color: var(--p-muted, #777); border-bottom: 1px solid color-mix(in srgb, var(--p-text, #111) 9%, transparent); }
  .p-ss-corr a { color: var(--p-text, #111); text-decoration: underline !important; }
  .p-ss-none { padding: 16px 18px 4px; }
  .p-ss-none b { font-size: 15px; }
  .p-ss-none div { font-size: 13px; color: var(--p-muted, #777); margin-top: 3px; }
  .p-ss-rd { display: flex; gap: 12px; align-items: center; padding: 16px 18px; font-size: 14px; }
  .p-ss-rd small { display: block; color: var(--p-muted, #777); }
  .p-ss-wait { padding: 18px; font-size: 13px; color: var(--p-muted, #777); }

  @media (max-width: 1100px) { .p-ss-field { width: 200px; } .p-ss.open .p-ss-field, .p-ss-field:focus-within { width: 280px; } }
  @media (max-width: 900px) {
    .p-ss-btn { display: flex; }
    .p-ss-box { display: none; }
    .p-ss.open .p-ss-box { display: block; position: fixed; inset: 0; z-index: 2000; background: var(--p-bg, #fff); padding: 12px 14px; overflow: auto; }
    .p-ss.open .p-ss-field { width: 100%; }
    .p-ss-x { display: block; }
    .p-ss-dd { position: static; width: auto; max-height: none; border: 0; box-shadow: none; border-radius: 0; margin-top: 8px; }
    .p-ss-cols { grid-template-columns: 1fr; }
    .p-ss-cols > .l { border-right: 0; border-bottom: 1px solid color-mix(in srgb, var(--p-text, #111) 9%, transparent); }
    .p-ss-grid { grid-template-columns: repeat(2, minmax(0, 1fr)); }
  }
</style>
<script>
(function () {
  var D = {!! json_encode(\Illuminate\Support\Arr::only($ssDisplay, ['dropdown', 'photos', 'recent', 'popular'])) !!};
  var KEY = 'shop_recent';
  function esc(x) { var d = document.createElement('div'); d.textContent = x == null ? '' : String(x); return d.innerHTML; }
  function recent() { try { return JSON.parse(localStorage.getItem(KEY) || '[]').slice(0, 5); } catch (e) { return []; } }
  function remember(q) { try { var r = recent().filter(function (x) { return x !== q; }); r.unshift(q); localStorage.setItem(KEY, JSON.stringify(r.slice(0, 5))); } catch (e) {} }
  function hl(s, terms) {
    var out = esc(s);
    (terms || []).filter(function (t) { return t && t.length > 1; }).sort(function (a, b) { return b.length - a.length; }).forEach(function (t) {
      out = out.replace(new RegExp('(' + esc(t).replace(/[.*+?^${}()|[\]\\]/g, '\\$&') + ')', 'ig'), '<mark>$1</mark>');
    });
    return out;
  }
  function go(q) { q = (q || '').trim(); if (!q) return; remember(q); location.href = '/shop?q=' + encodeURIComponent(q); }
  function tiles(list) {
    return '<div class="p-ss-tiles">' + list.map(function (c) {
      return '<a class="p-ss-tile" href="' + esc(c.url) + '" data-nav><span class="p">' + (D.photos && c.img ? '<img src="' + esc(c.img) + '" alt="" loading="lazy">' : '<i>' + esc((c.name || '?').charAt(0)) + '</i>') + '</span>' + esc(c.name) + (c.n ? ' <small>' + c.n + '</small>' : '') + '</a>';
    }).join('') + '</div>';
  }
  function promo(p) {
    if (!p) return '';
    return '<a class="p-ss-promo" href="' + esc(p.url) + '" data-nav>' + (D.photos && p.img ? '<img src="' + esc(p.img) + '" alt="">' : '') + '<div><b>' + esc(p.title) + '</b>' + (p.sub ? '<span>' + esc(p.sub) + '</span>' : '') + '</div><span class="go">' + esc(p.button) + ' →</span></a>';
  }
  function sugs(list, icon, terms) {
    return list.map(function (x) { return '<a class="p-ss-sug" data-go="' + esc(x) + '" data-nav><span class="i">' + icon + '</span><span>' + (terms ? hl(x, terms) : esc(x)) + '</span></a>'; }).join('');
  }

  function init(root) {
    if (root.__ss) return; root.__ss = true;
    var input = root.querySelector('[data-ss-in]'), dd = root.querySelector('[data-ss-dd]'), form = root.querySelector('form');
    var timer = null, seq = 0, cache = {}, qid = null, emptyData = null;

    function open() { root.classList.add('open'); render(); }
    function close() { root.classList.remove('open'); dd.classList.remove('has'); }
    root.querySelector('[data-ss-open]').addEventListener('click', function () { open(); setTimeout(function () { input.focus(); }, 30); });
    root.querySelector('[data-ss-close]').addEventListener('click', close);
    input.addEventListener('focus', open);
    input.addEventListener('input', function () { clearTimeout(timer); timer = setTimeout(render, 160); });
    form.addEventListener('submit', function (e) { e.preventDefault(); var on = dd.querySelector('.on'); if (on) { on.click(); return; } go(input.value); });
    document.addEventListener('click', function (e) { if (!root.contains(e.target)) close(); });
    input.addEventListener('keydown', function (e) {
      if (e.key === 'Escape') { close(); input.blur(); return; }
      if (e.key !== 'ArrowDown' && e.key !== 'ArrowUp') return;
      e.preventDefault();
      var links = Array.prototype.slice.call(dd.querySelectorAll('[data-nav]')), cur = links.indexOf(dd.querySelector('.on'));
      if (cur > -1) links[cur].classList.remove('on');
      var next = e.key === 'ArrowDown' ? cur + 1 : cur - 1;
      if (next < 0 || next >= links.length) return;
      links[next].classList.add('on'); links[next].scrollIntoView({ block: 'nearest' });
    });
    dd.addEventListener('click', function (e) {
      var a = e.target.closest('a'); if (!a) return;
      if (a.hasAttribute('data-go')) { e.preventDefault(); input.value = a.getAttribute('data-go'); go(a.getAttribute('data-go')); return; }
      var item = a.getAttribute('data-item');
      if (item && qid) { try { fetch('/shop/search/click?qid=' + encodeURIComponent(qid) + '&item=' + encodeURIComponent(item), { keepalive: true, credentials: 'same-origin' }); } catch (x) {} }
      if (item) remember(input.value.trim());
    });

    function show(html) { dd.innerHTML = html; dd.classList.toggle('has', !!html); }
    function fetchJson(q, literal) {
      var key = (literal ? '!' : '') + q;
      if (cache[key]) return Promise.resolve(cache[key]);
      return fetch('/shop/search.json?q=' + encodeURIComponent(q) + (literal ? '&literal=1' : ''), { headers: { 'Accept': 'application/json' }, credentials: 'same-origin' })
        .then(function (r) { return r.json(); }).then(function (d) { cache[key] = d; return d; });
    }

    function render(literal) {
      if (!D.dropdown) { show(''); return; }
      var q = input.value.trim(), my = ++seq;
      if (q.length < 2) {
        var draw = function (d) {
          if (my !== seq) return;
          var r = D.recent ? recent() : [], left = '', right = '';
          if (r.length) left += '<h6>Recent</h6>' + sugs(r, '↺');
          if (d.popular && d.popular.length) left += '<h6>Popular</h6>' + sugs(d.popular, '↗');
          if (d.categories && d.categories.length) right += '<h6>Shop by category</h6>' + tiles(d.categories);
          if (d.promo) right = promo(d.promo) + right;
          show(left || right ? '<div class="p-ss-cols"><div class="l p-ss-l">' + (left || '<h6>Search</h6><div class="p-ss-wait" style="padding:6px 0">Type what you are looking for.</div>') + '</div><div class="r">' + right + '</div></div>' : '');
        };
        if (emptyData) draw(emptyData); else fetchJson('').then(function (d) { emptyData = d; draw(d); }).catch(function () {});
        return;
      }
      if (!dd.innerHTML) show('<div class="p-ss-wait">Searching…</div>');
      fetchJson(q, literal).then(function (d) {
        if (my !== seq) return;
        qid = d.qid || null;
        if (d.redirect) {
          show('<a class="p-ss-rd" href="' + esc(d.redirect.url) + '" data-nav><span>→</span><span><b>' + esc(d.redirect.label) + '</b><small>Go to that page</small></span></a>');
          return;
        }
        var items = d.items || [];
        if (!items.length) {
          var h = '<div class="p-ss-none"><b>No matches for "' + esc(q) + '"</b><div>Try one of these instead, or press Enter to see more.</div></div><div class="p-ss-cols"><div class="l p-ss-l">';
          h += (d.popular && d.popular.length ? '<h6>Popular</h6>' + sugs(d.popular, '↗') : '') + '</div><div class="r">';
          if (emptyData && emptyData.categories && emptyData.categories.length) h += '<h6>Shop by category</h6>' + tiles(emptyData.categories.slice(0, 4));
          show(h + '</div></div>');
          if (!emptyData) fetchJson('').then(function (e) { emptyData = e; }).catch(function () {});
          return;
        }
        var terms = d.highlight || [];
        var html = d.corrected ? '<div class="p-ss-corr">Showing results for <b>' + esc(d.corrected) + '</b>. <a data-literal>Search for "' + esc(q) + '" instead</a></div>' : '';
        var left = '<h6>Suggestions</h6>' + sugs(d.suggestions && d.suggestions.length ? d.suggestions : [q], '⌕', terms);
        if (d.categories && d.categories.length) left += '<h6>Categories</h6>' + tiles(d.categories);
        var right = promo(d.promo) + '<h6>Products</h6><div class="p-ss-grid">' + items.map(function (i) {
          return '<a class="p-ss-prod" href="' + esc(i.url) + '" data-item="' + esc(i.id) + '" data-nav><span class="im">' + (i.img ? '<img src="' + esc(i.img) + '" alt="" loading="lazy">' : '') + (i.was ? '<em>Sale</em>' : '') + '</span>' +
            '<span class="t">' + hl(i.name, terms) + '</span><span class="pr">' + esc(i.price_text) + (i.was_text ? '<s>' + esc(i.was_text) + '</s>' : '') + '</span>' + (i.state === 'out' ? '<span class="out">Out of stock</span>' : '') + '</a>';
        }).join('') + '</div>';
        html += '<div class="p-ss-cols"><div class="l p-ss-l">' + left + '</div><div class="r">' + right + '</div></div>';
        html += '<a class="p-ss-more" data-go="' + esc(q) + '" data-nav>See all ' + d.total + ' result' + (d.total === 1 ? '' : 's') + ' →</a>';
        show(html);
        var lit = dd.querySelector('[data-literal]');
        if (lit) lit.addEventListener('click', function (e) { e.preventDefault(); e.stopPropagation(); remember(q); location.href = '/shop?literal=1&q=' + encodeURIComponent(q); });
      }).catch(function () { if (my === seq) show(''); });
    }
  }
  function boot() { document.querySelectorAll('[data-ss]').forEach(init); }
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', boot); else boot();
})();
</script>
@endonce
