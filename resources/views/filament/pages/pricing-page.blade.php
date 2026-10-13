{{-- MARKER-LIVE-PRICING: preview + editor for intake.works/pricing --}}
@php
    $plans  = \App\Support\PricingCatalog::plans();
    $groups = \App\Support\PricingCatalog::groups(false);
    $issues = \App\Support\PricingCatalog::issues();
    $money  = fn (int $c) => number_format($c / 100, $c % 100 ? 2 : 0);
    $dirty  = $this->isDirty();
    $ed     = $this->editing();
@endphp
<x-filament-panels::page>
<style>
  .pp{--ln:rgba(127,127,127,.2);--ln2:rgba(127,127,127,.32);--mute:rgba(127,127,127,.95);font-size:14px}
  .pp-sub{opacity:.7;max-width:760px}
  .pp-lab{font-size:10.5px;letter-spacing:.09em;text-transform:uppercase;font-weight:700;opacity:.55}
  .pp-bar{display:flex;gap:10px;align-items:center;flex-wrap:wrap}
  .pp-iss{border-top:1px solid var(--ln);border-bottom:1px solid var(--ln);padding:10px 0}
  .pp-iss div{display:grid;grid-template-columns:14px minmax(0,1fr) auto;gap:10px;padding:6px 0;align-items:baseline}
  .pp-iss i{width:8px;height:8px;border-radius:50%;background:#f0c46a;display:inline-block}
  .pp-iss .bad i{background:#f87171}
  .pp-iss span{opacity:.75}
  .pp table{width:100%;border-collapse:collapse}
  .pp thead th{text-align:center;font-weight:600;padding:10px;border-bottom:1px solid var(--ln2);font-size:13px}
  .pp thead th:first-child{text-align:left}
  .pp thead th small{display:block;font-weight:500;opacity:.6}
  .pp tbody th{text-align:left;font-weight:500;padding:9px 10px 9px 0}
  .pp tbody th small{display:block;font-size:11px;opacity:.5;font-weight:400;font-family:ui-monospace,monospace}
  .pp tbody td{text-align:center;padding:5px}
  .pp tbody tr{border-bottom:1px solid var(--ln)}
  .pp tr.grp th{padding:22px 0 8px;font-size:10.5px;letter-spacing:.1em;text-transform:uppercase;font-weight:700;opacity:.55}
  .pp-c{display:inline-block;min-width:100px;padding:6px 10px;border-radius:7px;border:1px solid transparent;font-size:13px;background:none;cursor:pointer;color:inherit}
  .pp-c:hover{border-color:var(--ln2)}
  .pp-c.inc{color:#84cc16;font-weight:600}
  .pp-c.ask{opacity:.7}
  .pp-c.no{opacity:.3}
  .pp-c.hide{color:#d4a017;border-color:rgba(240,196,106,.35);background:rgba(240,196,106,.07)}
  .pp input.pp-in,.pp textarea.pp-in,.pp select.pp-in{width:100%;background:transparent;border:1px solid var(--ln2);border-radius:7px;padding:7px 9px;font-size:13.5px;color:inherit}
  .pp .pp-in:focus{outline:none;border-color:#8b7cf6}
  .pp-row{display:grid;grid-template-columns:minmax(0,1.4fr) repeat(3,minmax(0,1fr)) 74px;gap:8px;align-items:center;padding:6px 0;border-bottom:1px solid var(--ln)}
  .pp-row.h{font-size:12px;font-weight:600;opacity:.6;border-bottom-color:var(--ln2)}
  .pp-x{background:none;border:0;cursor:pointer;opacity:.55;font-size:15px;padding:2px 5px;color:inherit}
  .pp-x:hover{opacity:1}
  .pp-blurbs{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:12px}
  .pp-dirty{color:#d4a017;font-size:12.5px}
  .pp-legend{font-size:12px;opacity:.65;line-height:1.65;border-top:1px solid var(--ln);padding-top:12px;max-width:940px}
  .pp-shade{position:fixed;inset:0;background:rgba(0,0,0,.45);z-index:40}
  .pp-drawer{position:fixed;top:0;right:0;bottom:0;width:380px;max-width:100%;z-index:41;overflow:auto;padding:22px;border-left:1px solid var(--ln2);background:#111113;color:#f4f4f5}
  .pp-drawer .f{margin-top:14px}
  .pp-drawer label.t{display:block;font-size:12px;opacity:.6;margin-bottom:5px}
  .pp-drawer select,.pp-drawer .pp-in{background:#18181b}
  .pp-chk{display:flex;gap:14px;flex-wrap:wrap}
  .pp-chk label{display:flex;gap:6px;align-items:center;font-size:13.5px}
  @media (max-width:900px){.pp-blurbs{grid-template-columns:1fr}.pp-row{grid-template-columns:1fr 1fr}.pp-row.h{display:none}}
</style>

<div class="pp" style="display:grid;gap:26px">

  <div class="pp-bar" style="justify-content:space-between">
    <p class="pp-sub">How intake.works/pricing reads right now, from the same data the public page uses. Click any add-on cell to change who gets it.</p>
    <x-filament::button tag="a" color="gray" href="{{ url('/pricing') }}" target="_blank">Open /pricing</x-filament::button>
  </div>

  @if(count($issues))
    <div>
      <div class="pp-lab" style="margin-bottom:4px">Needs a decision</div>
      <div class="pp-iss">
        @foreach($issues as [$sev, $title, $detail, $id])
          <div class="{{ $sev }}"><i></i><div><b>{{ $title }}</b> <span>{{ $detail }}</span></div>
            @if($id)<a href="#" wire:click.prevent="openAddon({{ $id }})" style="color:#8b7cf6;font-size:13px">Fix</a>@else<span></span>@endif
          </div>
        @endforeach
      </div>
    </div>
  @endif

  {{-- plan one-liners + basics: one Save for both --}}
  <div>
    <div class="pp-bar" style="justify-content:space-between;margin-bottom:10px">
      <div class="pp-lab">Plan cards</div>
      <div class="pp-bar">
        @if($dirty)<span class="pp-dirty">Unsaved changes</span>
          <x-filament::button color="gray" size="sm" wire:click="discardText">Discard</x-filament::button>@endif
        <x-filament::button size="sm" wire:click="saveText" :disabled="! $dirty">Save</x-filament::button>
      </div>
    </div>
    <div class="pp-blurbs">
      @foreach($plans as $p)
        <div>
          <div style="font-weight:600;margin-bottom:6px">{{ $p['name'] }} <span style="opacity:.55;font-weight:400">${{ $money($p['cents']) }}/mo</span></div>
          <textarea class="pp-in" rows="2" wire:model.live.debounce.400ms="blurbs.{{ $p['k'] }}" maxlength="200"></textarea>
        </div>
      @endforeach
    </div>

    <div class="pp-lab" style="margin:22px 0 6px">The basics · rows that aren't add-ons. Type yes or no for a tick or a dash.</div>
    <div class="pp-row h"><span>Row</span><span>Starter</span><span>Branded</span><span>Scale</span><span></span></div>
    @foreach($basics as $i => $r)
      <div class="pp-row" wire:key="basic-{{ $i }}">
        <input class="pp-in" wire:model.live.debounce.400ms="basics.{{ $i }}.0" maxlength="80" placeholder="Row name">
        <input class="pp-in" wire:model.live.debounce.400ms="basics.{{ $i }}.1" maxlength="60">
        <input class="pp-in" wire:model.live.debounce.400ms="basics.{{ $i }}.2" maxlength="60">
        <input class="pp-in" wire:model.live.debounce.400ms="basics.{{ $i }}.3" maxlength="60">
        <span style="white-space:nowrap">
          <button type="button" class="pp-x" wire:click="moveRow({{ $i }}, -1)" title="Move up">&uarr;</button><button type="button" class="pp-x" wire:click="moveRow({{ $i }}, 1)" title="Move down">&darr;</button><button type="button" class="pp-x" wire:click="removeRow({{ $i }})" title="Remove row">&times;</button>
        </span>
      </div>
    @endforeach
    <div style="margin-top:10px"><x-filament::button color="gray" size="sm" wire:click="addRow">+ Add row</x-filament::button></div>
  </div>

  {{-- add-ons by plan --}}
  <div>
    <div class="pp-lab" style="margin-bottom:4px">Add-ons on each plan</div>
    <table>
      <thead><tr><th>Add-on</th>@foreach($plans as $p)<th>{{ $p['name'] }}<small>${{ $money($p['cents']) }}/mo</small></th>@endforeach</tr></thead>
      <tbody>
        @foreach($groups as $gName => $rows)
          <tr class="grp"><th colspan="4">{{ $gName }}</th></tr>
          @foreach($rows as $r)
            <tr>
              <th>{{ $r['addon']->name }}<small>{{ $r['addon']->code }}{{ $r['addon']->min_plan_tier ? ' · '.ucfirst($r['addon']->min_plan_tier).' and up' : '' }}</small></th>
              @foreach($r['cells'] as $cell)
                <td><button type="button" class="pp-c {{ $cell['t'] }}" wire:click="openAddon({{ $r['addon']->id }})">
                  @if($cell['t'] === 'no')&mdash;@elseif($cell['t'] === 'ask')Ask us{{ isset($cell['price']) ? ' · '.$cell['price'] : '' }}@else{{ $cell['s'] }}@endif
                </button></td>
              @endforeach
            </tr>
          @endforeach
        @endforeach
      </tbody>
    </table>
  </div>

  <div class="pp-legend">
    <b>What this changes.</b> Saving here changes intake.works/pricing straight away; there is no publish step. Plan prices are not edited here, they come from Billing &rsaquo; Plan prices. Add-on prices are not edited here either, they come from Billing &rsaquo; Add-ons, where they can be dated. Who gets an add-on (the drawer) is the real setting: it changes what shops on that plan can use today, not only what the page says.
    Add-ons that are closed or retired, and hidden add-ons no plan includes, never appear on the page. Amber cells have no price yet, so the public page leaves them out.
  </div>
</div>

@if($ed)
  <div class="pp-shade" wire:click="closeAddon"></div>
  <aside class="pp-drawer">
    <div style="display:flex;justify-content:space-between;align-items:flex-start;gap:10px">
      <div><div style="font-size:18px;font-weight:700">{{ $ed->name }}</div><div style="font-family:ui-monospace,monospace;font-size:11.5px;opacity:.55">{{ $ed->code }}</div></div>
      <button type="button" class="pp-x" wire:click="closeAddon" style="font-size:20px">&times;</button>
    </div>
    <div class="f"><label class="t">Price</label>
      <div>{{ \App\Support\PricingCatalog::priceText($ed) ?? 'No price set' }} &nbsp;<a href="{{ $this->addonEditUrl($ed->id) }}" style="color:#a78bfa;font-size:13px">Change price</a></div>
    </div>
    <div class="f"><label class="t">Included free with</label>
      <div class="pp-chk">
        @foreach(\App\Support\PricingCatalog::NAMES as $k => $n)
          <label><input type="checkbox" value="{{ $k }}" wire:model="edit.included"> {{ $n }}</label>
        @endforeach
      </div>
    </div>
    <div class="f"><label class="t">Lowest plan that can have it</label>
      <select class="pp-in" wire:model="edit.min"><option value="">Any plan</option><option value="branded">Branded and up</option><option value="scale">Scale only</option></select>
    </div>
    <div class="f"><label class="t">Shops see it as</label>
      <select class="pp-in" wire:model="edit.visibility">@foreach(\App\Models\Addon::VISIBILITIES as $v => $l)<option value="{{ $v }}">{{ $l }}</option>@endforeach</select>
    </div>
    <div class="f"><label class="t">Status</label>
      <select class="pp-in" wire:model="edit.status">@foreach(\App\Models\Addon::STATUSES as $v => $l)<option value="{{ $v }}">{{ $l }}</option>@endforeach</select>
    </div>
    <p style="font-size:12px;opacity:.6;margin-top:14px">A lowest plan above an "included" plan wins: that plan does not get it.</p>
    <div style="display:flex;gap:8px;margin-top:18px">
      <x-filament::button wire:click="saveAddon">Save</x-filament::button>
      <x-filament::button color="gray" wire:click="closeAddon">Cancel</x-filament::button>
    </div>
  </aside>
@endif
</x-filament-panels::page>
