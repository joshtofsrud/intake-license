{{-- MARKER-SHOP-SEARCH-V2: master admin › Search defaults. Styled on Filament's own colour
     variables (the same set as Launch checklists) so it follows light and dark. --}}
@php
  use App\Support\ShopSearch\SearchConfig;
  $edit = $this->canEdit();
@endphp
<x-filament-panels::page>
@include('filament.pages._lcm-style')

<div class="lcm">
  <p class="mu" style="font-size:14px;margin-top:-8px">What every shop's search starts with, and which search features each plan gets. Shops add their own in Reports › Search; they cannot remove what is set here, only switch a default off for themselves.</p>

  <div class="lcm-bar2">
    <div class="lcm-tabs" role="tablist">
      <button type="button" wire:click="show('synonyms')" class="{{ $tab === 'synonyms' ? 'on' : '' }}">Default synonyms</button>
      <button type="button" wire:click="show('stop')" class="{{ $tab === 'stop' ? 'on' : '' }}">Stop words</button>
      <button type="button" wire:click="show('plans')" class="{{ $tab === 'plans' ? 'on' : '' }}">Plans</button>
      <button type="button" wire:click="show('usage')" class="{{ $tab === 'usage' ? 'on' : '' }}">How shops use it</button>
    </div>
  </div>

  @if($tab === 'synonyms')
    <section class="lcm-sec">
      <header><h3>Default synonyms</h3><p>Shown to every shop as "Intake defaults". Words go both ways: "tv" and "television" find each other. A shop's own synonym for the same word wins.</p></header>
      <div class="lcm-tbl">
        <table>
          <thead><tr><th>Shopper types</th><th>Treated as</th><th>On</th><th></th></tr></thead>
          <tbody>
          @forelse($syn as $i => $s)
            <tr wire:key="syn-{{ $i }}-{{ $s['id'] ?? 'n' }}">
              <td><input class="lcm-in" wire:model.live.debounce.400ms="syn.{{ $i }}.from" maxlength="120" @disabled(! $edit)></td>
              <td><input class="lcm-in" wire:model.live.debounce.400ms="syn.{{ $i }}.to" maxlength="120" @disabled(! $edit)></td>
              <td><label class="lcm-tg"><input type="checkbox" wire:model.live="syn.{{ $i }}.on" @disabled(! $edit)><i></i></label></td>
              <td class="n">@if($edit)<button type="button" class="lcm-btn r s" wire:click="askRemove({{ $i }})">Remove…</button>@endif</td>
            </tr>
          @empty
            <tr><td colspan="4" class="mu">No default synonyms.</td></tr>
          @endforelse
          </tbody>
        </table>
      </div>
      <div class="lcm-save">
        @if($edit)<button type="button" class="lcm-btn g" wire:click="openAdd">Add a synonym</button>@endif
        <span style="flex:1"></span>
        <span class="sm" style="color:{{ $dirtySyn ? 'rgb(var(--warning-500))' : 'var(--m-mu)' }}">{{ $dirtySyn ? 'Unsaved changes' : 'No unsaved changes' }}</span>
        @if($edit)<button type="button" class="lcm-btn g" wire:click="discard('syn')" @disabled(! $dirtySyn)>Discard</button><button type="button" class="lcm-btn" wire:click="saveSyn" @disabled(! $dirtySyn)>Save changes</button>@endif
      </div>
    </section>
    <div class="lcm-note"><b>How this works</b><div>Turning a default off here removes it for every shop. Removing one also clears it from shops that had switched it off. Shops pick up a change within a minute.</div></div>
  @endif

  @if($tab === 'stop')
    <section class="lcm-sec">
      <header><h3>Stop words</h3><p>Words ignored when they are the only thing that stops a search matching. "case for phone" still finds a phone case.</p></header>
      <div class="b" style="padding:16px 22px">
        <div style="display:flex;flex-wrap:wrap;gap:6px">
          @forelse($stop as $i => $w)
            <span class="lcm-badge b-gray" wire:key="stop-{{ $w }}">{{ $w }} @if($edit)<button type="button" wire:click="removeStop({{ $i }})" aria-label="Remove {{ $w }}" style="margin-left:6px;opacity:.7">✕</button>@endif</span>
          @empty
            <span class="mu sm">No stop words.</span>
          @endforelse
        </div>
        @if($edit)
          <div style="display:flex;gap:8px;margin-top:14px;max-width:420px"><input class="lcm-in" style="flex:1" wire:model="newStop" wire:keydown.enter="addStop" placeholder="Add words, separated by commas"><button type="button" class="lcm-btn g" wire:click="addStop">Add</button></div>
        @endif
      </div>
      <div class="lcm-save">
        <span style="flex:1"></span>
        <span class="sm" style="color:{{ $dirtyStop ? 'rgb(var(--warning-500))' : 'var(--m-mu)' }}">{{ $dirtyStop ? 'Unsaved changes' : 'No unsaved changes' }}</span>
        @if($edit)<button type="button" class="lcm-btn g" wire:click="discard('stop')" @disabled(! $dirtyStop)>Discard</button><button type="button" class="lcm-btn" wire:click="saveStop" @disabled(! $dirtyStop)>Save changes</button>@endif
      </div>
    </section>
  @endif

  @if($tab === 'plans')
    <section class="lcm-sec">
      <header><h3>Search features by plan</h3><p>Basic search is on for every shop with an online store. This controls the extras.</p></header>
      <div class="lcm-tbl">
        <table>
          <thead><tr><th>Feature</th>@foreach(SearchConfig::TIERS as $t => $tl)<th style="text-align:center">{{ $tl }}</th>@endforeach</tr></thead>
          <tbody>
          @foreach(SearchConfig::FEATURES as $k => $f)
            <tr>
              <td>{{ $f[0] }}</td>
              @foreach(SearchConfig::TIERS as $t => $tl)
                <td style="text-align:center"><label class="lcm-tg"><input type="checkbox" wire:model.live="plans.{{ $k }}.{{ $t }}" @disabled(! $edit)><i></i></label></td>
              @endforeach
            </tr>
          @endforeach
          </tbody>
        </table>
      </div>
      <div class="lcm-save">
        <span style="flex:1"></span>
        <span class="sm" style="color:{{ $dirtyPlans ? 'rgb(var(--warning-500))' : 'var(--m-mu)' }}">{{ $dirtyPlans ? 'Unsaved changes' : 'No unsaved changes' }}</span>
        @if($edit)<button type="button" class="lcm-btn g" wire:click="discard('plans')" @disabled(! $dirtyPlans)>Discard</button><button type="button" class="lcm-btn" wire:click="savePlans" @disabled(! $dirtyPlans)>Save changes</button>@endif
      </div>
    </section>
    <div class="lcm-note"><b>What a shop sees</b><div>A feature its plan does not include shows in the shop's Reports › Search as a locked row naming the plan that has it. Rules and promotions a shop already made keep working after a downgrade; it just cannot add new ones. Custom-plan shops get what Scale gets.</div></div>
  @endif

  @if($tab === 'usage')
    @php $u = $this->usage(); @endphp
    <div class="lcm-stats">
      <div class="lcm-sec lcm-stat"><div class="l">Shops with searches (30 days)</div><div class="v">{{ number_format($u['shops']) }}</div></div>
      <div class="lcm-sec lcm-stat"><div class="l">Searches</div><div class="v">{{ number_format($u['n']) }}</div></div>
      <div class="lcm-sec lcm-stat"><div class="l">No results</div><div class="v">{{ $u['zero'] }}%</div></div>
      <div class="lcm-sec lcm-stat"><div class="l">Shops with rules</div><div class="v">{{ number_format($u['rules']) }}</div></div>
      <div class="lcm-sec lcm-stat"><div class="l">Shops with promotions</div><div class="v">{{ number_format($u['promos']) }}</div></div>
    </div>
    <section class="lcm-sec">
      <header><h3>Most common searches with no results, across shops</h3><p>Good candidates for a new default synonym. Updated every ten minutes.</p></header>
      <div class="lcm-tbl">
        <table>
          <thead><tr><th>Search</th><th class="n">Shops</th><th class="n">Times</th><th></th></tr></thead>
          <tbody>
          @forelse($u['common'] as $c)
            <tr><td><b>{{ $c['q'] }}</b></td><td class="n">{{ $c['shops'] }}</td><td class="n">{{ number_format($c['n']) }}</td>
              <td class="n">@if($edit)<button type="button" class="lcm-btn g s" wire:click="openAdd({{ \Illuminate\Support\Js::from($c['q']) }})">Make a default…</button>@endif</td></tr>
          @empty
            <tr><td colspan="4" class="mu">No searches with no results in the last 30 days.</td></tr>
          @endforelse
          </tbody>
        </table>
      </div>
    </section>
  @endif
