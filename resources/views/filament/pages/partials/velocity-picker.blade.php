{{-- MARKER-VELOCITY-PICKER: the tenant searchable-select (x-tenant.searchable-select), in master
     admin colours: a button, then a panel with "Type to filter", matches highlighted, a tick on
     the current choice and a count. Options A–Z. Params: prop, opts [{v,n}], cur, any, noun, one. --}}
      <div class="vl-ssel" wire:key="{{ $prop }}-picker-{{ $vendor ?: 'all' }}"
           x-data="{
             open: false, q: '', hl: 0, opts: @js($opts), cur: @js($cur),
             get shown() { const q = this.q.trim().toLowerCase(); return q === '' ? this.opts : this.opts.filter(o => o.v.toLowerCase().includes(q)); },
             mark(s) { const q = this.q.trim(); const e = t => t.replace(/[&<>&quot;]/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','&quot;':'&quot;'}[c]));
               if (!q) return e(s); const i = s.toLowerCase().indexOf(q.toLowerCase()); return i < 0 ? e(s) : e(s.slice(0, i)) + '<mark>' + e(s.slice(i, i + q.length)) + '</mark>' + e(s.slice(i + q.length)); },
             toggle() { this.open = !this.open; if (this.open) { this.q = ''; this.hl = 0; this.$nextTick(() => this.$refs.q.focus()); } },
             pick(v) { this.cur = v; this.open = false; $wire.set(@js($prop), v); },
             move(d) { const max = this.shown.length; this.hl = Math.max(0, Math.min(max, this.hl + d)); this.$nextTick(() => { const el = this.$refs.list.querySelector('.is-hl'); if (el) el.scrollIntoView({ block: 'nearest' }); }); },
             enter() { if (this.q.trim() === '' && this.hl === 0) return this.pick(''); const o = this.shown[this.q.trim() === '' ? this.hl - 1 : this.hl]; if (o) this.pick(o.v); }
           }"
           @click.outside="open = false" @keydown.escape.window="open = false">
        <button type="button" class="vl-ssel-btn" @click="toggle()" aria-haspopup="listbox">
          <span :class="cur === '' ? 'is-any' : ''" x-text="cur === '' ? @js($any) : cur">{{ $cur ?: $any }}</span>
          <span class="vl-ssel-chev" aria-hidden="true">&#9662;</span>
        </button>
        <div class="vl-ssel-panel" x-show="open" x-cloak>
          <div class="vl-ssel-search"><input type="text" x-ref="q" x-model="q" @input="hl = 0" placeholder="Type to filter&hellip;" autocomplete="off"
               @keydown.arrow-down.prevent="move(1)" @keydown.arrow-up.prevent="move(-1)" @keydown.enter.prevent="enter()"></div>
          <div class="vl-ssel-list" role="listbox" x-ref="list">
            <template x-if="q.trim() === ''">
              <div class="vl-ssel-opt" :class="{ 'is-sel': cur === '', 'is-hl': hl === 0 }" @click="pick('')" role="option"><span>{{ $any }}</span><span class="vl-ssel-tick">&#10003;</span></div>
            </template>
            <template x-for="(o, i) in shown" :key="o.v">
              <div class="vl-ssel-opt" :class="{ 'is-sel': cur === o.v, 'is-hl': hl === (q.trim() === '' ? i + 1 : i) }" @click="pick(o.v)" role="option">
                <span x-html="mark(o.v)"></span><span class="vl-ssel-n" x-text="o.n.toLocaleString()"></span><span class="vl-ssel-tick">&#10003;</span>
              </div>
            </template>
            <div class="vl-ssel-none" x-show="q.trim() !== '' && shown.length === 0">No {{ $one }} matches “<span x-text="q"></span>”.</div>
          </div>
          <div class="vl-ssel-foot" x-text="q.trim() === '' ? opts.length.toLocaleString() + ' ' + @js($noun) : shown.length.toLocaleString() + ' of ' + opts.length.toLocaleString() + ' ' + @js($noun)"></div>
        </div>
      </div>
