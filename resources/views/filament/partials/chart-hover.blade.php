{{-- MARKER-CHART-HOVER: hover read-out for master-admin line charts.
     A chart opts in with data-hover-chart on its wrapper plus:
       data-cur    JSON list of values, one per point
       data-prev   JSON list for the comparison window (optional)
       data-labels JSON list of point labels (ISO dates are shown as "Oct 5")
       data-geom   "viewBoxHeight,yBase,yRange,peak" — how the page maps a value to y
       data-name   what the value is ("Visitors")
       data-fmt    "int" or "money"
     Display only: nothing here changes what the chart draws. --}}
<style>
  [data-hover-chart]{position:relative}
  .mth-layer{position:absolute;inset:0;pointer-events:none;z-index:5}
  .mth-line{position:absolute;top:0;width:1px;background:rgba(127,127,127,.55);display:none}
  .mth-dot{position:absolute;width:9px;height:9px;margin:-4.5px 0 0 -4.5px;border-radius:50%;
    background:rgb(var(--primary-500,139 124 246));box-shadow:0 0 0 3px rgba(0,0,0,.45);display:none}
  .mth-dot.prev{background:#a1a1aa;width:7px;height:7px;margin:-3.5px 0 0 -3.5px}
  .mth-tip{position:absolute;display:none;min-width:150px;max-width:230px;padding:9px 11px;border-radius:10px;
    background:#18181b;border:1px solid rgba(255,255,255,.12);box-shadow:0 10px 30px rgba(0,0,0,.45);
    font-size:12px;line-height:1.45;color:#e4e4e7;white-space:nowrap}
  .mth-tip .t{font-size:11px;color:#a1a1aa;margin-bottom:2px}
  .mth-tip .v{font-size:15px;font-weight:700;color:#fafafa;font-variant-numeric:tabular-nums}
  .mth-tip .p{color:#a1a1aa;margin-top:2px;font-variant-numeric:tabular-nums}
  .mth-tip .up{color:#86efac}.mth-tip .down{color:#fca5a5}
.mt-tile{position:relative;transition:background .15s}
  .mt-tile:hover{background:rgba(255,255,255,.07)}
  .mt-tile .hv{font-size:11px;line-height:1.35;margin-top:0;max-height:0;opacity:0;overflow:hidden;transition:max-height .18s,opacity .18s,margin-top .18s;color:#fca5a5}
  .mt-tile .hv.start{color:#a1a1aa}
  .mt-tile:hover .hv{max-height:34px;opacity:1;margin-top:6px}
</style>
<script>
(function () {
  if (window.__mthBound) return; window.__mthBound = true;
  var MON = ['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'];
  function parse(s) { try { var v = JSON.parse(s || '[]'); return Array.isArray(v) ? v : []; } catch (e) { return []; } }
  function lbl(s) {
    var m = /^(\d{4})-(\d{2})-(\d{2})$/.exec(String(s || ''));
    return m ? MON[+m[2] - 1] + ' ' + (+m[3]) : String(s == null ? '' : s);
  }
  function fmt(v, f) {
    v = Number(v) || 0;
    var s = Math.round(v).toLocaleString('en-US');
    return f === 'money' ? '$' + s : s;
  }
  function layer(wrap) {
    var l = wrap.querySelector(':scope > .mth-layer');
    if (!l) {
      l = document.createElement('div'); l.className = 'mth-layer';
      l.innerHTML = '<div class="mth-line"></div><div class="mth-dot prev"></div><div class="mth-dot cur"></div><div class="mth-tip"></div>';
      wrap.appendChild(l);
    }
    return l;
  }
  function hide(wrap) {
    var l = wrap && wrap.querySelector(':scope > .mth-layer');
    if (l) l.querySelectorAll('.mth-line,.mth-dot,.mth-tip').forEach(function (n) { n.style.display = 'none'; });
  }
  var active = null;
  document.addEventListener('mousemove', function (e) {
    var wrap = e.target.closest && e.target.closest('[data-hover-chart]');
    if (active && active !== wrap) { hide(active); active = null; }
    if (!wrap) return;
    var svg = wrap.querySelector('svg'); if (!svg) return;
    var cur = parse(wrap.dataset.cur); var n = cur.length; if (n < 2) return;
    var r = svg.getBoundingClientRect(), w = wrap.getBoundingClientRect();
    if (e.clientX < r.left - 8 || e.clientX > r.right + 8 || e.clientY < r.top - 8 || e.clientY > r.bottom + 8) { hide(wrap); return; }
    active = wrap;
    var g = String(wrap.dataset.geom || '').split(',').map(Number);
    var vbH = g[0] || 200, yBase = g[1] || vbH, yRange = g[2] || vbH, peak = g[3] || 1;
    var i = Math.max(0, Math.min(n - 1, Math.round((e.clientX - r.left) / r.width * (n - 1))));
    var prev = parse(wrap.dataset.prev), labels = parse(wrap.dataset.labels), f = wrap.dataset.fmt || 'int';
    function ypx(v) { return (r.top - w.top) + ((yBase - (Number(v) || 0) / peak * yRange) / vbH) * r.height; }
    var x = (r.left - w.left) + (i / (n - 1)) * r.width;
    var l = layer(wrap);
    var line = l.querySelector('.mth-line'), dc = l.querySelector('.mth-dot.cur'), dp = l.querySelector('.mth-dot.prev'), tip = l.querySelector('.mth-tip');
    line.style.display = 'block'; line.style.left = x + 'px'; line.style.top = (r.top - w.top) + 'px'; line.style.height = r.height + 'px';
    dc.style.display = 'block'; dc.style.left = x + 'px'; dc.style.top = ypx(cur[i]) + 'px';
    var hasPrev = prev.length > i && prev.length > 1;
    if (hasPrev) { dp.style.display = 'block'; dp.style.left = x + 'px'; dp.style.top = ypx(prev[i]) + 'px'; } else { dp.style.display = 'none'; }
    var html = '<div class="t">' + lbl(labels[i] != null ? labels[i] : '') + '</div>'
             + '<div class="v">' + fmt(cur[i], f) + ' <span style="font-weight:500;font-size:12px;color:#a1a1aa">' + (wrap.dataset.name || '') + '</span></div>';
    if (hasPrev) {
      var a = Number(cur[i]) || 0, b = Number(prev[i]) || 0;
      var pct = b > 0 ? Math.round((a - b) / b * 100) : null;
      html += '<div class="p">before: ' + fmt(b, f)
            + (pct === null ? '' : ' <span class="' + (pct > 0 ? 'up' : (pct < 0 ? 'down' : '')) + '">(' + (pct > 0 ? '+' : '') + pct + '%)</span>')
            + '</div>';
    }
    tip.innerHTML = html; tip.style.display = 'block';
    var tw = tip.offsetWidth, th = tip.offsetHeight;
    var tx = x + 14; if (tx + tw > w.width - 4) tx = x - tw - 14; if (tx < 4) tx = 4;
    var ty = Math.max(4, Math.min(ypx(cur[i]) - th - 10, w.height - th - 4));
    tip.style.left = tx + 'px'; tip.style.top = ty + 'px';
  });
  document.addEventListener('mouseleave', function () { if (active) { hide(active); active = null; } });
})();
</script>
