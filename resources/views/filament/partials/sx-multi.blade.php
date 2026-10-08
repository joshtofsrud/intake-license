{{-- multi-pick dropdown for master admin filters, built to
     behave like the tenant admin's searchable select (x-tenant.searchable-select):
     a search box that filters as you type and highlights the match, ticks on
     picked rows, arrow keys + Enter, and a count in the footer. Alpine owns the
     open/search state (wire:ignore) and the picks are entangled with the
     Livewire property, so the page re-renders without closing the panel.
     Params: model (Livewire array property), opts [[v, l, n]], any, noun. --}}
@php
  $sxmOpts = array_map(fn ($o) => ['v' => (string) $o[0], 'l' => (string) $o[1], 'n' => (int) ($o[2] ?? 0)], $opts ?? []);
@endphp
<div class="sxm" wire:ignore wire:key="sxm-{{ $model }}-{{ $key ?? '' }}" style="min-width:{{ $width ?? 150 }}px"
     x-data="{
       open: false, q: '', hl: 0,
       opts: @js($sxmOpts),
       any: @js($any ?? 'All'), noun: @js($noun ?? 'options'),
       sel: $wire.entangle(@js($model)).live,
       get shown() { const q = this.q.trim().toLowerCase(); return q ? this.opts.filter(o => o.l.toLowerCase().includes(q)) : this.opts; },
       has(v) { return (this.sel || []).includes(v); },
       toggle(v) { const s = [...(this.sel || [])]; const i = s.indexOf(v); if (i < 0) s.push(v); else s.splice(i, 1); this.sel = s; },
       clear() { this.sel = []; },
       esc(s) { const d = document.createElement('div'); d.textContent = s; return d.innerHTML; },
       mark(l) { const q = this.q.trim(); if (!q) return this.esc(l); const at = l.toLowerCase().indexOf(q.toLowerCase()); if (at < 0) return this.esc(l);
                 return this.esc(l.slice(0, at)) + '<mark>' + this.esc(l.slice(at, at + q.length)) + '</mark>' + this.esc(l.slice(at + q.length)); },
       label() { const s = this.sel || []; if (!s.length) return this.any; const ls = s.map(v => (this.opts.find(o => o.v === v) || { l: v }).l); return ls.length <= 2 ? ls.join(', ') : ls.length + ' ' + this.noun; },
       openUp() { this.open = true; this.q = ''; this.hl = 0; this.$nextTick(() => this.$refs.q && this.$refs.q.focus()); },
       move(d) { this.hl = Math.max(0, Math.min(this.shown.length - 1, this.hl + d)); this.$nextTick(() => { const el = this.$refs.list && this.$refs.list.querySelector('.is-hl'); if (el) el.scrollIntoView({ block: 'nearest' }); }); },
     }"
     x-on:click.outside="open = false" x-on:keydown.escape.stop="open = false">
  <button type="button" class="sxm-btn" x-on:click="open ? open = false : openUp()" aria-haspopup="listbox">
    <span class="sxm-cur" :class="(sel || []).length ? '' : 'is-any'" x-text="label()"></span><span class="sxm-chev" aria-hidden="true">&#9662;</span>
  </button>
  <div class="sxm-panel" x-show="open" x-cloak>
    <div class="sxm-search"><input type="text" x-ref="q" x-model="q" placeholder="Type to filter&hellip;" autocomplete="off"
      x-on:input="hl = 0" x-on:keydown.arrow-down.prevent="move(1)" x-on:keydown.arrow-up.prevent="move(-1)"
      x-on:keydown.enter.prevent="shown[hl] && toggle(shown[hl].v)"></div>
    <div class="sxm-list" role="listbox" x-ref="list">
      <template x-for="(o, i) in shown" :key="o.v">
        <div class="sxm-opt" :class="{ 'is-sel': has(o.v), 'is-hl': i === hl }" role="option" :aria-selected="has(o.v)"
             x-on:click="toggle(o.v)" x-on:mouseenter="hl = i">
          <span class="sxm-tick" aria-hidden="true">&#10003;</span>
          <span class="t" x-html="mark(o.l)"></span>
          <span class="n" x-show="o.n" x-text="o.n.toLocaleString()"></span>
        </div>
      </template>
      <div class="sxm-none" x-show="!shown.length" x-text="opts.length ? 'Nothing matches “' + q + '”' : 'Nothing to pick yet'"></div>
    </div>
    <div class="sxm-foot">
      <span x-text="shown.length + ' of ' + opts.length + ' ' + noun"></span>
      <span x-show="(sel || []).length"><span x-text="(sel || []).length + ' picked'"></span> · <button type="button" x-on:click="clear()">Clear</button></span>
    </div>
  </div>
</div>
@once
<style>
/* same shape as the tenant searchable select, in the Prospects page's colours */
.sxm{position:relative}
.sxm-btn{width:100%;display:flex;align-items:center;justify-content:space-between;gap:10px;background-color:rgba(255,255,255,.04);
  border:1px solid var(--sx-line-2,rgba(255,255,255,.14));border-radius:7px;padding:6px 10px;font-size:13px;color:inherit;cursor:pointer;text-align:left;font-family:inherit}
.sxm-btn:hover{border-color:rgba(255,255,255,.28)}
.sxm-cur{white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.sxm-cur.is-any{color:var(--sx-dim,#a3a3ab)}
.sxm-chev{flex:0 0 auto;font-size:10px;opacity:.5}
.sxm-panel{position:absolute;z-index:60;top:calc(100% + 6px);left:0;min-width:260px;width:max-content;max-width:340px;background:#1b1c20;
  border:1px solid var(--sx-line-2,rgba(255,255,255,.14));border-radius:8px;box-shadow:0 16px 40px rgba(0,0,0,.45);overflow:hidden}
.sxm-search{padding:9px;border-bottom:.5px solid var(--sx-line,rgba(255,255,255,.08))}
.sxm-search input{width:100%;box-sizing:border-box;background:rgba(255,255,255,.04);border:1px solid var(--sx-line-2,rgba(255,255,255,.14));border-radius:7px;padding:7px 10px;color:inherit;font-size:13px;font-family:inherit}
.sxm-search input:focus{outline:none;border-color:var(--sx-violet,#8b5cf6)}
.sxm-list{max-height:280px;overflow-y:auto;padding:4px}
.sxm-opt{display:flex;align-items:center;gap:8px;padding:7px 10px;border-radius:6px;font-size:13px;cursor:pointer}
.sxm-opt .t{flex:1}
.sxm-opt .n{font-size:11px;color:var(--sx-faint,#74747d);font-variant-numeric:tabular-nums}
.sxm-opt.is-hl{background:rgba(255,255,255,.06)}
.sxm-opt.is-sel{color:var(--sx-vtext,#b4a0fb);font-weight:600}
.sxm-tick{width:12px;font-size:11px;visibility:hidden}
.sxm-opt.is-sel .sxm-tick{visibility:visible}
.sxm-opt mark{background:transparent;color:var(--sx-vtext,#b4a0fb);font-weight:700}
.sxm-none{padding:16px 12px;font-size:12.5px;color:var(--sx-dim,#a3a3ab);text-align:center}
.sxm-foot{display:flex;justify-content:space-between;gap:10px;padding:7px 11px;border-top:.5px solid var(--sx-line,rgba(255,255,255,.08));font-size:11px;color:var(--sx-dim,#a3a3ab)}
.sxm-foot button{background:none;border:0;color:var(--sx-vtext,#b4a0fb);cursor:pointer;font:inherit;padding:0}
</style>
@endonce
