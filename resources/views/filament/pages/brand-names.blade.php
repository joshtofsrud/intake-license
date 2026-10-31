<x-filament-panels::page>
{{-- MARKER-BRAND-NAMES: one brand, one spelling across every distributor feed. --}}
<style>
  .bn { --line:rgba(255,255,255,.075); --line2:rgba(255,255,255,.14); --dim:#a3a3ab; --faint:#74747d; --txt:#fff;
    --violet:#8b5cf6; --vtext:#a78bfa; --vsoft:rgba(139,92,246,.17); --hover:rgba(255,255,255,.035); font-size:14px; display:flex; flex-direction:column; gap:16px; }
  html:not(.dark) .bn { --line:rgba(0,0,0,.08); --line2:rgba(0,0,0,.16); --dim:#52525b; --faint:#6b6b75; --txt:#18181b;
    --vtext:#6d28d9; --vsoft:rgba(139,92,246,.11); --hover:rgba(0,0,0,.025); }
  .bn .sub { color:var(--dim); max-width:780px; line-height:1.55; margin:0; }
  .bn-tabs { display:flex; gap:22px; border-bottom:1px solid var(--line); }
  .bn-tabs button { background:none; border:0; border-bottom:2px solid transparent; padding:9px 0; color:var(--dim); cursor:pointer; font:inherit; font-weight:500; margin-bottom:-1px; }
  .bn-tabs button.on { color:var(--txt); border-bottom-color:var(--violet); }
  .bn-tabs button span { color:var(--faint); font-weight:400; margin-left:4px; }
  .bn-legend { color:var(--faint); font-size:12.5px; line-height:1.6; padding-bottom:11px; border-bottom:1px solid var(--line); }
  .bn-legend b { color:var(--dim); font-weight:600; }
  .bn-in { background:transparent; border:1px solid var(--line2); border-radius:7px; padding:6px 10px; font:inherit; font-size:13px; color:inherit; min-width:240px; }
  .bn-in:focus { outline:none; border-color:var(--violet); }
  .bn-group { border-bottom:1px solid var(--line); padding:12px 0; }
  .bn-row { display:flex; align-items:center; gap:12px; padding:5px 0; flex-wrap:wrap; }
  .bn-row .nm { font-weight:600; color:var(--txt); min-width:220px; }
  .bn-row .by { color:var(--faint); font-size:12.5px; flex:1; }
  .bn-btn { border:1px solid var(--line2); background:none; border-radius:7px; padding:5px 11px; font:inherit; font-weight:500; font-size:12.5px; cursor:pointer; color:inherit; white-space:nowrap; }
  .bn-btn:hover { background:var(--hover); }
  .bn-btn.p { background:var(--violet); border-color:var(--violet); color:#fff; }
  .bn-btn[disabled] { opacity:.45; cursor:default; }
  .bn-tbl { width:100%; border-collapse:collapse; font-size:13.5px; }
  .bn-tbl th { font-size:11.5px; font-weight:500; color:var(--faint); text-align:left; padding:8px 10px; border-bottom:1px solid var(--line2); }
  .bn-tbl td { padding:10px; border-bottom:1px solid var(--line); }
  .bn-empty { padding:36px 0; text-align:center; color:var(--dim); }
  .bn-empty b { display:block; color:var(--txt); margin-bottom:6px; font-size:15px; }
  .bn-form { display:flex; gap:10px; align-items:flex-end; flex-wrap:wrap; max-width:780px; }
  .bn-form label { display:flex; flex-direction:column; gap:5px; font-size:12px; color:var(--dim); }
</style>

@php $pairs = $this->pairings(); $groups = $tab === 'suggested' ? $this->suggestions() : []; $edit = $this->canEdit(); @endphp

<div class="bn">
  <p class="sub">Distributors spell some brands differently — "1st Endurance" at BTI, "First Endurance" at QBP. Choose one spelling and every screen uses it: shop imports, the inventory brand filter, brand on/off settings, Distributor velocity and catalog titles.</p>

  <div class="bn-tabs">
    <button type="button" wire:click="setTab('suggested')" class="{{ $tab === 'suggested' ? 'on' : '' }}">Likely duplicates</button>
    <button type="button" wire:click="setTab('pairings')" class="{{ $tab === 'pairings' ? 'on' : '' }}">Pairings<span>{{ count($pairs) }}</span></button>
    <button type="button" wire:click="setTab('add')" class="{{ $tab === 'add' ? 'on' : '' }}">Add a pairing</button>
  </div>

  <div class="bn-legend">
    <b>What this does.</b> Applies to every distributor feed, current and future: the catalog sync uses these pairings on every row it writes, and saving one relabels the existing catalog straight away.
    Each distributor's own spelling is kept underneath, so removing a pairing puts it back. Item names a shop has already saved are the shop's own and are not renamed; catalog titles pick up the new spelling at the next sync.
  </div>

  @if ($tab === 'suggested')
    <input type="search" class="bn-in" style="align-self:flex-start;width:320px" wire:model.live.debounce.300ms="find" placeholder="Find a brand">
    @forelse ($groups as $fold => $g)
      <div class="bn-group" wire:key="g-{{ md5($fold) }}">
        @foreach ($g as $s)
          <div class="bn-row">
            <span class="nm">{{ $s['name'] }}</span>
            <span class="by">{{ collect($s['by'])->map(fn ($n, $d) => $d . ' ' . number_format($n))->implode(' · ') }}</span>
            <button type="button" class="bn-btn" wire:click="useSpelling(@js($fold), @js($s['name']))" @disabled(! $edit)>Use this spelling</button>
          </div>
        @endforeach
      </div>
    @empty
      <div class="bn-empty"><b>{{ $find !== '' ? 'No likely duplicates match “' . $find . '”.' : 'No likely duplicates.' }}</b>
        Spellings that differ only by 1st/First, &amp;/and, case, punctuation, spacing or Inc/Co show here. Other pairs can be added by hand.
        <div style="margin-top:12px"><button type="button" class="bn-btn" wire:click="setTab('add')">Add a pairing</button></div></div>
    @endforelse

  @elseif ($tab === 'pairings')
    @if ($pairs)
      <table class="bn-tbl">
        <thead><tr><th>Distributor spelling</th><th>Shows as</th><th>Catalog items</th><th>Added</th><th></th></tr></thead>
        <tbody>
        @foreach ($pairs as $p)
          <tr wire:key="p-{{ $p->id }}">
            <td>{{ $p->alias_name }}</td>
            <td style="color:var(--txt);font-weight:600">{{ $p->canonical_name }}</td>
            <td>{{ number_format($p->items) }}</td>
            <td style="color:var(--faint);font-size:12.5px">{{ $p->created_by ?: '—' }}</td>
            <td style="text-align:right"><button type="button" class="bn-btn" wire:click="undo({{ $p->id }})" @disabled(! $edit)>Remove</button></td>
          </tr>
        @endforeach
        </tbody>
      </table>
    @else
      <div class="bn-empty"><b>No pairings yet.</b>Pick a spelling under Likely duplicates, or add one by hand.
        <div style="margin-top:12px;display:flex;gap:8px;justify-content:center"><button type="button" class="bn-btn" wire:click="setTab('suggested')">See likely duplicates</button><button type="button" class="bn-btn" wire:click="setTab('add')">Add a pairing</button></div></div>
    @endif

  @else
    <form wire:submit="addPairing" class="bn-form">
      <datalist id="bn-names">@foreach ($this->allNames() as $n)<option value="{{ $n }}"></option>@endforeach</datalist>
      <label>Distributor spelling<input class="bn-in" list="bn-names" wire:model="from" placeholder="e.g. First Endurance"></label>
      <label>Show it as<input class="bn-in" list="bn-names" wire:model="to" placeholder="e.g. 1st Endurance"></label>
      <button type="submit" class="bn-btn p" @disabled(! $edit)>Save pairing</button>
    </form>
    @error('from')<div style="color:#f47c7c;font-size:12.5px">{{ $message }}</div>@enderror
    @error('to')<div style="color:#f47c7c;font-size:12.5px">{{ $message }}</div>@enderror
    @unless ($edit)<div style="color:var(--faint);font-size:12.5px">Your role can view brand names but not change them.</div>@endunless
  @endif
</div>
</x-filament-panels::page>
