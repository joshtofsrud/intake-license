{{-- MARKER-SHOP-SEARCH-V2: master admin › Search defaults. Styled on Filament's own colour
     variables (the same set as Launch checklists) so it follows light and dark. --}}
@php
  use App\Support\ShopSearch\SearchConfig;
  $edit = $this->canEdit();
@endphp
<x-filament-panels::page>
<style>
  .lcm{--m-card:#fff;--m-ring:rgba(9,9,11,.08);--m-tx:rgb(var(--gray-950));--m-mu:rgb(var(--gray-500));--m-row:rgba(9,9,11,.06);--m-head:rgb(var(--gray-50));--m-in:#fff;--m-inring:rgba(9,9,11,.18);display:flex;flex-direction:column;gap:24px}
  .dark .lcm{--m-card:rgb(var(--gray-900));--m-ring:rgba(255,255,255,.1);--m-tx:#fff;--m-mu:rgb(var(--gray-400));--m-row:rgba(255,255,255,.06);--m-head:rgba(255,255,255,.04);--m-in:rgba(255,255,255,.05);--m-inring:rgba(255,255,255,.2)}
  .lcm-tabs{display:flex;gap:4px;overflow-x:auto;padding:4px;border-radius:12px;background:var(--m-card);box-shadow:0 0 0 1px var(--m-ring);width:max-content;max-width:100%}
  .lcm-tabs button,.lcm-tabs a{border:0;background:transparent;color:var(--m-mu);font-weight:500;font-size:14px;padding:7px 12px;border-radius:8px;cursor:pointer;white-space:nowrap}
  .lcm-tabs button:hover,.lcm-tabs a:hover{background:var(--m-row)}
  .lcm-tabs .on{background:rgba(var(--primary-500),.12);color:rgb(var(--primary-600))}.dark .lcm-tabs .on{color:rgb(var(--primary-400))}
  .lcm-bar2{display:flex;gap:12px;flex-wrap:wrap;align-items:center}
  .lcm-sec{background:var(--m-card);border-radius:12px;box-shadow:0 0 0 1px var(--m-ring),0 1px 2px rgba(0,0,0,.05)}
  .lcm-sec>header{padding:14px 22px;border-bottom:1px solid var(--m-row)}.lcm-sec>header h3{font-weight:600;font-size:16px;line-height:24px;color:var(--m-tx)}.lcm-sec>header p{font-size:13.5px;color:var(--m-mu);margin-top:2px}
  .lcm-sec .b{padding:6px 22px}.lcm-sec.p{padding:16px 22px}
  .lcm-row{display:flex;justify-content:space-between;align-items:center;gap:20px;padding:14px 0;border-bottom:1px solid var(--m-row)}.lcm-row:last-child{border:0}
  .lcm-row .t{font-size:14px;font-weight:500;color:var(--m-tx)}.lcm-row .d{font-size:13px;color:var(--m-mu);margin-top:2px;max-width:62ch}
  .lcm-ctl{display:flex;gap:12px;align-items:center;flex:none;font-size:13.5px;color:var(--m-tx)}
  .lcm-tbl{overflow-x:auto}.lcm-tbl table{width:100%;border-collapse:collapse;font-size:14px;color:var(--m-tx)}
  .lcm-tbl th{text-align:left;font-weight:600;font-size:13.5px;padding:12px;background:var(--m-head);white-space:nowrap}.lcm-tbl td{padding:10px 12px;border-top:1px solid var(--m-row);vertical-align:middle}
  .lcm-tbl th:first-child,.lcm-tbl td:first-child{padding-left:22px}.lcm-tbl th:last-child,.lcm-tbl td:last-child{padding-right:22px}
  .lcm-tbl .n{text-align:right;white-space:nowrap}.lcm .mu{color:var(--m-mu)}.lcm .sm{font-size:13px}
  .lcm-in,.lcm-sel,.lcm-ta{background:var(--m-in);color:var(--m-tx);border:0;border-radius:8px;box-shadow:inset 0 0 0 1px var(--m-inring);padding:6px 10px;font-size:14px;outline:none;max-width:100%}
  .lcm-sel{padding-right:30px;appearance:none;-webkit-appearance:none;background-repeat:no-repeat;background-position:right .45rem center;background-size:1.1em}.lcm-sel option{background:rgb(var(--gray-900));color:#fff}
  .lcm-in:focus,.lcm-sel:focus,.lcm-ta:focus{box-shadow:inset 0 0 0 2px rgb(var(--primary-500))}
  .lcm-ta,.lcm-modal .lcm-in,.lcm-modal .lcm-sel{width:100%;display:block}
  .lcm-tg{position:relative;display:inline-block;width:44px;height:24px;flex:none}.lcm-tg input{position:absolute;inset:0;opacity:0;margin:0;cursor:pointer;z-index:1}.lcm-tg i{position:absolute;inset:0;border-radius:99px;background:rgb(var(--gray-300));transition:.15s}.dark .lcm-tg i{background:rgb(var(--gray-700))}.lcm-tg i:before{content:'';position:absolute;top:2px;left:2px;width:20px;height:20px;border-radius:50%;background:#fff;box-shadow:0 1px 2px rgba(0,0,0,.3);transition:.15s}.lcm-tg input:checked+i{background:rgb(var(--primary-600))}.lcm-tg input:checked+i:before{transform:translateX(20px)}.lcm-tg input:disabled+i{opacity:.4}
  .lcm-btn{display:inline-flex;align-items:center;gap:6px;font-weight:600;font-size:14px;border-radius:8px;padding:6px 12px;cursor:pointer;border:0;background:rgb(var(--primary-600));color:#fff;box-shadow:0 1px 2px rgba(0,0,0,.2)}.lcm-btn:hover{background:rgb(var(--primary-500))}
  .lcm-btn.g{background:var(--m-card);color:var(--m-tx);box-shadow:0 0 0 1px var(--m-inring)}.lcm-btn.g:hover{background:var(--m-row)}
  .lcm-btn.r{background:transparent;color:rgb(var(--danger-500));box-shadow:0 0 0 1px rgba(var(--danger-500),.5)}.lcm-btn.r:hover{background:rgba(var(--danger-500),.1)}
  .lcm-btn.s{padding:3px 9px;font-size:13px}.lcm-btn:disabled{opacity:.45;cursor:default}
  .lcm-badge{display:inline-flex;align-items:center;font-weight:500;font-size:12px;padding:2px 8px;border-radius:6px;white-space:nowrap}
  .b-ok{background:rgba(var(--success-500),.12);color:rgb(var(--success-600));box-shadow:inset 0 0 0 1px rgba(var(--success-600),.25)}.dark .b-ok{color:rgb(var(--success-400))}
  .b-bad{background:rgba(var(--danger-500),.12);color:rgb(var(--danger-600));box-shadow:inset 0 0 0 1px rgba(var(--danger-600),.25)}.dark .b-bad{color:rgb(var(--danger-400))}
  .b-warn{background:rgba(var(--warning-500),.12);color:rgb(var(--warning-700));box-shadow:inset 0 0 0 1px rgba(var(--warning-600),.3)}.dark .b-warn{color:rgb(var(--warning-400))}
  .b-pri{background:rgba(var(--primary-500),.12);color:rgb(var(--primary-600));box-shadow:inset 0 0 0 1px rgba(var(--primary-600),.25)}.dark .b-pri{color:rgb(var(--primary-400))}
  .b-gray{background:rgba(var(--gray-500),.1);color:rgb(var(--gray-600));box-shadow:inset 0 0 0 1px rgba(var(--gray-500),.25)}.dark .b-gray{color:rgb(var(--gray-300))}
  .lcm-bar{height:6px;border-radius:99px;background:var(--m-row);overflow:hidden;min-width:90px}.lcm-bar i{display:block;height:100%;background:rgb(var(--primary-500))}
  .lcm-note{border-radius:12px;padding:12px 18px;font-size:13.5px;background:rgba(var(--primary-500),.07);box-shadow:inset 0 0 0 1px rgba(var(--primary-500),.25);color:var(--m-tx)}.lcm-note b{color:rgb(var(--primary-600))}.dark .lcm-note b{color:rgb(var(--primary-400))}.lcm-note div{margin-top:5px;color:var(--m-mu)}
  .lcm-stats{display:grid;grid-template-columns:repeat(auto-fit,minmax(170px,1fr));gap:16px}.lcm-stat{padding:14px 18px}.lcm-stat .l{font-size:13px;color:var(--m-mu)}.lcm-stat .v{font-size:28px;font-weight:700;color:var(--m-tx);line-height:1.2}
  .lcm-save{display:flex;gap:12px;align-items:center;padding:14px 22px;border-top:1px solid var(--m-row)}
  .lcm-two{display:grid;grid-template-columns:1fr 1fr;gap:24px}
  .lcm-modal label{display:block;font-weight:500;font-size:14px;margin:14px 0 5px}
  .lcm-err{color:rgb(var(--danger-500));font-size:13px;margin-top:6px}
  @media(max-width:900px){.lcm-two{grid-template-columns:1fr}.lcm-row{flex-direction:column;align-items:flex-start}}
</style>

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
