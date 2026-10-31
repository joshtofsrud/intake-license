<x-filament-panels::page>
{{-- MARKER-VELOCITY: distributor stock velocity. Hairlines, no cards (the Sales-page vocabulary). --}}
<style>
  .vl-root { --line:rgba(255,255,255,.075); --line2:rgba(255,255,255,.14); --dim:#a3a3ab; --faint:#74747d; --txt:#fff;
    --violet:#8b5cf6; --vtext:#a78bfa; --vsoft:rgba(139,92,246,.17); --ok:#86efac; --oksoft:rgba(134,239,172,.12);
    --amber:#fbbf24; --red:#f47c7c; --hover:rgba(255,255,255,.035); --pop:#18181b; font-size:14px; }
  html:not(.dark) .vl-root { --line:rgba(0,0,0,.08); --line2:rgba(0,0,0,.16); --dim:#52525b; --faint:#6b6b75; --txt:#18181b;
    --vtext:#6d28d9; --vsoft:rgba(139,92,246,.11); --ok:#15803d; --oksoft:rgba(22,163,74,.1);
    --amber:#b45309; --red:#dc2626; --hover:rgba(0,0,0,.025); --pop:#fff; }
  .vl-root .sub { color:var(--dim); max-width:780px; line-height:1.55; margin:0 }
  .vl-tabs { display:flex; gap:22px; border-bottom:1px solid var(--line); overflow-x:auto; }
  .vl-tabs button { background:none; border:0; border-bottom:2px solid transparent; padding:9px 0; color:var(--dim); cursor:pointer; white-space:nowrap; font:inherit; font-weight:500; margin-bottom:-1px; }
  .vl-tabs button.on { color:var(--txt); border-bottom-color:var(--violet); }
  .vl-tabs button span { color:var(--faint); font-weight:400; margin-left:4px; }
  .vl-legend { color:var(--faint); font-size:12.5px; line-height:1.6; padding:11px 0; border-bottom:1px solid var(--line); }
  .vl-legend b { color:var(--dim); font-weight:600; }
  .vl-sum { display:flex; gap:34px; flex-wrap:wrap; padding:6px 0; }
  .vl-sum b { display:block; font-size:26px; font-weight:700; letter-spacing:-.02em; color:var(--txt); line-height:1.15; }
  .vl-sum span { font-size:12.5px; color:var(--dim); }
  .vl-sum em { font-style:normal; font-size:12px; color:var(--faint); display:block; }
  .vl-bar { display:flex; gap:10px; align-items:center; flex-wrap:wrap; }
  .vl-in { background:transparent; border:1px solid var(--line2); border-radius:7px; padding:6px 10px; font:inherit; font-size:13px; color:inherit; min-width:200px; }
  /* one chevron: Filament's form CSS also paints one on every select and it tiles under our background */
  .vl-root select.vl-in { -webkit-appearance:none; appearance:none; padding-right:30px; background-color:transparent;
    background-image:url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 12 12' fill='none' stroke='%23a3a3ab' stroke-width='1.5' stroke-linecap='round' stroke-linejoin='round'%3E%3Cpath d='M3 4.5l3 3 3-3'/%3E%3C/svg%3E") !important;
    background-repeat:no-repeat !important; background-position:right 10px center !important; background-size:12px 12px !important; }
  .vl-root select.vl-in option { background:var(--pop); color:var(--txt); }
  /* brand picker: the tenant searchable-select, in this page's colours */
  .vl-ssel { position:relative; min-width:190px; max-width:240px; }
  .vl-ssel-btn { width:100%; display:flex; align-items:center; justify-content:space-between; gap:10px; background:transparent; border:1px solid var(--line2);
    border-radius:7px; padding:6px 10px; color:inherit; font:inherit; font-size:13px; cursor:pointer; text-align:left; }
  .vl-ssel-btn:hover { border-color:var(--dim); }
  .vl-ssel-btn > span:first-child { white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
  .vl-ssel-btn .is-any { color:var(--dim); }
  .vl-ssel-chev { flex:0 0 auto; font-size:10px; opacity:.5; }
  .vl-ssel-panel { position:absolute; z-index:40; top:calc(100% + 6px); left:0; width:280px; background:var(--pop); border:1px solid var(--line2);
    border-radius:8px; box-shadow:0 16px 40px rgba(0,0,0,.35); overflow:hidden; }
  .vl-ssel-search { padding:9px; border-bottom:1px solid var(--line); }
  .vl-ssel-search input { width:100%; background:transparent; border:1px solid var(--line2); border-radius:7px; padding:7px 10px; color:inherit; font:inherit; font-size:13px; }
  .vl-ssel-search input:focus { outline:none; border-color:var(--violet); }
  .vl-ssel-list { max-height:280px; overflow-y:auto; padding:4px; }
  .vl-ssel-opt { display:flex; align-items:center; gap:10px; padding:7px 10px; border-radius:6px; font-size:13px; cursor:pointer; color:var(--txt); }
  .vl-ssel-opt > span:first-child { flex:1; min-width:0; overflow:hidden; text-overflow:ellipsis; white-space:nowrap; }
  .vl-ssel-opt:hover, .vl-ssel-opt.is-hl { background:var(--hover); }
  .vl-ssel-opt.is-sel { color:var(--vtext); font-weight:600; }
  .vl-ssel-opt mark { background:transparent; color:var(--vtext); font-weight:700; }
  .vl-ssel-n { font-size:11.5px; color:var(--faint); font-variant-numeric:tabular-nums; }
  .vl-ssel-tick { visibility:hidden; font-size:11px; } .vl-ssel-opt.is-sel .vl-ssel-tick { visibility:visible; }
  .vl-ssel-none { padding:16px 12px; font-size:12.5px; color:var(--dim); text-align:center; }
  .vl-ssel-foot { padding:7px 11px; border-top:1px solid var(--line); font-size:11px; color:var(--dim); }
  .vl-seg { display:inline-flex; border:1px solid var(--line2); border-radius:8px; padding:2px; gap:2px; flex-wrap:wrap; }
  .vl-seg button { background:none; border:0; color:var(--dim); padding:4px 10px; border-radius:6px; font:inherit; font-size:12.5px; cursor:pointer; white-space:nowrap; }
  .vl-seg button.on { color:var(--txt); background:var(--vsoft); font-weight:600; }
  .vl-sort { background:none; border:0; padding:0; font:inherit; color:inherit; cursor:pointer; white-space:nowrap; }
  .vl-sort:hover, .vl-sort.on { color:var(--txt); }
  .vl-sort i { font-style:normal; color:var(--vtext); margin-left:3px; }
  .vl-chk { font-size:12.5px; color:var(--dim); display:inline-flex; gap:6px; align-items:center; cursor:pointer; margin-left:auto; }
  .vl-tbl { width:100%; border-collapse:collapse; font-size:13.5px; }
  .vl-tbl th { font-size:11.5px; font-weight:500; color:var(--faint); text-align:left; padding:8px 10px; border-bottom:1px solid var(--line2); white-space:nowrap; }
  .vl-tbl td { padding:10px; border-bottom:1px solid var(--line); vertical-align:middle; }
  .vl-tbl .r { text-align:right; font-variant-numeric:tabular-nums; }
  .vl-tbl tbody tr { cursor:pointer; } .vl-tbl tbody tr:hover { background:var(--hover); }
  .vl-nm b { font-weight:600; color:var(--txt); display:block; } .vl-nm span { font-size:12px; color:var(--faint); }
  .vl-mut { color:var(--faint); }
  .vl-dl { font-weight:700; } .vl-dl.red { color:var(--red); } .vl-dl.amber { color:var(--amber); }
  .vl-chip { display:inline-flex; font-size:11.5px; padding:1px 7px; border-radius:4px; white-space:nowrap; border:1px solid var(--line2); color:var(--faint); }
  .vl-chip.ok { background:var(--oksoft); color:var(--ok); border-color:transparent; }
  .vl-chip.v { background:var(--vsoft); color:var(--vtext); border-color:transparent; }
  .vl-chip.warn { background:rgba(251,191,36,.13); color:var(--amber); border-color:transparent; }
  .vl-btn { border:1px solid var(--line2); background:none; border-radius:7px; padding:6px 12px; font:inherit; font-weight:500; font-size:13px; cursor:pointer; color:inherit; }
  .vl-btn:hover { background:var(--hover); }
  .vl-btn.p { background:var(--violet); border-color:var(--violet); color:#fff; }
  .vl-btn[disabled] { opacity:.45; cursor:default; }
  .vl-empty { padding:40px 0; text-align:center; color:var(--dim); }
  .vl-empty b { display:block; color:var(--txt); margin-bottom:6px; font-size:15px; }
  .vl-cov { display:grid; grid-template-columns:repeat(auto-fit,minmax(200px,1fr)); border-bottom:1px solid var(--line); }
  .vl-cov > div { padding:14px 18px 14px 0; }
  .vl-cov b { color:var(--txt); } .vl-cov p { margin:5px 0 0; font-size:12.5px; color:var(--dim); line-height:1.6; }
  .vl-set .row { display:flex; gap:18px; padding:15px 0; border-bottom:1px solid var(--line); align-items:flex-start; max-width:780px; }
  .vl-set .row > div:first-child { flex:1; }
  .vl-set .row b { display:block; color:var(--txt); font-weight:600; }
  .vl-set .row span { font-size:12.5px; color:var(--dim); line-height:1.5; }
  .vl-sw { position:relative; width:40px; height:22px; flex:none; margin-top:2px; }
  .vl-sw input { opacity:0; width:0; height:0; position:absolute; }
  .vl-sw i { position:absolute; inset:0; border-radius:99px; background:var(--line2); cursor:pointer; transition:background .12s; }
  .vl-sw i::after { content:''; position:absolute; left:3px; top:3px; width:16px; height:16px; border-radius:50%; background:#fff; box-shadow:0 1px 2px rgba(0,0,0,.3); transition:left .12s; }
  .vl-sw input:checked + i { background:var(--violet); } .vl-sw input:checked + i::after { left:21px; }
  .vl-dr-bg { position:fixed; inset:0; background:rgba(0,0,0,.35); z-index:60; }
  .vl-dr { position:fixed; top:0; right:0; bottom:0; width:min(480px,100%); background:var(--pop); z-index:61; padding:24px 26px; overflow:auto; box-shadow:-20px 0 40px rgba(0,0,0,.18); }
  .vl-dr dl { display:grid; grid-template-columns:150px 1fr; margin:18px 0; }
  .vl-dr dt, .vl-dr dd { padding:9px 0; border-bottom:1px solid var(--line); margin:0; font-size:13.5px; }
  .vl-dr dt { color:var(--faint); } .vl-dr dd { color:var(--txt); }
</style>

@php
  $vendorCounts = $this->vendorCounts();
  $allCount = array_sum($vendorCounts);
  $confWord = ['good' => 'Good', 'building' => 'Building', 'early' => 'Too early'];
  // admin time zone when that helper is present, UTC otherwise
  $at = fn ($v, $f) => $v ? (function_exists('alocal') ? alocal($v, $f) : \Carbon\Carbon::parse($v)->format($f)) : '—';
  $tzLabel = function_exists('admin_tz_label') ? admin_tz_label() : 'UTC';
@endphp

<div class="vl-root" style="display:flex;flex-direction:column;gap:18px">
  <p class="sub">How fast each distributor's stock is moving, how many days it should last at that pace, and when more is due. Read from each distributor every 4 hours — no shop sales data is used here.</p>

  <div class="vl-tabs">
    <button type="button" wire:click="setTab('items')" class="{{ $tab === 'items' ? 'on' : '' }}">Items</button>
    <button type="button" wire:click="setTab('coverage')" class="{{ $tab === 'coverage' ? 'on' : '' }}">Data coverage</button>
    <button type="button" wire:click="setTab('shops')" class="{{ $tab === 'shops' ? 'on' : '' }}">Shop visibility</button>
  </div>

  @if ($tab === 'items')
    @php $s = $this->stats(); $res = $this->rows(); @endphp
    <div class="vl-legend">
      <b>What this shows.</b> Each distributor's whole catalog, read every 4 hours with the master admin logins (a sweep). Only sweep readings move an item's pace; the per-shop sync adds arrival dates.
      <b>Days left</b> = stock ÷ average daily drop over roughly the last month. Only drops count; a restock is never read as a sale.
      <b>Next arrival</b> is the earliest date a distributor's warehouse sends; blank means none was sent.
      <b>History</b>: Too early (under half a day of readings), Building, or Good (21+ days). Nothing on this page is shown to shops — see Shop visibility.
    </div>

    <div class="vl-sum">
      <div><b>{{ number_format($s['items']) }}</b><span>Items tracked</span><em>{{ $vendor ?: 'all distributors' }}</em></div>
      <div><b>{{ number_format($s['with_rate']) }}</b><span>With a pace</span><em>{{ $s['items'] ? round($s['with_rate'] / $s['items'] * 100) : 0 }}% — fills in as sweeps run</em></div>
      <div><b>{{ number_format($s['under14']) }}</b><span>Under 14 days left</span><em>at current pace</em></div>
      <div><b>{{ number_format($s['due7']) }}</b><span>Arrivals due in 7 days</span><em>where a date is sent</em></div>
      <div><b style="font-size:15px;padding-top:9px">{{ $at($s['last_read'], 'M j, g:ia') }}</b><span>Last reading</span><em>{{ $tzLabel }}</em></div>
    </div>

    <div class="vl-tabs">
      <button type="button" wire:click="setVendor('')" class="{{ $vendor === '' ? 'on' : '' }}">All distributors<span>{{ number_format($allCount) }}</span></button>
      @foreach ($vendorCounts as $code => $n)
        <button type="button" wire:click="setVendor('{{ $code }}')" class="{{ $vendor === $code ? 'on' : '' }}">{{ $code }}<span>{{ number_format($n) }}</span></button>
      @endforeach
    </div>

    <div class="vl-bar">
      <input type="search" class="vl-in" wire:model.live.debounce.400ms="search" placeholder="Search item, brand or SKU">
      {{-- MARKER-VELOCITY-BRAND-PICKER: works like the tenant brand picker (x-tenant.searchable-select):
           a button, then a panel with "Type to filter", matches highlighted, a tick on the
           current brand and a count. Brands A–Z. --}}
      @php
        $brandList = $this->brands();
        $brandOpts = collect($brandList)->map(fn ($n, $b) => ['v' => (string) $b, 'n' => $n])->values()->all();
      @endphp
      <div class="vl-ssel" wire:key="brand-picker-{{ $vendor ?: 'all' }}"
           x-data="{
             open: false, q: '', hl: 0, opts: @js($brandOpts), cur: @js($brand),
             get shown() { const q = this.q.trim().toLowerCase(); return q === '' ? this.opts : this.opts.filter(o => o.v.toLowerCase().includes(q)); },
             mark(s) { const q = this.q.trim(); const e = t => t.replace(/[&<>&quot;]/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','&quot;':'&quot;'}[c]));
               if (!q) return e(s); const i = s.toLowerCase().indexOf(q.toLowerCase()); return i < 0 ? e(s) : e(s.slice(0, i)) + '<mark>' + e(s.slice(i, i + q.length)) + '</mark>' + e(s.slice(i + q.length)); },
             toggle() { this.open = !this.open; if (this.open) { this.q = ''; this.hl = 0; this.$nextTick(() => this.$refs.q.focus()); } },
             pick(v) { this.cur = v; this.open = false; $wire.set('brand', v); },
             move(d) { const max = this.shown.length; this.hl = Math.max(0, Math.min(max, this.hl + d)); this.$nextTick(() => { const el = this.$refs.list.querySelector('.is-hl'); if (el) el.scrollIntoView({ block: 'nearest' }); }); },
             enter() { if (this.q.trim() === '' && this.hl === 0) return this.pick(''); const o = this.shown[this.q.trim() === '' ? this.hl - 1 : this.hl]; if (o) this.pick(o.v); }
           }"
           @click.outside="open = false" @keydown.escape.window="open = false">
        <button type="button" class="vl-ssel-btn" @click="toggle()" aria-haspopup="listbox">
          <span :class="cur === '' ? 'is-any' : ''" x-text="cur === '' ? 'All brands' : cur">{{ $brand ?: 'All brands' }}</span>
          <span class="vl-ssel-chev" aria-hidden="true">&#9662;</span>
        </button>
        <div class="vl-ssel-panel" x-show="open" x-cloak>
          <div class="vl-ssel-search"><input type="text" x-ref="q" x-model="q" @input="hl = 0" placeholder="Type to filter&hellip;" autocomplete="off"
               @keydown.arrow-down.prevent="move(1)" @keydown.arrow-up.prevent="move(-1)" @keydown.enter.prevent="enter()"></div>
          <div class="vl-ssel-list" role="listbox" x-ref="list">
            <template x-if="q.trim() === ''">
              <div class="vl-ssel-opt" :class="{ 'is-sel': cur === '', 'is-hl': hl === 0 }" @click="pick('')" role="option"><span>All brands</span><span class="vl-ssel-tick">&#10003;</span></div>
            </template>
            <template x-for="(o, i) in shown" :key="o.v">
              <div class="vl-ssel-opt" :class="{ 'is-sel': cur === o.v, 'is-hl': hl === (q.trim() === '' ? i + 1 : i) }" @click="pick(o.v)" role="option">
                <span x-html="mark(o.v)"></span><span class="vl-ssel-n" x-text="o.n.toLocaleString()"></span><span class="vl-ssel-tick">&#10003;</span>
              </div>
            </template>
            <div class="vl-ssel-none" x-show="q.trim() !== '' && shown.length === 0">No brand matches “<span x-text="q"></span>”.</div>
          </div>
          <div class="vl-ssel-foot" x-text="q.trim() === '' ? opts.length.toLocaleString() + ' brands' : shown.length.toLocaleString() + ' of ' + opts.length.toLocaleString() + ' brands'"></div>
        </div>
      </div>
      <div class="vl-seg">
        @foreach (\App\Filament\Pages\DistributorVelocity::VIEWS as $k => $label)
          <button type="button" wire:click="setView('{{ $k }}')" class="{{ $mode === $k ? 'on' : '' }}">{{ $label }}</button>
        @endforeach
      </div>
      <div class="vl-seg">
        @foreach (\App\Filament\Pages\DistributorVelocity::STOCK as $k => $label)
          <button type="button" wire:click="setStock('{{ $k }}')" class="{{ $stock === $k ? 'on' : '' }}">{{ $label }}</button>
        @endforeach
      </div>
      {{-- a toggle in the filter row instead of a checkbox that wrapped onto its own line --}}
      <div class="vl-seg"><button type="button" wire:click="$toggle('hideEarly')" class="{{ $hideEarly ? 'on' : '' }}" title="Hide items with too little history to have a pace" aria-pressed="{{ $hideEarly ? 'true' : 'false' }}">{{ $hideEarly ? '✓ ' : '' }}Has a pace</button></div>
    </div>

    @if ($this->searching())
      <div class="vl-mut" style="font-size:12.5px;margin-top:-6px">Searching every item{{ $vendor ? ' at ' . $vendor : '' }}{{ $brand ? ' from ' . $brand : '' }} — the view above only sets the order while you search.</div>
    @endif
    <div style="overflow-x:auto">
    <table class="vl-tbl">
      @php
        $sh = function ($key, $label, $right = false) use ($sort, $dir) {
            $on = $sort === $key;
            $arrow = $on ? ($dir === 'asc' ? '↑' : '↓') : '';
            return '<th' . ($right ? ' class="r"' : '') . '><button type="button" class="vl-sort' . ($on ? ' on' : '') . '" wire:click="sortBy(\'' . $key . '\')" title="Sort by ' . e(strtolower($label)) . '">' . e($label) . ($arrow ? '<i>' . $arrow . '</i>' : '') . '</button></th>';
        };
      @endphp
      <thead><tr>{!! $sh('item', 'Item') !!}{!! $sh('code', 'Distributor') !!}{!! $sh('stock', 'In stock', true) !!}<th title="Stock since the first reading, up to 30 days">Stock trend</th>{!! $sh('pace', 'Pace', true) !!}{!! $sh('days', 'Days left', true) !!}{!! $sh('arrival', 'Next arrival') !!}<th class="r">Shops stocking</th>{!! $sh('history', 'History') !!}</tr></thead>
      <tbody>
      @forelse ($res['rows'] as $r)
        @php $dl = $r->days_left === null ? null : (float) $r->days_left; $cls = $dl === null ? 'vl-mut' : ($dl < 14 ? 'red' : ($dl < 30 ? 'amber' : '')); @endphp
        <tr wire:click="select({{ $r->id }})" wire:key="vl-{{ $r->id }}">
          <td class="vl-nm"><b>{{ $r->title ?: $r->distributor_variant_no }}</b><span>{{ trim(($r->brand ? $r->brand . ' · ' : '') . ($r->mpn ?: $r->distributor_variant_no)) }}</span></td>
          <td>{{ $r->distributor_code }}</td>
          <td class="r">{{ number_format((int) $r->avail) }}</td>
          <td title="Since {{ $at($r->spark_from, 'M j, g:ia') }}"><svg width="80" height="22" viewBox="0 0 80 22" style="display:block"><polyline fill="none" stroke="#8b5cf6" stroke-width="1.6" points="{{ $r->spark }}"/></svg></td>
          <td class="r" style="white-space:nowrap">{{ $r->rate_per_day === null ? '—' : rtrim(rtrim(number_format((float) $r->rate_per_day, 1), '0'), '.') . ' / day' }}</td>
          <td class="r"><span class="vl-dl {{ $cls }}">{{ $dl === null ? '—' : ($dl < 1 ? 'Under 1 day' : number_format($dl) . ' ' . \Illuminate\Support\Str::plural('day', (int) round($dl))) }}</span></td>
          <td>@if ($r->next_arrival_on)<span class="vl-chip ok">{{ \Carbon\Carbon::parse($r->next_arrival_on)->format('M j') }}</span>@else<span class="vl-chip">None sent</span>@endif</td>
          <td class="r">{{ $r->shops }}</td>
          <td class="vl-mut" style="white-space:nowrap">{{ $confWord[$r->conf] }} · {{ $r->readings }} {{ \Illuminate\Support\Str::plural('reading', (int) $r->readings) }}</td>
        </tr>
      @empty
        <tr><td colspan="9"><div class="vl-empty">
          @if ($s['items'] === 0)
            <b>No readings yet.</b>Run the pull once after deploy, or wait for tonight's sync.
          @else
            @php $hidden = $this->hiddenByStock(); @endphp
            <b>{{ $this->searching() ? 'No match for “' . $search . '”' . ($stock !== 'all' ? ' that is ' . strtolower(\App\Filament\Pages\DistributorVelocity::STOCK[$stock]) : '') . '.' : 'Nothing in this view yet.' }}</b>
            @if ($hidden > 0) {{ number_format($hidden) }} {{ \Illuminate\Support\Str::plural('item', $hidden) }} match but {{ $stock === 'in' ? 'are out of stock' : 'are in stock' }}. @elseif ($this->searching()) Search looks at the item name, brand, SKU and distributor number. @elseif ($stock === 'out' && $mode === 'run') Running out only lists items that are still in stock. @elseif ($mode === 'run' || $mode === 'fast') Pace needs about half a day of sweeps, so these fill in as they run. @else Try a different view, stock filter or distributor. @endif
            <div style="display:flex;gap:8px;justify-content:center;margin-top:14px;flex-wrap:wrap">
              <button type="button" class="vl-btn" wire:click="clearFilters">Clear search and filters</button>
              @if ($stock !== 'all')<button type="button" class="vl-btn" wire:click="setStock('all')">Show all stock</button>@endif
              <button type="button" class="vl-btn" wire:click="setView('arrival')">Show arrivals due</button>
              <button type="button" class="vl-btn" wire:click="setTab('coverage')">See data coverage</button>
            </div>
          @endif
        </div></td></tr>
      @endforelse
      </tbody>
    </table>
    </div>
    {{-- pagination: how many match, per-page size, and page controls --}}
    @php $pg = $page; $pp = $res['pages']; $to = $res['from'] ? $res['from'] + count($res['rows']) - 1 : 0; @endphp
    <div style="display:flex;align-items:center;gap:12px;flex-wrap:wrap">
      <span class="vl-mut" style="font-size:12.5px">
        @if ($res['total']) Showing {{ number_format($res['from']) }}–{{ number_format($to) }} of {{ number_format($res['total']) }} that match
          {{ $this->searching() ? 'this search' : 'this view and filters' }}@else No items match @endif
        · {{ number_format($s['items']) }} tracked{{ $vendor ? ' at ' . $vendor : '' }}
      </span>
      <span style="flex:1"></span>
      <label class="vl-mut" style="font-size:12.5px;display:inline-flex;gap:6px;align-items:center">Rows
        <select class="vl-in" style="min-width:0;padding:4px 8px" wire:model.live="perPage">
          @foreach (\App\Filament\Pages\DistributorVelocity::PER_PAGE as $n)<option value="{{ $n }}">{{ $n }}</option>@endforeach
        </select></label>
      @if ($pp > 1)
        <div class="vl-seg">
          <button type="button" wire:click="goPage(1)" @disabled($pg <= 1) title="First page">«</button>
          <button type="button" wire:click="goPage({{ $pg - 1 }})" @disabled($pg <= 1)>Previous</button>
          @foreach (array_unique(array_filter([1, $pg - 2, $pg - 1, $pg, $pg + 1, $pg + 2, $pp], fn ($x) => $x >= 1 && $x <= $pp)) as $i => $n)
            @if ($i > 0 && $n - $prevN > 1)<button type="button" disabled>…</button>@endif
            <button type="button" wire:click="goPage({{ $n }})" class="{{ $n === $pg ? 'on' : '' }}">{{ number_format($n) }}</button>
            @php $prevN = $n; @endphp
          @endforeach
          <button type="button" wire:click="goPage({{ $pg + 1 }})" @disabled($pg >= $pp)>Next</button>
          <button type="button" wire:click="goPage({{ $pp }})" @disabled($pg >= $pp) title="Last page">»</button>
        </div>
      @endif
    </div>

    @if ($d = $this->detail())
      <div class="vl-dr-bg" wire:click="closeDetail"></div>
      <aside class="vl-dr">
        <div style="display:flex;align-items:flex-start;gap:12px">
          <div style="flex:1"><div class="vl-mut" style="font-size:12.5px">{{ $d->distributor_code }} · {{ $d->distributor_variant_no }}</div>
            <h2 style="font-size:18px;font-weight:600;margin:2px 0;color:var(--txt)">{{ $d->title ?: $d->distributor_variant_no }}</h2>
            <div style="color:var(--dim);font-size:13px">{{ trim(($d->brand ?? '') . ($d->mpn ? ' · ' . $d->mpn : ''), ' ·') }}</div></div>
          <button type="button" class="vl-btn" wire:click="closeDetail">Close</button>
        </div>
        <div style="margin:18px 0 4px"><svg width="100%" height="96" viewBox="0 0 420 96" preserveAspectRatio="none" style="display:block"><polyline fill="none" stroke="#8b5cf6" stroke-width="1.8" points="{{ $d->chart }}"/></svg>
          <div class="vl-mut" style="font-size:11.5px;display:flex;justify-content:space-between"><span>{{ $at($d->chart_from, 'M j, g:ia') }}</span><span>{{ $d->change_count }} {{ \Illuminate\Support\Str::plural('change', $d->change_count) }} recorded</span><span>Now</span></div></div>
        <dl>
          <dt>In stock</dt><dd>{{ number_format((int) $d->avail) }}</dd>
          <dt>Average pace</dt><dd>{{ $d->rate_per_day === null ? 'Not enough history yet' : rtrim(rtrim(number_format((float) $d->rate_per_day, 2), '0'), '.') . ' per day' }}</dd>
          <dt>Days left</dt><dd>{{ $d->days_left === null ? '—' : ((float) $d->days_left < 1 ? 'Under 1 day' : number_format((float) $d->days_left) . ' days') }}</dd>
          <dt>Next arrival</dt><dd>{{ $d->next_arrival_on ? \Carbon\Carbon::parse($d->next_arrival_on)->format('M j, Y') : 'None sent by ' . $d->distributor_code }}</dd>
          <dt>Last restock</dt><dd>{{ $d->last_restock_at ? $at($d->last_restock_at, 'M j, g:ia') : 'None seen' }}</dd>
          <dt>Before this build</dt><dd>{{ $d->baseline_avail === null ? 'No earlier reading' : number_format((int) $d->baseline_avail) . ' on ' . $at($d->baseline_at, 'M j, g:ia') }}</dd>
          <dt>History</dt><dd>{{ $confWord[$d->conf] }} · {{ $d->readings }} readings since {{ $d->first_seen_at ? $at($d->first_seen_at, 'M j') : '—' }}</dd>
          <dt>Shops stocking</dt><dd>{{ $d->shops }} (a count — shops are never named here)</dd>
        </dl>
      </aside>
    @endif

  @elseif ($tab === 'coverage')
    @php $c = $this->coverage(); @endphp
    <div class="vl-legend"><b>What each distributor gives us.</b> Readings come from a sweep of each whole catalog every 4 hours with the master admin logins (BTI and Shimano in one feed, HLC in batches of 50, QBP one call per warehouse), plus the per-shop sync. Arrival dates come from warehouse detail where it's sent; QBP's warehouse call carries none, so QBP dates come only from shops' syncs.</div>
    <div class="vl-cov">
      @forelse ($c['per'] as $code => $p)
        @php $ch = $c['changes'][$code] ?? null; @endphp
        <div><b>{{ $code }}</b>
          <p>{{ number_format($p->items) }} items tracked<br>{{ number_format($p->with_rate) }} with a pace<br>{{ number_format($p->with_arrival) }} with an arrival date<br>
          {{ number_format($ch->n ?? 0) }} changes kept{{ $ch && $ch->oldest ? ' since ' . $at($ch->oldest, 'M j') : '' }}<br>
          Last read {{ $p->last_read ? $at($p->last_read, 'M j, g:ia') : '—' }}</p></div>
      @empty
        <div><p>No readings yet. Run the pull once after deploy, or wait for tonight's sync.</p></div>
      @endforelse
    </div>
    <div class="vl-set">
      <div class="row"><div><b>Recorder</b><span>Fed by the 4-hourly sweep and the per-shop sync. Updates one summary row per item and writes a change row only when the quantity moved.</span></div><span class="vl-chip ok">On</span></div>
      <div class="row"><div><b>Change history</b><span>Individual changes are kept {{ \App\Services\Distributors\StockVelocityRecorder::KEEP_DAYS }} days, then removed nightly. The per-item summary is kept.</span></div><span class="vl-chip v">{{ \App\Services\Distributors\StockVelocityRecorder::KEEP_DAYS }} days</span></div>
      <div class="row"><div><b>Storage</b><span>Both tables together.</span></div><span class="vl-chip">{{ $c['mb'] === null ? 'Unknown' : $c['mb'] . ' MB' }}</span></div>
    </div>

    {{-- MARKER-VELOCITY-SWEEP: the last sweeps, so a missed or partial one is visible --}}
    <div class="vl-legend" style="border-bottom:0;padding-bottom:0"><b>Recent sweeps.</b> Every 4 hours at quarter past (UTC). A row turns amber when a distributor was skipped, failed, or read nothing.</div>
    @php $runs = $this->runs(); @endphp
    <div style="overflow-x:auto">
    <table class="vl-tbl">
      <thead><tr><th>Started</th><th>Took</th><th>Distributors</th><th>Notes</th></tr></thead>
      <tbody>
      @forelse ($runs as $run)
        <tr style="cursor:default">
          <td style="white-space:nowrap">{{ $at($run->started_at, 'M j, g:ia') }}</td>
          <td class="vl-mut" style="white-space:nowrap">{{ $run->finished_at ? \Carbon\Carbon::parse($run->started_at)->diffForHumans(\Carbon\Carbon::parse($run->finished_at), true) : 'Still running or stopped' }}</td>
          <td>@foreach ($run->results as $x)<span class="vl-chip {{ (! empty($x['note']) || (($x['items'] ?? 0) > 0 && ($x['read'] ?? 0) === 0)) ? 'warn' : 'ok' }}" style="margin:0 4px 4px 0">{{ $x['code'] }} · {{ number_format($x['read'] ?? 0) }} read · {{ number_format($x['changed'] ?? 0) }} changed</span>@endforeach</td>
          <td class="vl-mut" style="font-size:12.5px">{{ collect($run->results)->pluck('note')->filter()->implode(' ') ?: '—' }}</td>
        </tr>
      @empty
        <tr style="cursor:default"><td colspan="4"><div class="vl-empty"><b>No sweeps yet.</b>The first runs at the next quarter past a 4-hour mark (UTC), or run it now from the server.</div></td></tr>
      @endforelse
      </tbody>
    </table>
    </div>

  @else
    <div class="vl-legend"><b>Not live in tenant admin yet.</b> These settings are saved now so the shop-side build reads them. Until that ships, shops see nothing from this page, whatever is switched on. Shops will only ever see distributor stock — never another shop's data.</div>
    <div class="vl-set">
      @foreach (\App\Filament\Pages\DistributorVelocity::SHOP_SWITCHES as $k => $d)
        <div class="row"><div><b>{{ $d[0] }}</b><span>{{ $d[1] }}</span></div>
          <label class="vl-sw"><input type="checkbox" wire:model="shop.{{ $k }}" @disabled(! $this->canEdit())><i></i></label></div>
      @endforeach
      <div class="row"><div><b>Distributors shown to shops</b><span>Untick one to hide its estimates everywhere in tenant admin.</span></div>
        <div style="display:flex;gap:14px;flex-wrap:wrap;font-size:13px">
          @foreach (($shop['vendors'] ?? []) as $code => $on)
            <label style="display:inline-flex;gap:6px;align-items:center"><input type="checkbox" wire:model="shop.vendors.{{ $code }}" @disabled(! $this->canEdit())> {{ $code }}</label>
          @endforeach
        </div></div>
    </div>
    <div style="display:flex;gap:8px;align-items:center">
      <button type="button" class="vl-btn p" wire:click="saveShop" @disabled(! $this->canEdit())>Save settings</button>
      @unless ($this->canEdit())<span class="vl-mut" style="font-size:12.5px">Your role can view these but not change them.</span>@endunless
    </div>
  @endif
</div>
</x-filament-panels::page>
