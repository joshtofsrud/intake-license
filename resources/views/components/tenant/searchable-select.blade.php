{{-- MARKER-SSEL — reusable searchable select. Renders a hidden input under
     `name`, so any form using a native <select> can swap in without touching
     its controller. Options are server-rendered; JS only filters/highlights.
     Styling reads the app CSS vars, so it follows the tenant theme. --}}
@props(['name', 'options' => [], 'selected' => '', 'any' => 'Any', 'noun' => 'options'])
<div class="ssel" data-noun="{{ $noun }}">
  <input type="hidden" name="{{ $name }}" value="{{ $selected }}" class="ssel-val">
  <button type="button" class="ssel-btn" aria-haspopup="listbox">
    <span class="ssel-cur {{ $selected === '' ? 'is-any' : '' }}">{{ $selected !== '' ? $selected : $any }}</span>
    <span class="ssel-chev" aria-hidden="true">&#9662;</span>
  </button>
  <div class="ssel-panel" hidden>
    <div class="ssel-search"><input type="text" placeholder="Type to filter&hellip;" autocomplete="off"></div>
    <div class="ssel-list" role="listbox">
      <div class="ssel-opt ssel-any {{ $selected === '' ? 'is-sel' : '' }}" data-v="" data-l="{{ $any }}" role="option"><span class="t">{{ $any }}</span><span class="ssel-tick">&#10003;</span></div>
      @foreach($options as $o)
        <div class="ssel-opt {{ $selected === (string) $o ? 'is-sel' : '' }}" data-v="{{ $o }}" data-l="{{ $o }}" role="option"><span class="t">{{ $o }}</span><span class="ssel-tick">&#10003;</span></div>
      @endforeach
    </div>
    <div class="ssel-foot"><span class="ssel-cnt"></span><span>Enter to pick &middot; Esc to close</span></div>
  </div>
</div>
@once
  @push('styles')
<style>
/* MARKER-SSEL */
.ssel{position:relative}
.ssel-btn{width:100%;display:flex;align-items:center;justify-content:space-between;gap:10px;
  background:var(--ia-input-bg);border:1px solid var(--ia-border);border-radius:var(--ia-r-md);
  padding:9px 11px;color:var(--ia-text);font-size:13px;font-family:inherit;cursor:pointer;text-align:left}
