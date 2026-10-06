{{-- MARKER-SALES-SETUP --}}
@php
    $mtd = $this->monthToDateCents();
    $budget = $budgetDollars * 100;
    $pct = $budget > 0 ? min(100, round($mtd / $budget * 100)) : 0;
@endphp
<x-filament-panels::page>
<style>
  .sx-root { max-width:620px; font-size:14px; }
  .sx-root p { color:#a3a3ab; font-size:13.5px; line-height:1.55; margin:0 0 20px; }
  .sx-lab { display:block; font-size:12.5px; color:#a3a3ab; margin-bottom:6px; }
  .sx-in { width:100%; background-color:rgba(255,255,255,.04); border:1px solid rgba(255,255,255,.14); border-radius:7px; padding:8px 11px; font-size:14px; color:inherit; }
  select.sx-in { padding-right:32px; background-repeat:no-repeat; } /* MARKER-SALES-SELECT-FIX */
  .sx-hint { font-size:12px; color:#74747d; margin-top:6px; }
  .sx-two { display:grid; grid-template-columns:1fr 160px; gap:16px; margin-top:22px; }
  .sx-btn { border:1px solid rgba(255,255,255,.14); background:none; border-radius:7px; padding:7px 13px; font-weight:500; font-size:13px; cursor:pointer; color:inherit; }
  .sx-btn.p { background:#8b5cf6; border-color:#8b5cf6; color:#fff; }
  .sx-meter { height:6px; border-radius:3px; background:rgba(255,255,255,.08); overflow:hidden; margin:8px 0 4px; }
  .sx-meter i { display:block; height:100%; background:{{ $pct >= 100 ? '#f47c7c' : ($pct >= 80 ? '#f5b942' : '#BEF264') }}; }
</style>
<div class="sx-root">
  <p>Every Places search costs money, about {{ $this->money(\App\Models\SalesSetting::placesCostCents()) }} per page of 20 results, and searches stop once this month's spend reaches the budget. Loading a shop list from a file is free.</p>

  <div style="font-size:12.5px;color:#a3a3ab">This month</div>
  <div style="font-size:24px;font-weight:650;font-variant-numeric:tabular-nums">{{ $this->money($mtd) }} <span style="font-size:14px;font-weight:400;color:#a3a3ab">of {{ $this->money($budget) }}</span></div>
  <div class="sx-meter"><i style="width:{{ $pct }}%"></i></div>

  <div class="sx-two">
    <div>
      <label class="sx-lab" for="pk">API key</label>
      <input id="pk" type="password" class="sx-in" wire:model="placesKey" autocomplete="off" placeholder="{{ $this->configured() ? 'A key is saved' : 'Paste a key' }}">
      <div class="sx-hint">{{ $this->configured() ? 'Stored encrypted. Leave blank to keep the saved key.' : 'A Google Cloud key with Places API (New) turned on. Stored encrypted.' }}</div>
      @error('placesKey')<div style="color:#f47c7c;font-size:12px;margin-top:4px">{{ $message }}</div>@enderror
    </div>
    <div>
      <label class="sx-lab" for="bd">Monthly budget ($)</label>
      <input id="bd" type="number" min="0" class="sx-in" wire:model="budgetDollars">
      @error('budgetDollars')<div style="color:#f47c7c;font-size:12px;margin-top:4px">{{ $message }}</div>@enderror
    </div>
  </div>
  <div style="display:flex;gap:8px;margin-top:20px">
    <button class="sx-btn p" wire:click="save" wire:loading.attr="disabled">Save</button>
    @if($this->configured())<button class="sx-btn" wire:click="testKey" wire:loading.attr="disabled">Test the key</button>@endif
  </div>
</div>
</x-filament-panels::page>
