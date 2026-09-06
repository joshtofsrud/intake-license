{{-- MARKER-SSEL — reusable searchable select. Renders a hidden input under
     `name`, so any form using a native <select> can swap in without touching
     its controller. Options are server-rendered; JS only filters/highlights.
     Styling reads the app CSS vars, so it follows the tenant theme. --}}
{{-- MARKER-SSEL-CATS — `options` may be a flat list (value === label, as the
     import screen uses) or an associative array of value => label, which a
     category needs: a uuid value with a readable name. `searchable` turns the
     filter box off for short lists; it also hides itself on a phone. --}}
@props(['name', 'options' => [], 'selected' => '', 'any' => 'Any', 'noun' => 'options', 'searchable' => true, 'required' => false])
@php
  // A list is 0,1,2… in order; anything else is a value => label map, even
  // when its keys happen to be numeric.
  $sselAssoc = ! array_is_list($options);
  $sselOpts = [];
  foreach ($options as $k => $v) {
      // MARKER-SSEL-NUMKEY — PHP casts numeric string keys back to integers,
      // so ['0' => 'Forever'] arrives with $k === 0 and the old is_int() test
      // read it as a FLAT list: value became "Forever", nothing matched the
      // selected "0", and the button rendered blank.
      $sselOpts[] = (is_int($k) && ! $sselAssoc)
          ? ['v' => (string) $v, 'l' => (string) $v]
          : ['v' => (string) $k, 'l' => (string) $v];
  }
  $sselCur = '';
  foreach ($sselOpts as $o) {
      if ($o['v'] === (string) $selected) { $sselCur = $o['l']; break; }
  }
@endphp
<div class="ssel" data-noun="{{ $noun }}" data-name="{{ $name }}">{{-- MARKER-SSEL-SCOPE --}}
  <input type="hidden" name="{{ $name }}" value="{{ $selected }}" class="ssel-val">
  <button type="button" class="ssel-btn" aria-haspopup="listbox">
    <span class="ssel-cur {{ (string) $selected === '' ? 'is-any' : '' }}">{{ $sselCur !== '' ? $sselCur : $any }}</span>
    <span class="ssel-chev" aria-hidden="true">&#9662;</span>
  </button>
  <div class="ssel-panel" hidden>
    @if($searchable)
      <div class="ssel-search"><input type="text" placeholder="Type to filter&hellip;" autocomplete="off"></div>
    @endif
    <div class="ssel-list" role="listbox">
      {{-- MARKER-SSEL-NOBLANK — this row was rendered whatever $any held, so
           any="" produced an empty option in every picker. It exists only when
           there is something to say, e.g. "Any brand" on a filter. --}}
      @if($any !== '')
        <div class="ssel-opt ssel-any {{ (string) $selected === '' ? 'is-sel' : '' }}" data-v="" data-l="{{ $any }}" role="option"><span class="t">{{ $any }}</span><span class="ssel-tick">&#10003;</span></div>
      @endif
      @foreach($sselOpts as $o)
        <div class="ssel-opt {{ (string) $selected === $o['v'] ? 'is-sel' : '' }}" data-v="{{ $o['v'] }}" data-l="{{ $o['l'] }}" role="option"><span class="t">{!! nl2br(e($o['l'])) !!}</span><span class="ssel-tick">&#10003;</span></div>
      @endforeach
    </div>
    <div class="ssel-foot"><span class="ssel-cnt"></span>{{-- MARKER-SSEL-NOHINT — keyboard hint removed --}}</div>
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
      // MARKER-SSEL-NOSEARCH — there is no search box when :searchable=false;
      // this threw and the panel never opened.
      if (input) { input.value = ''; }
      filter('');
      if (input) { input.focus(); }
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
      // MARKER-SSEL-SCOPE — behave like a native select for listeners.
      val.dispatchEvent(new Event('change', { bubbles: true }));
    }

    btn.addEventListener('click', function () { panel.hidden ? open() : close(); });
    if (input) input.addEventListener('input', function () { filter(input.value); });
    if (input) input.addEventListener('keydown', function (e) {
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

    // MARKER-SSEL-SCOPE — replace the option list in place. Keeps the current
    // value when it survives the new list; otherwise resets to the Any row
    // WITHOUT dispatching change (the caller initiated this, no loops).
    function setOptions(labels) {
      opts.forEach(function (o) { if (o !== anyOpt) { o.remove(); } });
      opts = [anyOpt];
      labels.forEach(function (l) {
        var o = document.createElement('div');
        o.className = 'ssel-opt';
        o.setAttribute('role', 'option');
        o.setAttribute('data-v', l);
        o.setAttribute('data-l', l);
        var t = document.createElement('span');
        t.className = 't';
        t.textContent = l;
        var tick = document.createElement('span');
        tick.className = 'ssel-tick';
        tick.textContent = '\u2713';
        o.appendChild(t);
        o.appendChild(tick);
        list.appendChild(o);
        opts.push(o);
      });
      var keep = null;
      opts.forEach(function (o) {
        if (o !== anyOpt && o.getAttribute('data-v') === val.value) { keep = o; }
      });
      if (val.value !== '' && !keep) {
        val.value = '';
        cur.textContent = anyOpt.getAttribute('data-l');
        cur.classList.add('is-any');
      }
      opts.forEach(function (o) {
        o.classList.toggle('is-sel', o === (keep || anyOpt));
      });
      if (none) { none.remove(); none = null; }
    }

    root.__sselApi = { setOptions: setOptions, getValue: function () { return val.value; } };
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

{{-- MARKER-SSEL-CATS-PHONE — a filter box on a phone raises the keyboard over
     the list it is meant to filter. Below 640px the list stands on its own. --}}
<style>
  @media (max-width: 640px) { .ssel-search { display: none !important; } }
</style>