.ssel-btn:hover{border-color:var(--ia-border-strong)}
.ssel-btn:focus-visible,.ssel-search input:focus{outline:none;border-color:var(--ia-accent)}
.ssel-cur{white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.ssel-cur.is-any{color:var(--ia-text-dim)}
.ssel-chev{flex:0 0 auto;font-size:10px;opacity:.5}
.ssel-panel{position:absolute;z-index:80;top:calc(100% + 6px);left:0;right:0;
  background:var(--ia-surface);border:1px solid var(--ia-border-strong);border-radius:var(--ia-r-md);
  box-shadow:0 16px 40px rgba(0,0,0,.45);overflow:hidden}
.ssel-panel[hidden]{display:none}
.ssel-search{padding:9px;border-bottom:.5px solid var(--ia-border)}
.ssel-search input{width:100%;background:var(--ia-input-bg);border:1px solid var(--ia-border);
  border-radius:var(--ia-r-md);padding:8px 10px;color:var(--ia-text);font-size:13px;font-family:inherit}
.ssel-search input::placeholder{color:var(--ia-text-dim)}
.ssel-list{max-height:280px;overflow-y:auto;padding:4px}
.ssel-opt{display:flex;align-items:center;justify-content:space-between;gap:10px;
  padding:7px 10px;border-radius:calc(var(--ia-r-md) - 2px);font-size:13px;cursor:pointer;color:var(--ia-text)}
.ssel-opt.is-hidden{display:none}
.ssel-opt:hover,.ssel-opt.is-hl{background:var(--ia-surface-2)}
.ssel-opt.is-sel{color:var(--ia-accent);font-weight:600}
.ssel-tick{visibility:hidden;font-size:11px}
.ssel-opt.is-sel .ssel-tick{visibility:visible}
.ssel-opt mark{background:transparent;color:var(--ia-accent);font-weight:700}
.ssel-none{padding:16px 12px;font-size:12.5px;color:var(--ia-text-dim);text-align:center}
.ssel-foot{display:flex;justify-content:space-between;gap:10px;padding:7px 11px;
  border-top:.5px solid var(--ia-border);font-size:11px;color:var(--ia-text-dim)}
</style>
  @endpush
<script>
// MARKER-SSEL — one initializer for every .ssel on the page.
(function () {
  if (window.__iaSselInit) { return; }
  window.__iaSselInit = true;

  function escHtml(s) {
    var d = document.createElement('div');
    d.textContent = s;
    return d.innerHTML;
  }

  function init(root) {
    var val    = root.querySelector('.ssel-val');
    var btn    = root.querySelector('.ssel-btn');
    var cur    = root.querySelector('.ssel-cur');
    var panel  = root.querySelector('.ssel-panel');
    var input  = root.querySelector('.ssel-search input');
    var list   = root.querySelector('.ssel-list');
    var cnt    = root.querySelector('.ssel-cnt');
    var noun   = root.getAttribute('data-noun') || 'options';
    var opts   = Array.prototype.slice.call(list.querySelectorAll('.ssel-opt'));
    var anyOpt = list.querySelector('.ssel-any');
    var none   = null;
    var visible = [];
    var hl = 0;

    function open() {
      panel.hidden = false;
      input.value = '';
      filter('');
      input.focus();
    }
    function close() { panel.hidden = true; }

    function setHl(i) {
      hl = Math.max(0, Math.min(visible.length - 1, i));
      visible.forEach(function (o, k) { o.classList.toggle('is-hl', k === hl); });
      if (visible[hl]) { visible[hl].scrollIntoView({ block: 'nearest' }); }
    }

    function filter(q) {
      var ql = q.trim().toLowerCase();
      visible = [];
      opts.forEach(function (o) {
        var label = o.getAttribute('data-l') || '';
        var isAny = o === anyOpt;
        var at = label.toLowerCase().indexOf(ql);
        var show = ql === '' ? true : (!isAny && at !== -1);
        o.classList.toggle('is-hidden', !show);
        var t = o.querySelector('.t');
        if (show && ql !== '' && at !== -1 && !isAny) {
          t.innerHTML = escHtml(label.slice(0, at)) + '<mark>' +
            escHtml(label.slice(at, at + ql.length)) + '</mark>' +
            escHtml(label.slice(at + ql.length));
        } else {
          t.textContent = label;
        }
        if (show) { visible.push(o); }
      });
      var real = opts.length - 1; // minus the Any row
      var shown = ql === '' ? real : visible.length;
      cnt.textContent = ql === '' ? real + ' ' + noun : shown + ' of ' + real + ' ' + noun;
      if (none) { none.remove(); none = null; }
      if (!visible.length) {
        none = document.createElement('div');
        none.className = 'ssel-none';
        none.textContent = 'No ' + noun + ' match \u201C' + q.trim() + '\u201D';
        list.appendChild(none);
      }
      setHl(0);
    }

    function choose(o) {
      var v = o.getAttribute('data-v') || '';
      var l = o.getAttribute('data-l') || '';
      val.value = v;
      cur.textContent = v === '' ? (anyOpt ? anyOpt.getAttribute('data-l') : l) : l;
      cur.classList.toggle('is-any', v === '');
      opts.forEach(function (x) { x.classList.toggle('is-sel', x === o); });
      close();
      btn.focus();
    }

    btn.addEventListener('click', function () { panel.hidden ? open() : close(); });
    input.addEventListener('input', function () { filter(input.value); });
    input.addEventListener('keydown', function (e) {
      if (e.key === 'ArrowDown')      { e.preventDefault(); setHl(hl + 1); }
      else if (e.key === 'ArrowUp')   { e.preventDefault(); setHl(hl - 1); }
      else if (e.key === 'Enter')     { e.preventDefault(); if (visible[hl]) { choose(visible[hl]); } }
      else if (e.key === 'Escape')    { close(); btn.focus(); }
    });
    list.addEventListener('click', function (e) {
      var o = e.target.closest('.ssel-opt');
      if (o) { choose(o); }
    });
    document.addEventListener('click', function (e) {
      if (!root.contains(e.target)) { close(); }
    });
  }

  function boot() {
    document.querySelectorAll('.ssel').forEach(function (r) {
      if (!r.__ssel) { r.__ssel = true; init(r); }
    });
  }
  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', boot);
  } else {
    boot();
  }
})();
</script>
@endonce
