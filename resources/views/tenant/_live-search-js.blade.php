{{-- Live search without a page reload (inlined so it can never 404 or be cached stale). --}}
<script>
/* Live search without a page reload.
   Typing in the box fetches the same GET URL, swaps only the result region and
   leaves the input node untouched, so focus and the phone keyboard stay put.
   Usage: IntakeLiveSearch.bind({ form, input, region, delay, onSwap })
   region = element whose innerHTML is replaced (must have an id present in the response). */
(function () {
  'use strict';
  function bind(o) {
    var form = o.form, input = o.input, region = o.region;
    if (!form || !input || !region) { return; }
    var timer = null, seq = 0, delay = o.delay || 300;

    function url() {
      var p = new URLSearchParams(new FormData(form));
      p.delete('page');
      var qs = p.toString();
      return (form.getAttribute('action') || location.pathname) + (qs ? '?' + qs : '');
    }
    function run() {
      var mine = ++seq, u = url();
      fetch(u, { headers: { 'Accept': 'text/html' }, credentials: 'same-origin' })
        .then(function (r) { if (!r.ok) { throw new Error('http ' + r.status); } return r.text(); })
        .then(function (html) {
          if (mine !== seq) { return; }               // a newer keystroke already won
          var doc = new DOMParser().parseFromString(html, 'text/html');
          var next = doc.getElementById(region.id);
          if (!next) { throw new Error('region missing'); }
          if (o.keepForm) {
            // list shares its box with the search form: swap everything except the form
            var oldKids = Array.prototype.slice.call(region.children), newKids = Array.prototype.slice.call(next.children);
            oldKids.forEach(function (k) { if (k !== form && !k.contains(input)) { k.remove(); } });
            var anchor = form;
            newKids.forEach(function (k) { if (k.id !== form.id && !k.contains(k.querySelector('#' + input.id))) { anchor.after(k); anchor = k; } });
            var nf = next.querySelector('#' + form.id), oc = form.querySelector('.ib-search-clear'), nc = nf && nf.querySelector('.ib-search-clear');
            if (oc && !nc) { oc.remove(); } else if (!oc && nc) { form.appendChild(nc); }
          } else {
            region.innerHTML = next.innerHTML;
          }
          if (o.onSwap) { o.onSwap(doc); }
          try { history.replaceState(null, '', u); } catch (e) {}
        })
        .catch(function () { if (mine === seq) { form.submit(); } });   // fall back to a normal search
    }
    input.addEventListener('input', function () { clearTimeout(timer); timer = setTimeout(run, delay); });
    form.addEventListener('submit', function (e) { e.preventDefault(); clearTimeout(timer); run(); });
  }
  window.IntakeLiveSearch = { bind: bind };
})();
</script>
