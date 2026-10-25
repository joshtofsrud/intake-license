<x-filament-panels::page>
@include('filament.partials.dashboards-style')
<style>
  .de-top { display:flex; align-items:center; gap:10px; flex-wrap:wrap; padding-bottom:12px; border-bottom:1px solid var(--sx-line); }
  .de-top .sp { margin-left:auto; display:flex; gap:8px; flex-wrap:wrap; align-items:center; }
  .de-grid { display:grid; grid-template-columns:280px minmax(0,1fr); gap:0; align-items:start; }
  .de-panel { border-right:1px solid var(--sx-line); padding:14px 18px 14px 0; position:sticky; top:72px; max-height:calc(100vh - 90px); overflow:auto; }
  .de-canvas { padding:14px 0 14px 22px; min-width:0; }
  .de-g { font-size:10.5px; letter-spacing:.09em; text-transform:uppercase; color:var(--sx-faint); font-weight:700; padding:14px 0 6px; }
  .de-lib { display:grid; grid-template-columns:14px 1fr auto; gap:8px; align-items:center; padding:7px 6px 7px 2px; border-radius:6px; font-size:13px; border:1px solid transparent; cursor:grab; }
  .de-lib:hover { background:var(--db-hover); border-color:var(--sx-line); }
  .de-lib.on { opacity:.5; cursor:default; }
  .de-lib.hid .t { text-decoration:line-through; }
  .de-lib .s { display:block; font-size:11.5px; color:var(--sx-faint); }
  .de-grip { color:var(--sx-faint); font-size:11px; letter-spacing:-1px; }
  .de-row { border:1px solid var(--sx-line); border-radius:10px; margin-bottom:14px; }
  .de-rowh { display:flex; align-items:center; gap:8px; padding:8px 10px; border-bottom:1px solid var(--sx-line); font-size:12.5px; color:var(--sx-faint); flex-wrap:wrap; }
  .de-rowh b { color:var(--db-txt); font-weight:600; }
  .de-rowh select { width:auto; padding:3px 26px 3px 8px; font-size:12px; }
  .de-rowh .sp { margin-left:auto; display:flex; gap:4px; }
  .de-cols { display:grid; grid-template-columns:repeat(12, minmax(0, 1fr)); gap:10px; padding:10px; }
  .de-col { border:1.5px dashed var(--sx-line-2); border-radius:8px; padding:8px; min-height:74px; display:flex; flex-direction:column; gap:6px; cursor:pointer; }
  .de-col.target { border-color:var(--sx-violet); border-style:solid; }
  .de-col.over { border-color:var(--sx-violet); background:var(--sx-vsoft); }
  .de-col .empty { margin:auto; font-size:12px; color:var(--sx-faint); text-align:center; }
  .de-col .colh { font-size:10.5px; color:var(--sx-faint); letter-spacing:.06em; text-transform:uppercase; font-weight:600; }
  .de-blk { display:grid; grid-template-columns:14px 1fr auto; gap:8px; align-items:center; background:var(--db-card); border:1px solid var(--sx-line-2); border-radius:7px; padding:8px 8px 8px 6px; cursor:grab; }
  .de-blk .t { font-size:13px; font-weight:600; color:var(--db-txt); }
  .de-blk .s { font-size:11.5px; color:var(--sx-faint); }
  .de-blk.hid { border-style:dashed; opacity:.6; }
  .de-blk.sel { border-color:var(--sx-violet); box-shadow:0 0 0 1px var(--sx-violet); }
  .de-blk .who { display:inline-block; margin-top:3px; font-size:11px; color:var(--db-vtext); }
  .de-blk .pick { background:none; border:0; padding:0; text-align:left; color:inherit; font:inherit; cursor:pointer; }
  .de-set { border:1px solid var(--sx-violet); border-radius:10px; padding:10px 12px; margin:6px 0 4px; }
  .de-set h4 { font-size:13.5px; font-weight:700; color:var(--db-txt); margin:0 0 8px; display:flex; }
  .de-set h4 button { margin-left:auto; }
  .de-chk { display:flex; gap:8px; align-items:center; font-size:13px; padding:3px 0; cursor:pointer; }
  .de-chk input, .de-opt input { accent-color:#8b5cf6 !important; color:#8b5cf6 !important; }
  .de-opt { display:flex; gap:8px; align-items:flex-start; font-size:13px; padding:5px 0; cursor:pointer; }
  .de-opt input { margin-top:3px; }
  .de-opt small { display:block; font-size:11.5px; color:var(--sx-faint); }
  .de-chips { display:flex; flex-wrap:wrap; gap:6px; margin:6px 0; }
  .de-chip { display:inline-flex; align-items:center; gap:4px; border:1px solid var(--sx-line-2); border-radius:99px; padding:2px 4px 2px 9px; font-size:12px; }
  .de-chip button { background:none; border:0; color:var(--sx-faint); cursor:pointer; font-size:13px; padding:0 3px; }
  .de-hits { border:1px solid var(--sx-line-2); border-radius:7px; margin-top:4px; }
  .de-hits button { display:block; width:100%; text-align:left; background:none; border:0; border-bottom:1px solid var(--sx-line); padding:6px 8px; font:inherit; font-size:12.5px; color:inherit; cursor:pointer; }
  .de-hits button:hover { background:var(--sx-vsoft); }
  .de-ctl { display:flex; gap:2px; }
  .de-ctl button { background:none; border:0; color:var(--sx-faint); cursor:pointer; font-size:13px; padding:2px 5px; border-radius:4px; }
  .de-ctl button:hover { color:var(--db-txt); background:var(--db-hover); }
  .de-ctl button[disabled] { opacity:.3; cursor:default; }
  .de-addrow { display:flex; gap:6px; flex-wrap:wrap; align-items:center; border:1.5px dashed var(--sx-line-2); border-radius:10px; padding:12px; font-size:12.5px; color:var(--sx-faint); }
  .de-split { display:inline-flex; gap:2px; height:9px; width:24px; vertical-align:middle; margin-right:4px; }
  .de-split i { display:block; background:currentColor; opacity:.5; border-radius:1.5px; }
  .de-f { margin-bottom:12px; }
  .de-f label { display:block; font-size:12px; color:var(--sx-faint); margin-bottom:4px; }
  .de-ver { display:flex; gap:8px; align-items:baseline; padding:7px 0; border-bottom:1px solid var(--sx-line); font-size:12.5px; }
  .de-ver .sp { margin-left:auto; }
  .de-ch { list-style:none; margin:0 0 12px; padding:0; border-top:1px solid var(--sx-line); }
  .de-ch li { padding:7px 0; border-bottom:1px solid var(--sx-line); font-size:13px; }
  @media (max-width:1100px) {
    .de-grid { grid-template-columns:1fr; }
    .de-panel { position:static; max-height:none; border-right:0; border-top:1px solid var(--sx-line); padding:14px 0; order:2; }
    .de-canvas { padding:14px 0; order:1; }
  }
  @media (max-width:640px) {
    .de-cols { display:block; }
    .de-col + .de-col { margin-top:10px; }
  }
</style>

@php
  $splitIcon = fn ($sp) => '<span class="de-split">' . collect($sp)->map(fn ($n) => '<i style="flex:' . $n . '"></i>')->implode('') . '</span>';
@endphp

<div class="sx-root" x-data="{
    over: null,
    drag(e, data) { e.dataTransfer.setData('text/plain', JSON.stringify(data)); e.dataTransfer.effectAllowed = 'move'; },
    at(e, col) {
      const kids = [...col.querySelectorAll(':scope > .de-blk')];
      for (let i = 0; i < kids.length; i++) { const b = kids[i].getBoundingClientRect(); if (e.clientY < b.top + b.height / 2) return i; }
      return kids.length;
    },
    drop(e, r, c) {
      this.over = null;
      let data; try { data = JSON.parse(e.dataTransfer.getData('text/plain')); } catch (err) { return; }
      $wire.dropAt(data, r, c, this.at(e, e.currentTarget));
    }
  }">

  <div style="font-size:13px;color:var(--sx-faint);margin:-6px 0 8px"><a class="db-lnk" href="{{ \App\Filament\Pages\Dashboards::getUrl() }}">Dashboards</a> / {{ $tpl->name }}</div>

  <div class="de-top">
    @if ($tpl->state === 'live')
      <span class="db-tag live">Live v{{ $tpl->liveVersion?->version }}</span>
      <span class="db-note">{{ number_format($picked) }} {{ \Illuminate\Support\Str::plural('person', $picked) }} picked it{{ $tpl->is_default ? ', and it is the default' : '' }}</span>
    @elseif ($tpl->state === 'retired')
      <span class="db-tag off">Retired</span><span class="db-note">Restore it from Dashboards to edit it.</span>
    @else
      <span class="db-tag draft">Draft only</span><span class="db-note">No shop sees it yet.</span>
    @endif
    @if ($canEdit)
      <span class="sp">
        @if ($dirty)
          <button type="button" class="db-btn" wire:click="revertScreen">Undo unsaved changes</button>
        @elseif ($hasDraft && $tpl->liveVersion)
          <button type="button" class="db-btn warn" wire:click="discardDraft" data-confirm="Discard the draft?&#10;The editor goes back to v{{ $tpl->liveVersion->version }}, which is what shops see now.">Discard draft</button>
        @endif
        <button type="button" class="db-btn" wire:click="saveDraft" @disabled(! $dirty)>{{ $dirty ? 'Save draft' : 'Draft saved' }}</button>
        <x-filament::modal id="publish-dashboard" width="lg">
          <x-slot name="trigger"><button type="button" class="db-btn p" @disabled(! $canPublish)>Publish…</button></x-slot>
          <x-slot name="heading">Publish {{ $tpl->name }} v{{ ($tpl->liveVersion?->version ?? 0) + 1 }}</x-slot>
          <x-slot name="description">
            @if ($tpl->state === 'live')
              Everyone on {{ $tpl->name }} sees this on their next page load.
            @else
              It joins the dashboard switch of the shops that get it (see Who gets it). Nobody's screen changes until they pick it.
            @endif
          </x-slot>
          <div class="sx-root">
            <ul class="de-ch">
              @forelse ($changes as [$kind, $text])
                <li><span class="db-diff" style="display:inline;padding:0;border:0"><span class="{{ $kind }}">{{ $text }}</span></span></li>
              @empty
                <li class="db-note">No layout changes. Publishing offers it to shops again.</li>
              @endforelse
            </ul>
            <label class="db-note">Note for the version history (optional)
              <input type="text" class="db-in" style="margin-top:4px" maxlength="255" wire:model="note" placeholder="What changed and why">
            </label>
          </div>
          <x-slot name="footerActions">
            <x-filament::button wire:click="publish">Publish v{{ ($tpl->liveVersion?->version ?? 0) + 1 }}</x-filament::button>
            <x-filament::button color="gray" x-on:click="$dispatch('close-modal', { id: 'publish-dashboard' })">Cancel</x-filament::button>
          </x-slot>
        </x-filament::modal>
      </span>
    @endif
  </div>

  <div class="db-diff">
    @if ($changes)
      <span>{{ $tpl->liveVersion ? 'Since v' . $tpl->liveVersion->version . ':' : 'New:' }}</span>
      @foreach ($changes as [$kind, $text])<span class="{{ $kind }}">{{ $text }}</span>@endforeach
    @else
      <span>No changes from v{{ $tpl->liveVersion?->version }}. Shops see exactly this.</span>
    @endif
    @if ($dirty)<span class="db-warn" style="margin-left:auto">Unsaved</span>@elseif ($hasDraft)<span style="margin-left:auto">Draft saved {{ $tpl->updated_at?->diffForHumans() }}</span>@endif
  </div>

  <div class="db-legend" style="padding-top:10px">
    <b>This is the layout, not a preview.</b> Each box is a block; shops see them drawn with their own data and theme.
    A block that's hidden everywhere, or that a shop can't use, is left out of that shop's dashboard and the space closes up.
    Each person only sees blocks, figures and links from areas their role can open in their shop's Team &amp; access.
    On phones every row becomes one column, left to right.
  </div>

  <div class="de-grid">
    <aside class="de-panel">
      @if ($canEdit && $selBlock)
        @php $hideOff = $rearrange === 'none' || ! $customizeOn; $selAreas = \App\Support\Dashboard\BlockAccess::describe($selBlock['b']); $selRule = $registry[$selBlock['b']]['access'] ?? []; @endphp
        <div class="de-set">
          <h4>{{ $names[$selBlock['b']] ?? $selBlock['b'] }}<button type="button" class="db-lnk dim" style="font-size:12px" wire:click="selectBlock({{ $sel[0] }}, {{ $sel[1] }}, {{ $sel[2] }})">Close</button></h4>
          <div class="db-note" style="margin-bottom:4px">Who sees it</div>
          <div style="font-size:13px;margin-bottom:4px">
            @if (! $selAreas) Everyone in the shop.
            @elseif (! empty($selRule['all'])) People whose role can open {{ $selAreas }}.
            @elseif (count($selRule['any']) === 1) People whose role can open {{ $selAreas }}.
            @else People whose role can open any of: {{ $selAreas }}. Parts from areas they can't open are left out.
            @endif
          </div>
          <div class="db-note" style="margin:4px 0 10px">Follows each shop's own roles in Team &amp; access, whatever they're called. Anyone else gets the space closed up, not a gap.</div>
          <label class="de-chk" @if ($hideOff) style="opacity:.5" @endif><input type="checkbox" @checked(empty($selBlock['lock'])) wire:click="toggleLock" @disabled($hideOff)> People can hide it</label>
          <div class="db-note" style="margin-top:4px">
            @if (! $customizeOn) Customizing is turned off for every shop on the Dashboards page.
            @elseif ($rearrange === 'none') This template doesn't let people change anything (see Template settings).
            @else Untick to keep it on everyone's dashboard. @endif
            Saved with the draft; shops get it when you publish.
          </div>
        </div>
      @endif
      @if ($canEdit)
        <div class="db-note" style="margin-bottom:4px">Drag a block into a column, or click <b>Add</b> to put it in the outlined column. Click a placed block for its settings. New blocks are made on the <a class="db-lnk" href="{{ \App\Filament\Pages\DashboardBlocks::getUrl() }}">Blocks</a> page.</div>
        @foreach ($catalog as $origin => $items)
          <div class="de-g">{{ $origin === 'Custom' ? 'Made here' : 'From ' . $origin }}</div>
          @foreach ($items as $b)
            <div class="de-lib {{ $b['on'] ? 'on' : '' }} {{ $b['hidden'] ? 'hid' : '' }}" title="{{ $b['desc'] }}"
                 @if (! $b['on']) draggable="true" x-on:dragstart="drag($event, { add: '{{ $b['key'] }}' })" @endif>
              <span class="de-grip">⋮⋮</span>
              <span><span class="t">{{ $b['name'] }}</span><span class="s">{{ $b['on'] ? 'On this template' : ($b['hidden'] ? 'Hidden everywhere' : $b['desc']) }}</span></span>
              @if (! $b['on'])<button type="button" class="db-lnk" style="font-size:12px" wire:click="addBlock('{{ $b['key'] }}')">Add</button>@endif
            </div>
          @endforeach
        @endforeach

        <div class="de-g" style="padding-top:22px">Template settings</div>
        <div class="de-f"><label>Name</label><input type="text" class="db-in" maxlength="80" wire:model="name"></div>
        <div class="de-f"><label>Name in the dashboard switch</label><input type="text" class="db-in" maxlength="40" wire:model="label"></div>
        <label class="db-note" style="display:flex;gap:8px;align-items:center;margin-bottom:10px"><input type="checkbox" wire:model="beta" style="accent-color:#8b5cf6;color:#8b5cf6"> Show a BETA label in the switch</label>
        <div class="db-note" style="margin:6px 0 2px">What people can change on it</div>
        @foreach (['none' => ['Nothing', 'Everyone sees it exactly as you built it.'], 'hide' => ['Hide blocks', 'Each person can hide blocks you allow, from a Customize button.'], 'both' => ['Hide and reorder', 'Each person can also change the order within a column.']] as $rv => [$rl, $rd])
          <label class="de-opt"><input type="radio" value="{{ $rv }}" wire:model="rearrange"><span>{{ $rl }}<small>{{ $rd }}</small></span></label>
        @endforeach
        @unless ($customizeOn)<div class="db-note db-warn" style="margin-bottom:6px">Customizing is off for every shop right now, so nobody sees Customize. This setting is kept for when it's back on.</div>@endunless
        <button type="button" class="db-btn sm" wire:click="saveSettings">Save settings</button>
        <div class="db-note" style="margin-top:6px">These apply straight away, without publishing.</div>
      @endif

      <div class="de-g" style="padding-top:22px">Who gets it</div>
      @if ($tpl->is_default)
        <div class="db-note">This is the default, so every shop gets it. Choose another default on the Dashboards page to limit it.</div>
      @else
        <div class="db-note" style="margin-bottom:4px">{{ $tpl->state === 'live' ? 'Applies straight away.' : 'Takes effect when you publish.' }} A shop that stops getting it falls back to the default. Each person's own pick and choices are kept in case it comes back.</div>
        @foreach (['all' => ['Every shop', ''], 'match' => ['Shops that match', 'By plan, type of business or add-on'], 'chosen' => ['Only shops I choose', 'A beta: name the shops']] as $mv => [$ml, $md])
          <label class="de-opt"><input type="radio" value="{{ $mv }}" wire:model.live="audMode" @disabled(! $canEdit)><span>{{ $ml }}@if ($md)<small>{{ $md }}</small>@endif</span></label>
        @endforeach
        @if ($audMode === 'match')
          <div class="de-f" style="margin-top:6px"><label>Plan</label>
            <select class="db-in" wire:model.live="audPlan" @disabled(! $canEdit)>
              <option value="">Any plan</option>
              @foreach ($plans as $pk => $pl)<option value="{{ $pk }}">{{ $pl }} and up</option>@endforeach
            </select></div>
          <div class="db-note">Type of business <span style="opacity:.8">(none ticked = any)</span></div>
          @foreach ($industries as $ik => $il)
            <label class="de-chk"><input type="checkbox" value="{{ $ik }}" wire:model.live="audIndustries" @disabled(! $canEdit)> {{ $il }}</label>
          @endforeach
          <div class="db-note" style="margin-top:8px">Has every one of these add-ons</div>
          @foreach ($addons as $ak => $al)
            <label class="de-chk"><input type="checkbox" value="{{ $ak }}" wire:model.live="audAddons" @disabled(! $canEdit)> {{ $al }}</label>
          @endforeach
        @endif
        @foreach (($audMode === 'all' ? ['audExclude' => 'Never offer it to'] : ['audInclude' => $audMode === 'chosen' ? 'The shops' : 'Also offer it to', 'audExclude' => 'Never offer it to']) as $list => $title)
          <div class="db-note" style="margin-top:10px">{{ $title }}</div>
          <div class="de-chips">
            @forelse ($this->{$list} as $sid)
              <span class="de-chip">{{ $shopNames[$sid] ?? 'Removed shop' }}@if ($canEdit)<button type="button" wire:click="removeShop('{{ $list }}', '{{ $sid }}')" aria-label="Remove">×</button>@endif</span>
            @empty
              <span class="db-note">None</span>
            @endforelse
          </div>
        @endforeach
        @if ($canEdit)
          <input type="text" class="db-in" placeholder="Find a shop to add" wire:model.live.debounce.300ms="shopFind">
          @if ($shopHits)
            <div class="de-hits">
              @foreach ($shopHits as $sid => $sname)
                <button type="button" wire:click="addShop('{{ $audMode === 'all' ? 'audExclude' : 'audInclude' }}', '{{ $sid }}')">{{ $sname }} <span class="db-note">· {{ $audMode === 'all' ? 'never offer' : 'add' }}</span></button>
                @if ($audMode !== 'all')<button type="button" wire:click="addShop('audExclude', '{{ $sid }}')" style="padding-left:20px"><span class="db-note">or never offer it to {{ $sname }}</span></button>@endif
              @endforeach
            </div>
          @endif
          <div class="db-bar" style="padding:10px 0 0">
            <button type="button" class="db-btn sm p" wire:click="saveAudience" @disabled($aud === $audSaved)>Save who gets it</button>
            <span class="note">{{ \App\Support\Dashboard\Audience::describe($aud) }} · <b>{{ number_format($audCount) }}</b> {{ \Illuminate\Support\Str::plural('shop', $audCount) }}</span>
          </div>
        @endif
      @endif

      <div class="de-g" style="padding-top:22px">Versions</div>
      @forelse ($versions as $v)
        <div class="de-ver">
          <b style="color:var(--db-txt)">v{{ $v->version }}</b>
          <span class="db-note">{{ $v->published_at?->format('M j, g:ia') }}@if ($v->note) · {{ \Illuminate\Support\Str::limit($v->note, 60) }}@endif</span>
          <span class="sp">
            @if ($tpl->live_version_id === $v->id)<span class="db-tag live">Live</span>
            @elseif ($canEdit)<button type="button" class="db-lnk" style="font-size:12px" wire:click="openVersion({{ $v->id }})"
                data-confirm="Open v{{ $v->version }} as your draft?&#10;It replaces the draft you're editing. Shops keep seeing the live version until you publish.">Open as draft</button>@endif
          </span>
        </div>
      @empty
        <div class="db-note">Not published yet.</div>
      @endforelse
    </aside>

    <section class="de-canvas">
      @foreach ($rows as $r => $row)
        <div class="de-row" wire:key="row-{{ $r }}-{{ md5(json_encode($row)) }}">
          <div class="de-rowh">
            <b>Row {{ $r + 1 }}</b>
            @if ($canEdit)
              <select class="db-in" wire:change="setSplit({{ $r }}, $event.target.value)" aria-label="Columns in row {{ $r + 1 }}">
                @foreach ($splits as $si => $sp)
                  <option value="{{ $si }}" @selected($sp === $row['split'])>{{ \App\Support\Dashboard\Layout::splitName($sp) }}</option>
                @endforeach
              </select>
              <span class="sp de-ctl">
                <button type="button" title="Move row up" wire:click="moveRow({{ $r }}, -1)" @disabled($r === 0)>↑</button>
                <button type="button" title="Move row down" wire:click="moveRow({{ $r }}, 1)" @disabled($r === count($rows) - 1)>↓</button>
                <button type="button" title="Remove row" wire:click="removeRow({{ $r }})"
                  @if (collect($row['cols'])->flatten(1)->count()) data-confirm="Remove row {{ $r + 1 }}?&#10;Its blocks come off this template. They aren't deleted, and you can add them again." @endif>✕</button>
              </span>
            @else
              <span>{{ \App\Support\Dashboard\Layout::splitName($row['split']) }}</span>
            @endif
          </div>
          <div class="de-cols">
            @foreach ($row['split'] as $c => $span)
              @php $col = $row['cols'][$c] ?? []; @endphp
              <div class="de-col {{ $canEdit && $target === [$r, $c] ? 'target' : '' }}" style="grid-column:span {{ $span }}"
                   :class="{ 'over': over === '{{ $r }}-{{ $c }}' }"
                   @if ($canEdit)
                     wire:click.self="setTarget({{ $r }}, {{ $c }})"
                     x-on:dragover.prevent="over = '{{ $r }}-{{ $c }}'"
                     x-on:dragleave="if (! $el.contains($event.relatedTarget)) over = null"
                     x-on:drop.prevent="drop($event, {{ $r }}, {{ $c }})"
                   @endif>
                @if (count($row['split']) > 1)<div class="colh">Column {{ $c + 1 }} · {{ \App\Support\Dashboard\Layout::colWidth($row['split'], $c) }}</div>@endif
                @forelse ($col as $i => $p)
                  @php $def = $registry[$p['b']] ?? null; $isHidden = in_array($p['b'], $hidden, true); @endphp
                  <div class="de-blk {{ $isHidden ? 'hid' : '' }} {{ $sel === [$r, $c, $i] ? 'sel' : '' }}" @if ($canEdit) draggable="true" x-on:dragstart="drag($event, { r: {{ $r }}, c: {{ $c }}, i: {{ $i }} })" @endif>
                    <span class="de-grip">⋮⋮</span>
                    <button type="button" class="pick" wire:click="selectBlock({{ $r }}, {{ $c }}, {{ $i }})" title="Settings for this block">
                      <span class="t">{{ $names[$p['b']] ?? $p['b'] }}</span>
                      <span class="s" style="display:block">{{ $isHidden ? 'Hidden everywhere: no shop sees it' : (($def['origin'] ?? '') === 'custom' ? 'Made here' : 'From ' . ucfirst($def['origin'] ?? '')) . ' · ' . ($def['reads'] ?? '') }}</span>
                      @if (! empty($p['lock']))<span class="who">Always shown</span>@endif
                    </button>
                    @if ($canEdit)
                      <span class="de-ctl">
                        <button type="button" title="Move up" wire:click="nudge({{ $r }}, {{ $c }}, {{ $i }}, -1)" @disabled($i === 0)>↑</button>
                        <button type="button" title="Move down" wire:click="nudge({{ $r }}, {{ $c }}, {{ $i }}, 1)" @disabled($i === count($col) - 1)>↓</button>
                        @if ($c > 0)<button type="button" title="Move to the column on the left" wire:click="toColumn({{ $r }}, {{ $c }}, {{ $i }}, {{ $c - 1 }})">←</button>@endif
                        @if ($c < count($row['split']) - 1)<button type="button" title="Move to the column on the right" wire:click="toColumn({{ $r }}, {{ $c }}, {{ $i }}, {{ $c + 1 }})">→</button>@endif
                        <button type="button" title="Remove from this template" wire:click="removeBlock({{ $r }}, {{ $c }}, {{ $i }})">✕</button>
                      </span>
                    @endif
                  </div>
                @empty
                  <div class="empty">{{ $canEdit ? 'Drop a block here' : 'Empty' }}</div>
                @endforelse
              </div>
            @endforeach
          </div>
        </div>
      @endforeach

      @if ($canEdit)
        <div class="de-addrow">
          <span>Add a row:</span>
          @foreach ($splits as $si => $sp)
            <button type="button" class="db-btn sm" wire:click="addRow({{ $si }})">{!! $splitIcon($sp) !!}{{ \App\Support\Dashboard\Layout::splitName($sp) }}</button>
          @endforeach
        </div>
      @endif
    </section>
  </div>
</div>
</x-filament-panels::page>
