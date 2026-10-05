<x-filament-panels::page>
{{-- MARKER-MKT-NAV — see App\Filament\Pages\SiteNavigation --}}
<style>
  .snv{--snv-line:rgba(127,127,127,.22);--snv-acc:rgb(139,92,246)}
  .snv-legend{border:1px solid rgba(139,92,246,.3);background:rgba(139,92,246,.07);border-radius:12px;padding:12px 16px;font-size:13px;line-height:1.65;margin-bottom:16px}
  .snv-card{border:1px solid var(--snv-line);border-radius:12px;padding:16px;margin-bottom:16px}
  .snv-head{display:flex;align-items:center;justify-content:space-between;gap:12px;margin-bottom:10px}
  .snv-head b{font-size:14px}
  .snv-seg{display:inline-flex;background:rgba(127,127,127,.12);border-radius:8px;padding:2px;gap:2px}
  .snv-seg button{border:0;background:none;color:inherit;opacity:.65;font:inherit;font-size:12px;padding:4px 9px;border-radius:6px;cursor:pointer;white-space:nowrap}
  .snv-seg button.on{opacity:1;background:rgba(139,92,246,.22);font-weight:600}
  .snv-ctl{display:inline-flex;align-items:center;gap:6px;border:1px solid var(--snv-line);border-radius:8px;padding:4px 8px}
  .snv-ctl input[type=color]{width:24px;height:20px;border:0;background:none;padding:0}
  .snv-ctl input[type=range]{width:90px}
  .snv-ctl b{min-width:34px;text-align:right;font-weight:500}
  .snv-frame{width:100%;height:100px;border:1px solid var(--snv-line);border-radius:10px;background:#0a0a0a;display:block;margin:0 auto}
  .snv-frame.phone{width:390px;max-width:100%;height:520px}
  .snv-dim{font-size:12px;opacity:.6}
  .snv-cols,.snv-row{display:grid;grid-template-columns:22px minmax(140px,1fr) minmax(160px,1.2fr) auto auto 64px 26px;gap:10px;align-items:center}
  .snv-cols{font-size:11.5px;opacity:.55;padding:0 4px 8px;border-bottom:1px solid var(--snv-line)}
  .snv-row{padding:9px 4px;border-bottom:1px solid rgba(127,127,127,.12)}
  .snv-row.drag{opacity:.4}.snv-row.over{box-shadow:inset 0 2px 0 var(--snv-acc)}
  .snv-grip{cursor:grab;opacity:.5;text-align:center;user-select:none}
  .snv-type{font-size:10.5px;display:flex;gap:6px;align-items:center;margin-bottom:4px}
  .snv-type b{background:rgba(127,127,127,.15);border-radius:4px;padding:1px 6px;font-weight:600}
  .snv-warn{font-size:10.5px;color:#d97706;border:1px solid rgba(217,119,6,.45);border-radius:99px;padding:0 7px}
  .snv-in{width:100%;min-width:0;border:1px solid var(--snv-line);background:transparent;border-radius:7px;padding:6px 8px;font:inherit;font-size:12.5px;color:inherit}
  .snv-in:focus{outline:none;border-color:var(--snv-acc)}
  .snv-target{font-size:12.5px;opacity:.75;min-width:0}
  .snv-target code{font-size:11.5px}
  .snv-x{background:none;border:0;font-size:18px;opacity:.5;cursor:pointer;color:inherit}.snv-x:hover{opacity:1;color:#dc2626}
  .snv-add{display:flex;gap:10px;padding-top:12px;flex-wrap:wrap;position:relative}
  .snv-btn{border:1px solid var(--snv-line);background:transparent;color:inherit;border-radius:8px;padding:6px 12px;font:inherit;font-size:13px;cursor:pointer}
  .snv-btn--pri{background:var(--snv-acc);border-color:var(--snv-acc);color:#fff;font-weight:600}
  .snv-btn:disabled{opacity:.35;cursor:default}
  .snv-pop{position:absolute;top:calc(100% + 4px);left:0;z-index:20;min-width:260px;border:1px solid var(--snv-line);border-radius:10px;padding:6px;background:var(--snv-pop-bg,#18181b);box-shadow:0 12px 32px rgba(0,0,0,.35)}
  html:not(.dark) .snv{--snv-pop-bg:#fff}
  .snv-pop button{display:flex;justify-content:space-between;gap:10px;width:100%;text-align:left;background:none;border:0;color:inherit;font:inherit;font-size:13px;padding:8px 10px;border-radius:7px;cursor:pointer}
  .snv-pop button:hover{background:rgba(127,127,127,.12)}
  .snv-bar{position:sticky;bottom:0;display:flex;justify-content:flex-end;align-items:center;gap:10px;padding:12px 0}
  @media(max-width:1200px){.snv-cols{display:none}.snv-row{grid-template-columns:22px 1fr 1fr;row-gap:8px}}
</style>

<div class="snv" wire:ignore
     x-data="snvEditor(@js($rows), @js($pages), @js($previewUrl), @js($header))"
     x-on:nav-saved.window="saved = snapshot()"
     x-on:beforeunload.window="if (dirty) { $event.preventDefault(); $event.returnValue = ''; }">

  <div class="snv-legend"><b>This list is the intake.works header</b> — on every marketing page, desktop and phone. Nothing else changes the menu. A page item hides itself while its page is unpublished and follows the page if its address changes. Changes go live when you press Save.</div>

  <div class="snv-card">
    {{-- MARKER-MKT-NAV-FLOAT / MARKER-MKT-NAV-PHONE --}}
    <div class="snv-head" style="flex-wrap:wrap;justify-content:flex-start">
      <b style="margin-right:6px">Header</b>
      <span class="snv-seg" title="Which screen you're setting up"><button type="button" :class="edit==='desktop' && 'on'" @click="edit='desktop'; dev='desktop'; refresh()">Desktop</button><button type="button" :class="edit==='phone' && 'on'" @click="edit='phone'; dev='phone'; refresh()">Phone</button></span>
      <span class="snv-dim" x-show="edit==='phone'" x-text="Object.keys(header.phone || {}).length ? 'Phone has its own settings' : 'Same as desktop'"></span>
      <button type="button" class="snv-btn" style="padding:2px 8px;font-size:11.5px" x-show="edit==='phone' && Object.keys(header.phone || {}).length" @click="header.phone = {}; changed()">Use desktop for all</button>
    </div>
    <div class="snv-head" style="flex-wrap:wrap;justify-content:flex-start">
      <b style="margin-right:6px">Style</b>
      <span class="snv-seg"><button type="button" :class="val('style')==='classic' && 'on'" @click="set('style','classic')">Classic</button><button type="button" :class="val('style')==='float' && 'on'" @click="set('style','float')">Floating</button></span>
      <template x-if="val('style')==='float'">
        <span style="display:inline-flex;gap:8px;flex-wrap:wrap;align-items:center;font-size:12px">
          <label class="snv-ctl">Bar <input type="color" :value="val('bg')" @input="set('bg', $event.target.value)"></label>
          <label class="snv-ctl">Opacity <input type="range" min="0" max="100" :value="val('opacity')" @input="set('opacity', +$event.target.value)"><b x-text="val('opacity') + '%'"></b></label>
          <label class="snv-ctl">Blur <input type="range" min="0" max="30" :value="val('blur')" @input="set('blur', +$event.target.value)"><b x-text="val('blur') + 'px'"></b></label>
          <label class="snv-ctl">Link pill <input type="color" :value="val('pill')" @input="set('pill', $event.target.value)"><input type="range" min="0" max="30" :value="val('pill_strength')" @input="set('pill_strength', +$event.target.value)" title="Pill strength"></label>
          <span class="snv-seg" title="Room around and inside the bar"><template x-for="o in [['tight','Tight'],['normal','Normal'],['roomy','Roomy']]"><button type="button" :class="val('space')===o[0] && 'on'" @click="set('space', o[0])" x-text="o[1]"></button></template></span>
          <label class="snv-ctl" title="Blur and fade the page as it scrolls up behind the bar"><input type="checkbox" :checked="!!val('fade')" @change="set('fade', $event.target.checked)"> Fade under</label>
        </span>
      </template>
      <span class="snv-ctl" style="font-size:12px">Links
        <input type="color" :value="val('link') || '#cccccc'" @input="set('link', $event.target.value)">
        <button type="button" class="snv-btn" style="padding:2px 8px;font-size:11.5px" x-show="val('link')" @click="set('link', '')">Auto</button>
        <span x-show="!val('link')" style="opacity:.6">Auto</span>
      </span>
    </div>
    <div class="snv-head"><b>Preview</b>
      <span class="snv-seg"><button type="button" :class="dev==='desktop' && 'on'" @click="dev='desktop'; refresh()">Desktop</button><button type="button" :class="dev==='phone' && 'on'" @click="dev='phone'; refresh()">Phone</button></span></div>
    <iframe class="snv-frame" :class="dev==='phone' && 'phone'" x-ref="frame" title="Header preview" @load="openPanel()"></iframe>
    <div class="snv-dim" style="margin-top:8px">Drawn by the site's own header code from the list and style above, including unsaved changes. Scroll inside it to see Floating over the page.</div>
  </div>

  <div class="snv-card">
    <div class="snv-cols"><span></span><span>Item</span><span>Goes to</span><span>Style</span><span>Side</span><span>New tab</span><span></span></div>

    <template x-for="(r, i) in rows" :key="i + '-' + (r.page || r.url) + '-' + rows.length">
      <div class="snv-row" draggable="true"
           @dragstart="from = i; $el.classList.add('drag')" @dragend="from = null; $el.classList.remove('drag')"
           @dragover.prevent="$el.classList.add('over')" @dragleave="$el.classList.remove('over')"
           @drop.prevent="$el.classList.remove('over'); move(from, i)">
        <span class="snv-grip" title="Drag to reorder">⋮⋮</span>
        <div>
          <div class="snv-type"><b x-text="r.type === 'page' ? 'Page' : 'Link'"></b>
            <template x-if="r.type === 'page' && pages[r.page] && !pages[r.page].published"><span class="snv-warn">hidden — page unpublished</span></template>
            <template x-if="r.type === 'page' && !pages[r.page]"><span class="snv-warn">page deleted — remove this row</span></template>
          </div>
          <input class="snv-in" x-model="r.label" @input="changed()" :placeholder="r.type === 'page' && pages[r.page] ? pages[r.page].title : 'Label'" maxlength="40">
        </div>
        <div>
          <template x-if="r.type === 'page'"><div class="snv-target"><span x-text="pages[r.page] ? pages[r.page].title + ' page · ' : ''"></span><code x-text="pages[r.page] ? pages[r.page].path : ''"></code></div></template>
          <template x-if="r.type === 'link'"><input class="snv-in" x-model="r.url" @input="changed()" placeholder="/path or https://…" maxlength="255"></template>
        </div>
        <span class="snv-seg">
          <template x-for="o in [['link','Link'],['button','Button'],['outline','Outline']]"><button type="button" :class="r.style===o[0] && 'on'" @click="r.style=o[0]; changed()" x-text="o[1]"></button></template>
        </span>
        <span class="snv-seg">
          <template x-for="o in [['left','Left'],['right','Right']]"><button type="button" :class="r.side===o[0] && 'on'" @click="r.side=o[0]; changed()" x-text="o[1]"></button></template>
        </span>
        <label style="display:flex;justify-content:center"><input type="checkbox" x-model="r.tab" @change="changed()" title="Open in a new tab"></label>
        <button type="button" class="snv-x" @click="rows.splice(i, 1); changed()" title="Remove from the menu">×</button>
      </div>
    </template>
    <div class="snv-dim" style="padding:12px 4px" x-show="!rows.length">The menu is empty — the header shows just the logo.</div>

    <div class="snv-add">
      <div style="position:relative" @click.outside="pop=false">
        <button type="button" class="snv-btn" @click="pop=!pop">+ Add a page</button>
        <div class="snv-pop" x-show="pop" x-cloak>
          <template x-for="id in freePages()" :key="id">
            <button type="button" @click="addPage(id)"><span x-text="pages[id].title + (pages[id].published ? '' : ' (unpublished)')"></span><code x-text="pages[id].path" style="opacity:.6;font-size:11px"></code></button>
          </template>
          <div class="snv-dim" style="padding:8px 10px" x-show="!freePages().length">Every page is already in the menu.</div>
        </div>
      </div>
      <button type="button" class="snv-btn" @click="rows.push({type:'link',page:null,label:'',url:'',style:'link',side:'left',tab:false}); changed()">+ Add a link or button</button>
    </div>
  </div>

  <div class="snv-bar">
    <span class="snv-dim" x-text="dirty ? 'Unsaved changes — the live menu hasn\u2019t changed yet' : 'All changes saved'"></span>
    <button type="button" class="snv-btn" :disabled="!dirty" @click="var s = JSON.parse(saved); rows = s.rows; header = s.header; changed()">Discard</button>
    <button type="button" class="snv-btn snv-btn--pri" :disabled="!dirty" @click="$wire.save(JSON.parse(JSON.stringify(rows)), JSON.parse(JSON.stringify(header)))">Save</button>
  </div>
</div>

<script>
  function snvEditor(rows, pages, previewUrl, header) {
    return {
      rows: rows, pages: pages, header: header, saved: JSON.stringify({rows: rows, header: header}), dev: 'desktop', pop: false, from: null, t: null,
      snapshot() { return JSON.stringify({rows: this.rows, header: this.header}); },
      // MARKER-MKT-NAV-PHONE — read/write the setting for the screen being edited.
      edit: 'desktop',
      val(k) { var p = this.header.phone || {}; return (this.edit === 'phone' && k in p) ? p[k] : this.header[k]; },
      set(k, v) {
        if (this.edit === 'phone') {
          if (!this.header.phone || Array.isArray(this.header.phone)) this.header.phone = {};
          if (v === this.header[k]) { delete this.header.phone[k]; this.header.phone = Object.assign({}, this.header.phone); }
          else this.header.phone = Object.assign({}, this.header.phone, {[k]: v});
        } else {
          this.header[k] = v;
        }
        this.changed();
      },
      get dirty() { return this.snapshot() !== this.saved; },
      init() { this.refresh(); },
      changed() { clearTimeout(this.t); this.t = setTimeout(() => this.refresh(), 200); },
      refresh() {
        var d = btoa(unescape(encodeURIComponent(JSON.stringify(this.rows))));
        var h = btoa(unescape(encodeURIComponent(JSON.stringify(this.header))));
        this.$refs.frame.src = previewUrl + '?d=' + encodeURIComponent(d) + '&h=' + encodeURIComponent(h);
      },
      openPanel() {
        if (this.dev !== 'phone') return;
        try { var p = this.$refs.frame.contentDocument.getElementById('mk-mobile-nav'); if (p) p.classList.add('open'); } catch (e) {}
      },
      move(a, b) { if (a === null || a === b) return; var m = this.rows.splice(a, 1)[0]; this.rows.splice(b, 0, m); this.changed(); },
      freePages() { var used = this.rows.filter(r => r.type === 'page').map(r => r.page); return Object.keys(this.pages).filter(id => used.indexOf(id) < 0); },
      addPage(id) {
        var at = this.rows.filter(r => r.side === 'left').length;
        this.rows.splice(at, 0, {type:'page', page:id, label:'', url:'', style:'link', side:'left', tab:false});
        this.pop = false; this.changed();
      },
    };
  }
</script>
</x-filament-panels::page>
