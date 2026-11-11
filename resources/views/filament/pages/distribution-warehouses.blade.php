<x-filament-panels::page>
{{-- MARKER-DISTRIBUTION-WAREHOUSES: hairlines, no cards (the Velocity-page vocabulary). --}}
<style>
  .dw-root { --line:rgba(255,255,255,.075); --line2:rgba(255,255,255,.14); --dim:#a3a3ab; --faint:#74747d; --txt:#fff;
    --violet:#8b5cf6; --vtext:#a78bfa; --vsoft:rgba(139,92,246,.17); --ok:#86efac; --amber:#fbbf24; --pop:#18181b; font-size:14px; }
  html:not(.dark) .dw-root { --line:rgba(0,0,0,.08); --line2:rgba(0,0,0,.16); --dim:#52525b; --faint:#6b6b75; --txt:#18181b;
    --vtext:#6d28d9; --vsoft:rgba(139,92,246,.11); --ok:#15803d; --amber:#b45309; --pop:#fff; }
  .dw-root .sub { color:var(--dim); max-width:780px; line-height:1.55; margin:0 }
  .dw-legend { color:var(--faint); font-size:12.5px; line-height:1.65; padding:11px 0; border-top:1px solid var(--line); border-bottom:1px solid var(--line); }
  .dw-legend b { color:var(--dim); font-weight:600; }
  .dw-row { display:flex; justify-content:space-between; align-items:center; gap:20px; padding:13px 0; border-bottom:1px solid var(--line); }
  .dw-row b { display:block; font-weight:600; }
  .dw-row span.d { color:var(--dim); font-size:12.5px; max-width:62ch; display:block; margin-top:2px; line-height:1.5; }
  .dw-h { font-size:11px; letter-spacing:.1em; text-transform:uppercase; color:var(--faint); font-weight:600; margin:6px 0 0; }
  .dw-in { background:transparent; border:1px solid var(--line2); border-radius:7px; padding:6px 10px; font:inherit; font-size:13px; color:inherit; }
  .dw-root select.dw-in { -webkit-appearance:none; appearance:none; padding-right:30px; background-color:transparent;
    background-image:url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 12 12' fill='none' stroke='%23a3a3ab' stroke-width='1.5' stroke-linecap='round' stroke-linejoin='round'%3E%3Cpath d='M3 4.5l3 3 3-3'/%3E%3C/svg%3E") !important;
    background-repeat:no-repeat !important; background-position:right 10px center !important; background-size:12px 12px !important; }
  .dw-root select.dw-in option { background:var(--pop); color:var(--txt); }
  .dw-tbl { width:100%; border-collapse:collapse; font-size:13.5px; }
  .dw-tbl th { font-size:11.5px; font-weight:500; color:var(--faint); text-align:left; padding:8px 10px; border-bottom:1px solid var(--line2); white-space:nowrap; }
  .dw-tbl td { padding:9px 10px; border-bottom:1px solid var(--line); vertical-align:middle; }
  .dw-tbl .n { text-align:right; font-variant-numeric:tabular-nums; } .dw-tbl .c { text-align:center; }
  .dw-vend { display:flex; gap:10px; align-items:baseline; margin:18px 0 2px; font-weight:600; }
  .dw-vend small { font-weight:400; color:var(--faint); font-size:12px; }
  .dw-sw { position:relative; width:40px; height:22px; flex:none; display:inline-block; }
  .dw-sw input { opacity:0; width:100%; height:100%; position:absolute; inset:0; margin:0; cursor:pointer; z-index:2; }
  .dw-sw i { position:absolute; inset:0; border-radius:99px; background:var(--line2); transition:background .12s; }
  .dw-sw i::after { content:''; position:absolute; left:3px; top:3px; width:16px; height:16px; border-radius:50%; background:#fff; box-shadow:0 1px 2px rgba(0,0,0,.3); transition:left .12s; }
  .dw-sw input:checked + i { background:var(--violet); } .dw-sw input:checked + i::after { left:21px; }
  .dw-sw input:disabled + i { opacity:.4; }
  .dw-pill { font-size:11px; padding:1px 8px; border-radius:99px; background:var(--vsoft); color:var(--vtext); margin-left:6px; }
  .dw-btn { background:var(--violet); color:#fff; border:0; border-radius:7px; padding:8px 16px; font:inherit; font-weight:600; cursor:pointer; }
  .dw-btn.g { background:transparent; color:var(--txt); border:1px solid var(--line2); }
  .dw-off { opacity:.45; pointer-events:none; }
  .dw-empty { padding:26px 0; color:var(--dim); line-height:1.6; }
  .dw-modal { position:fixed; inset:0; background:rgba(0,0,0,.55); display:flex; align-items:center; justify-content:center; z-index:60; }
  .dw-modal > div { background:var(--pop); color:var(--txt); border:1px solid var(--line2); border-radius:12px; padding:22px; width:min(440px,92vw); }
  .dw-modal p { color:var(--dim); line-height:1.55; margin:8px 0 18px; }
</style>

@php
  $wcount = $this->warehouses()->count();
  $groups = $this->grouped();
  $using  = $this->shopsUsing();
  $total  = $this->totalShops();
  $choices = $this->shopChoices();
  $ro = ! $this->canEdit();
@endphp

<div class="dw-root" style="display:flex;flex-direction:column;gap:16px"
     x-data="{ ask:false, on: $wire.entangle('set.feature_on') }" @keydown.escape.window="ask=false">
  <p class="sub">Which supplier warehouses shops can see, and which can ship straight to a shop's customers. Warehouses appear here on their own the first time a supplier feed reports them.</p>

  <div class="dw-legend">
    <b>Feature switch</b> off means no shop sees any warehouse or dropship setting, and every online order ships from the shop's own stock. A shop's own choices are kept, not deleted.
    <b>Shown to shops</b> lets a shop see that warehouse's stock counts. <b>Dropship</b> lets a shop choose it as a ship-from source, and can't be on unless the warehouse is shown.
    <b>Shop overrides</b> allow or block dropshipping for one shop, whatever the default is.
    <b>Stock count limit</b>: a supplier count older than this is treated as unknown, so nothing is offered from stale data.
    None of this touches the register or in-store sales.
  </div>

  <div>
    <p class="dw-h">Feature</p>
    <div class="dw-row">
      <div><b>Show warehouses and dropshipping to shops</b><span class="d">The master switch. Off hides the Where column, the supplier list and the dropship settings for every shop.</span></div>
      <label class="dw-sw"><input type="checkbox" :checked="on" @disabled($ro) @change="if(! $event.target.checked){ $event.target.checked = true; ask = true } else { on = true }"><i></i></label>
    </div>
    <div class="dw-row">
      <div><b>Shops with no override</b><span class="d">Whether dropshipping is on for shops you haven't set below. A shop still has to tick the warehouses it wants to use.</span></div>
      <select class="dw-in" wire:model="set.shop_default" @disabled($ro)><option value="0">Off, I enable it per shop</option><option value="1">On</option></select>
    </div>
    <div class="dw-row">
      <div><b>Supplier stock counts older than</b><span class="d">Counts older than this are treated as unknown.</span></div>
      <span><input type="number" class="dw-in" style="width:72px" min="1" max="168" wire:model="set.stale_hours" @disabled($ro)> hours</span>
    </div>
  </div>

  {{-- MARKER-SHIPPING-RATES: the unit each distributor's feed uses for weight and size --}}
  <div>
    <p class="dw-h">Package data by distributor</p>
    <table class="dw-tbl">
      <thead><tr><th>Distributor</th><th>Weight in the feed is in</th><th>Size in the feed is in</th></tr></thead>
      <tbody>
      @forelse ($this->unitCodes() as $code)
        <tr wire:key="unit-{{ $code }}">
          <td>{{ $code }}</td>
          <td><select class="dw-in" wire:model="units.{{ $code }}.weight" @disabled($ro)>@foreach (\App\Services\Shipping\Units::weightUnits() as $u)<option value="{{ $u }}">{{ $u }}</option>@endforeach</select></td>
          <td><select class="dw-in" wire:model="units.{{ $code }}.length" @disabled($ro)>@foreach (\App\Services\Shipping\Units::lengthUnits() as $u)<option value="{{ $u }}">{{ $u }}</option>@endforeach</select></td>
        </tr>
      @empty
        <tr><td colspan="3" class="dw-empty">No distributor catalogs yet. They appear here once a catalog has synced.</td></tr>
      @endforelse
      </tbody>
    </table>
    <div class="dw-legend" style="border-top:0">Shops with live shipping rates use these to turn a supplier's weight and size into pounds and inches. A shop's own weight on an item always wins. Check one item against the supplier's site before trusting a new distributor; the default is pounds and inches.</div>
  </div>

  <div :class="on ? '' : 'dw-off'" style="display:flex;flex-direction:column;gap:14px">
    <div style="display:flex;gap:8px;flex-wrap:wrap;align-items:center">
      <select class="dw-in" wire:model.live="dist"><option value="">All distributors</option>@foreach ($this->distributors() as $d)<option value="{{ $d }}">{{ $d }}</option>@endforeach</select>
      <select class="dw-in" wire:model.live="status"><option value="">Any status</option><option value="drop">Dropship on</option><option value="shown">Shown, no dropship</option><option value="hidden">Hidden</option></select>
      <input type="search" class="dw-in" style="flex:1;min-width:180px" wire:model.live.debounce.300ms="search" placeholder="Search warehouse">
      <button type="button" class="dw-btn g" wire:click="scan" @disabled($ro)>Scan feeds for new warehouses</button>
    </div>

    @forelse ($groups as $code => $list)
      <div>
        <div class="dw-vend">{{ $code }} <small>{{ count($list) }} {{ count($list) === 1 ? 'warehouse' : 'warehouses' }}</small></div>
        <table class="dw-tbl">
          <thead><tr><th>Warehouse</th><th>Feed code</th><th>Ships from ZIP</th><th class="n">Handling days</th><th class="n">Shops using</th><th class="c">Shown to shops</th><th class="c">Dropship</th></tr></thead>
          <tbody>
          @foreach ($list as $w)
            <tr wire:key="wh-{{ $w->id }}">
              <td>{{ $w->name }}@if ($w->first_seen_at && $w->first_seen_at->gt(now()->subDays(7)))<span class="dw-pill">New</span>@endif</td>
              <td>{{ $w->code }}</td>
              <td><input type="text" class="dw-in" style="width:84px" wire:model="wh.{{ $w->id }}.zip" @disabled($ro)></td>
              <td class="n"><input type="number" class="dw-in" style="width:64px" min="0" max="60" wire:model="wh.{{ $w->id }}.days" @disabled($ro)></td>
              <td class="n">{{ $using[$w->id] ?? 0 }} of {{ $total }}</td>
              <td class="c"><label class="dw-sw"><input type="checkbox" wire:model.live="wh.{{ $w->id }}.shown" @disabled($ro)><i></i></label></td>
              <td class="c"><label class="dw-sw"><input type="checkbox" wire:model.live="wh.{{ $w->id }}.drop" @disabled($ro || ! ($wh[$w->id]['shown'] ?? false))><i></i></label></td>
            </tr>
          @endforeach
          </tbody>
        </table>
      </div>
    @empty
      <div class="dw-empty">
        @if ($wcount === 0)
          No warehouses found yet. They appear after a shop's supplier sync reports stock by warehouse. To look through the stock already stored, use <b>Scan feeds for new warehouses</b>.
        @else
          No warehouses match these filters. Clear the search, or set Any status and All distributors.
        @endif
      </div>
    @endforelse
    <div class="dw-legend" style="border-top:0">New warehouses start hidden and off until you turn them on here.</div>

    <div>
      <p class="dw-h">Shop overrides</p>
      <div style="margin:8px 0"><input type="search" class="dw-in" style="width:260px" wire:model.live.debounce.300ms="shopSearch" placeholder="Find a shop"></div>
      <table class="dw-tbl">
        <thead><tr><th>Shop</th><th>Dropshipping</th><th>Warehouses they've chosen</th><th></th></tr></thead>
        <tbody>
        @forelse ($this->shops() as $t)
          <tr wire:key="shop-{{ $t->id }}">
            <td>{{ $t->name }}</td>
            <td><select class="dw-in" wire:model="ov.{{ $t->id }}" @disabled($ro)><option value="follow">Follow default</option><option value="allow">Allowed</option><option value="block">Blocked</option></select></td>
            <td>{{ isset($choices[$t->id]) ? implode(', ', $choices[$t->id]) : '—' }}</td>
            <td class="n"><a class="dw-btn g" style="text-decoration:none;display:inline-block;padding:5px 12px" href="{{ \App\Filament\Resources\TenantResource::getUrl('edit', ['record' => $t->id]) }}">Open shop</a></td>
          </tr>
        @empty
          <tr><td colspan="4" class="dw-empty">No shops match. Clear the search to see every shop.</td></tr>
        @endforelse
        </tbody>
      </table>
    </div>
  </div>

  <div style="display:flex;gap:8px">
    <button type="button" class="dw-btn" wire:click="save" wire:loading.attr="disabled" @disabled($ro)>Save changes</button>
    <button type="button" class="dw-btn g" wire:click="mount">Discard</button>
  </div>

  {{-- in-app dialog, never a browser one --}}
  <div class="dw-modal" x-show="ask" x-cloak x-transition.opacity @click.self="ask=false">
    <div>
      <b>Hide warehouses and dropshipping from all shops?</b>
      <p>Every online order will ship from the shop's own stock until you turn this back on. Shops' own choices are kept. This takes effect when you press Save changes.</p>
      <div style="display:flex;gap:8px;justify-content:flex-end">
        <button type="button" class="dw-btn g" @click="ask=false">Cancel</button>
        <button type="button" class="dw-btn" @click="on = false; ask=false">Turn off</button>
      </div>
    </div>
  </div>
</div>
</x-filament-panels::page>