</div>

<x-filament::modal id="sd-add" width="md">
  <x-slot name="heading">Add a default synonym</x-slot>
  <div class="lcm lcm-modal" style="gap:0">
    <label>Shopper types</label><input class="lcm-in" wire:model="add.from" maxlength="120" placeholder="e.g. tv">
    @error('add.from')<div class="lcm-err">{{ $message }}</div>@enderror
    <label>Treated as</label><input class="lcm-in" wire:model="add.to" maxlength="120" placeholder="e.g. television">
    @error('add.to')<div class="lcm-err">{{ $message }}</div>@enderror
    <p class="mu sm" style="margin-top:10px">Applies to every shop that has not set its own synonym for this word, once you press Save changes.</p>
  </div>
  <x-slot name="footerActions">
    <x-filament::button color="gray" x-on:click="close()">Cancel</x-filament::button>
    <x-filament::button wire:click="addSyn">Add</x-filament::button>
  </x-slot>
</x-filament::modal>

<x-filament::modal id="sd-del" width="md">
  <x-slot name="heading">Remove this default?</x-slot>
  <p class="text-sm" style="color:rgb(var(--gray-500))">"{{ $removing !== null ? ($syn[$removing]['from'] ?? '') : '' }}" stops being a default for every shop when you press Save changes. Shops that made their own synonym keep it.</p>
  <x-slot name="footerActions">
    <x-filament::button color="gray" x-on:click="close()">Cancel</x-filament::button>
    <x-filament::button color="danger" wire:click="removeSyn">Remove</x-filament::button>
  </x-slot>
</x-filament::modal>
</x-filament-panels::page>
