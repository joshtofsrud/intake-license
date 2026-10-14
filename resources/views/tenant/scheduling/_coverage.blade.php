{{-- MARKER-SCHED-COVERAGE: the week's shifts on the clock, so holes stand out.
     Same shifts as the Grid; nothing here publishes or notifies. --}}
@push('styles')
<style>
.sc-seg{display:inline-flex;border:.5px solid var(--ia-border-2,rgba(255,255,255,.2));border-radius:7px;overflow:hidden}
.sc-seg a{color:var(--ia-text-muted);font-size:12px;font-weight:600;padding:7px 12px;text-decoration:none}
.sc-seg a.on{background:var(--ia-surface-2,#1a1a1a);color:var(--ia-text)}
.sc-is-cov .sc-grid,.sc-is-cov .sc-grid ~ p,.sc-is-cov .scm{display:none !important}
.sc-modal{max-width:calc(100% - 32px)}
.cv{border:.5px solid var(--ia-border);border-radius:12px;background:var(--ia-surface);overflow-x:auto}
.cv-in{display:grid;grid-template-columns:52px repeat(7,minmax(118px,1fr));min-width:900px}
.cv-hd{padding:9px 10px 8px;border-bottom:.5px solid var(--ia-border);border-left:.5px dashed var(--ia-border);background:var(--ia-surface-2,#1a1a1a)}
.cv-hd:first-child{border-left:0}
.cv-hd .d{font-size:10px;text-transform:uppercase;letter-spacing:.05em;color:var(--ia-text-muted);font-weight:600}
.cv-hd .d span{opacity:.6}
.cv-hd .s{font-size:11px;margin-top:3px;color:var(--ia-text-dim)}
.cv-hd .s.ok{color:#86efac}.cv-hd .s.gap{color:#f87171}.cv-hd .s.thin{color:#FBBF24}
.cv-hours{position:relative}
.cv-hours div{position:absolute;right:8px;font-size:10px;color:var(--ia-text-dim);transform:translateY(-50%);font-family:var(--ia-font-mono)}
.cv-day{position:relative;border-left:.5px dashed var(--ia-border)}
.cv-line{position:absolute;left:0;right:0;border-top:.5px solid var(--ia-border);opacity:.55}
.cv-closed{position:absolute;left:0;right:0;background:repeating-linear-gradient(135deg,transparent,transparent 6px,rgba(255,255,255,.025) 6px,rgba(255,255,255,.025) 12px)}
.cv-rail{position:absolute;left:0;width:5px}
.cv-rail.n0{background:#f87171}.cv-rail.n1{background:#FBBF24}.cv-rail.n2{background:rgba(134,239,172,.55)}
.cv-gap{position:absolute;left:8px;right:6px;border:1px dashed rgba(248,113,113,.55);background:rgba(248,113,113,.07);border-radius:6px;color:#f87171;font-size:10.5px;font-weight:600;display:flex;align-items:center;justify-content:center;cursor:pointer;text-align:center;line-height:1.25;overflow:hidden;font-family:inherit;padding:0}
.cv-gap:hover{background:rgba(248,113,113,.14)}
.cv-bar{position:absolute;display:flex;flex-direction:column;justify-content:flex-start;align-items:stretch;border-radius:6px;padding:4px 6px;font-size:10.5px;line-height:1.25;overflow:hidden;cursor:pointer;border:1px solid;text-align:left;font-family:inherit;color:var(--ia-text)}
.cv-bar b{display:block;font-weight:600;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.cv-bar i{display:block;font-style:normal;font-size:9.5px;opacity:.75;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.cv-bar.draft{border-style:dashed;opacity:.85}
.cv-off{position:absolute;border-radius:6px;border:1px dashed rgba(248,113,113,.35);background:repeating-linear-gradient(45deg,transparent,transparent 5px,rgba(248,113,113,.08) 5px,rgba(248,113,113,.08) 10px);color:#f87171;font-size:9.5px;padding:4px 5px;line-height:1.25;overflow:hidden}
.cv-now{position:absolute;left:0;right:0;border-top:1.5px solid var(--ia-accent);z-index:3;pointer-events:none}
.cv-now::before{content:'';position:absolute;left:-4px;top:-4.5px;width:7px;height:7px;border-radius:50%;background:var(--ia-accent)}
.cv-key{display:flex;gap:14px;flex-wrap:wrap;align-items:center;font-size:11.5px;color:var(--ia-text-dim);margin:10px 0 0}
.cv-key > span{display:inline-flex;align-items:center;gap:6px}
.cv-key em{display:inline-block;width:5px;height:12px;border-radius:2px}
.cv-who{display:flex;gap:6px;flex-wrap:wrap;margin-left:auto}
.cv-who button{display:inline-flex;align-items:center;gap:6px;background:none;border:.5px solid var(--ia-border);border-radius:999px;padding:3px 10px 3px 4px;color:var(--ia-text);font:inherit;font-size:11.5px;cursor:pointer}
.cv-who button.off{opacity:.35}
.cv-who i{width:16px;height:16px;border-radius:50%;display:inline-block}
.cv-legend{margin-top:18px;font-size:12.5px;line-height:1.65;color:var(--ia-text-dim);max-width:900px;border-top:.5px solid var(--ia-border);padding-top:12px}
.cv-legend b{color:var(--ia-text);font-weight:500}
.cv-legend a{color:var(--ia-text);text-decoration:underline}
.cv-days{display:none}
.cv-empty{padding:28px;text-align:center;color:var(--ia-text-dim);font-size:13px}
@media (max-width:700px){
  .sc-seg{grid-column:1 / -1;justify-self:start}
  .cv-in{grid-template-columns:44px 1fr;min-width:0}
  .cv-in > .cv-hd:not(:first-child), .cv-in > .cv-day{display:none}
  .cv-in > .cv-hd.on, .cv-in > .cv-day.on{display:block}
  .cv-days{display:flex;gap:6px;overflow-x:auto;margin-bottom:10px;scrollbar-width:none}
  .cv-days button{flex:0 0 auto;background:none;border:.5px solid var(--ia-border);border-radius:9px;padding:5px 10px;color:var(--ia-text-muted);font:inherit;font-size:11.5px;text-align:center;cursor:pointer;position:relative;line-height:1.3}
  .cv-days button.on{border-color:var(--ia-accent);color:var(--ia-text)}
  .cv-days button .dot{position:absolute;top:4px;right:4px;width:6px;height:6px;border-radius:50%}
  .cv-who{margin-left:0}
}
</style>
@endpush

<div class="cv-days" id="cvDays"></div>
<div class="cv"><div class="cv-in" id="cvGrid"></div></div>
<div class="cv-key">
  <span><em style="background:#f87171"></em>Open, nobody on</span>
  @if($coverage['min_on'] > 1)<span><em style="background:#FBBF24"></em>Below {{ $coverage['min_on'] }} on</span>@endif
  <span><em style="background:rgba(134,239,172,.55)"></em>Covered</span>
  <span class="cv-who" id="cvWho"></span>
</div>
<div class="cv-legend">
  <b>What this shows.</b> The same shifts as the Grid, laid out on the clock so holes stand out. The coloured rail on the left of each day is how many people are on at that time, measured against your business hours
  (@if(Route::has('tenant.capacity.index'))<a href="{{ route('tenant.capacity.index') }}">Capacity</a>@else Capacity @endif)
  and the <b>minimum on the floor</b> (<a href="{{ route('tenant.scheduling.settings') }}">Scheduling › Settings</a>, {{ $coverage['min_on'] }} now).
  Red boxes are open hours with nobody scheduled; click one to add a shift that fills it. Click a shift to change or remove it. Dashed shifts are drafts staff can't see yet; approved time off shows hatched.
  @if($coverage['multi_location']) Shifts at every location count together here. @endif
  Nothing on this view sends anything: shifts still go out only when you publish the week.
</div>

{{-- removing a shift from the coverage view --}}
<form method="POST" id="cv-del-form" action="" style="display:none">@csrf</form>

@push('scripts')
<script>
(function () {
  var C = @json($coverage);
  var DELURL = @json(route('tenant.scheduling.shift.delete', '__ID__'));
  var PX = 46 / 60; // px per minute
  var hidden = {}, sel = C.sel;
  function fmt(m) { var h = Math.floor(m / 60) % 24, mm = m % 60, ap = h >= 12 ? 'p' : 'a', h12 = ((h + 11) % 12) + 1; return h12 + (mm ? ':' + String(mm).padStart(2, '0') : '') + ap; }
  function hhmm(m) { m = Math.min(m, 1439); return String(Math.floor(m / 60)).padStart(2, '0') + ':' + String(m % 60).padStart(2, '0'); }
  function y(m) { return (m - C.lo) * PX; }
  function esc(s) { return String(s == null ? '' : s).replace(/[&<>"]/g, function (c) { return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'}[c]; }); }
  var P = {}; C.people.forEach(function (p) { P[p.id] = p; });
  function dayShifts(d) { return C.shifts.filter(function (s) { return s.d === d && !hidden[s.u]; }); }
  function count(d, m) { return dayShifts(d).filter(function (s) { return s.s <= m && s.e > m; }).length; }
  function level(n) { return n === 0 ? 0 : (n < C.min_on ? 1 : 2); }
  function segs(d) {
    var o = C.hours[d], out = []; if (!o) return out;
    for (var m = o[0]; m < o[1]; m += 15) {
      var n = level(count(d, m)), l = out[out.length - 1];
      if (l && l.n === n) l.e = Math.min(m + 15, o[1]); else out.push({ n: n, s: m, e: Math.min(m + 15, o[1]) });
    }
    return out;
  }
  function render() {
    var H = (C.hi - C.lo) * PX, html = '<div class="cv-hd"></div>';
    C.days.forEach(function (day, d) {
      var o = C.hours[d], s;
      if (!o) s = '<div class="s">Closed</div>';
      else {
        var g = segs(d), gap = g.filter(function (x) { return x.n === 0; }), thin = g.filter(function (x) { return x.n === 1; });
        var span = function (a) { return a.map(function (x) { return fmt(x.s) + '–' + fmt(x.e); }).join(', '); };
        s = gap.length ? '<div class="s gap">Gap ' + span(gap) + '</div>'
          : thin.length ? '<div class="s thin">Short ' + span(thin) + '</div>'
          : '<div class="s ok">Covered ' + fmt(o[0]) + '–' + fmt(o[1]) + '</div>';
      }
      html += '<div class="cv-hd ' + (d === sel ? 'on' : '') + '"><div class="d">' + day.w + ' <span>' + day.n + '</span></div>' + s + '</div>';
    });
    html += '<div class="cv-hours" style="height:' + H + 'px">';
    for (var h = C.lo + 60; h < C.hi; h += 60) html += '<div style="top:' + y(h) + 'px">' + fmt(h) + '</div>';
    html += '</div>';
    C.days.forEach(function (day, d) {
      var o = C.hours[d], c = '<div class="cv-day ' + (d === sel ? 'on' : '') + '" style="height:' + H + 'px">';
      for (var h = C.lo + 60; h < C.hi; h += 60) c += '<div class="cv-line" style="top:' + y(h) + 'px"></div>';
      if (!o) c += '<div class="cv-closed" style="top:0;height:' + H + 'px"></div>';
      else {
        c += '<div class="cv-closed" style="top:0;height:' + y(o[0]) + 'px"></div><div class="cv-closed" style="top:' + y(o[1]) + 'px;height:' + (H - y(o[1])) + 'px"></div>';
        segs(d).forEach(function (g) {
          c += '<div class="cv-rail n' + g.n + '" style="top:' + y(g.s) + 'px;height:' + (g.e - g.s) * PX + 'px"></div>';
          if (g.n === 0) c += '<button type="button" class="cv-gap" data-d="' + d + '" data-s="' + g.s + '" data-e="' + g.e + '" style="top:' + (y(g.s) + 2) + 'px;height:' + Math.max(14, (g.e - g.s) * PX - 4) + 'px">' + ((g.e - g.s) >= 60 ? 'Nobody on<br>' + fmt(g.s) + '–' + fmt(g.e) + '<br>+ add shift' : '+ add shift') + '</button>';
        });
      }
      var list = dayShifts(d).slice().sort(function (a, b) { return a.s - b.s || b.e - a.e; });
      var lanes = [];
      list.forEach(function (s) { var i = -1; for (var k = 0; k < lanes.length; k++) { if (lanes[k] <= s.s) { i = k; break; } } if (i < 0) { i = lanes.length; lanes.push(0); } lanes[i] = s.e; s.l = i; });
      var offs = C.off.filter(function (x) { return x.d === d && !hidden[x.u]; });
      var n = Math.max(1, lanes.length + offs.length);
      var w = 'calc((100% - 14px)/' + n + ' - 3px)';
      list.forEach(function (s) {
        var p = P[s.u], col = p.color, l = 'calc(10px + (100% - 14px)/' + n + '*' + s.l + ')';
        c += '<button type="button" class="cv-bar ' + (s.draft ? 'draft' : '') + '" data-id="' + s.id + '" title="' + esc(p.name + ' · ' + fmt(s.s) + '–' + fmt(s.e) + (s.label ? ' · ' + s.label : '') + (s.warn ? ' · outside their availability' : '')) + '" style="top:' + (y(s.s) + 1) + 'px;height:' + Math.max(16, (s.e - s.s) * PX - 2) + 'px;left:' + l + ';width:' + w + ';background:' + col + '22;border-color:' + col + '88"><b style="color:' + col + '">' + esc(p.name) + (s.warn ? ' !' : '') + '</b><i>' + fmt(s.s) + '–' + fmt(s.e) + (s.draft ? ' · draft' : '') + '</i>' + (s.label ? '<i>' + esc(s.label) + '</i>' : '') + '</button>';
      });
      offs.forEach(function (x, i) {
        var top = o ? o[0] : C.lo, bot = o ? o[1] : C.hi, l = 'calc(10px + (100% - 14px)/' + n + '*' + (lanes.length + i) + ')';
        c += '<div class="cv-off" style="top:' + (y(top) + 1) + 'px;height:' + ((bot - top) * PX - 2) + 'px;left:' + l + ';width:' + w + '">' + esc(P[x.u].name) + '<br>Time off</div>';
      });
      if (C.now && C.now.d === d && C.now.m >= C.lo && C.now.m <= C.hi) c += '<div class="cv-now" style="top:' + y(C.now.m) + 'px"></div>';
      html += c + '</div>';
    });
    document.getElementById('cvGrid').innerHTML = html;
    document.getElementById('cvDays').innerHTML = C.days.map(function (day, d) {
      var g = segs(d), col = !C.hours[d] ? 'transparent' : g.some(function (x) { return x.n === 0; }) ? '#f87171' : g.some(function (x) { return x.n === 1; }) ? '#FBBF24' : 'transparent';
      return '<button type="button" class="' + (d === sel ? 'on' : '') + '" data-day="' + d + '">' + day.w + '<br><b>' + day.n + '</b><span class="dot" style="background:' + col + '"></span></button>';
    }).join('');
    document.getElementById('cvWho').innerHTML = C.people.map(function (p) {
      return '<button type="button" class="' + (hidden[p.id] ? 'off' : '') + '" data-p="' + p.id + '"><i style="background:' + p.color + '"></i>' + esc(p.name) + '</button>';
    }).join('');
  }
  document.addEventListener('click', function (ev) {
    var t = ev.target.closest('[data-p],[data-day],.cv-gap,.cv-bar');
    if (!t || !t.closest('.cv, .cv-key, .cv-days')) return;
    if (t.dataset.p) { if (hidden[t.dataset.p]) delete hidden[t.dataset.p]; else hidden[t.dataset.p] = 1; render(); }
    else if (t.dataset.day) { sel = +t.dataset.day; render(); }
    else if (t.classList.contains('cv-gap')) {
      var d = +t.dataset.d;
      window.scCovOpen({ title: 'Add shift · ' + C.days[d].label, date: C.days[d].date, start: hhmm(+t.dataset.s), end: hhmm(+t.dataset.e), pick: true });
    } else {
      var s = C.shifts.filter(function (x) { return x.id === t.dataset.id; })[0]; if (!s) return;
      window.scCovOpen({ title: P[s.u].name + ' · ' + C.days[s.d].label, date: C.days[s.d].date, start: hhmm(s.s), end: hhmm(s.e), uid: s.u, label: s.label, replace: s.id });
    }
  });
  window.scCovRemove = function () {
    var id = document.getElementById('sc-replace').value; if (!id) return;
    iaConfirm('Remove this shift?').then(function (ok) {
      if (!ok) return;
      var f = document.getElementById('cv-del-form'); f.action = DELURL.replace('__ID__', id); f.submit();
    });
  };
  render();
})();
</script>
@endpush
