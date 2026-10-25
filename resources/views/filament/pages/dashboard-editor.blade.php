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
              It joins every shop's dashboard switch. Nobody's screen changes until they pick it.
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
    On phones every row becomes one column, left to right.
  </div>

  <div class="de-grid">
    <aside class="de-panel">
      @if ($canEdit)
        <div class="db-note" style="margin-bottom:4px">Drag a block into a column, or click <b>Add</b> to put it in the outlined column.</div>
        @foreach ($catalog as $origin => $items)
          <div class="de-g">From {{ $origin }}</div>
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
        <label class="db-note" style="display:flex;gap:8px;align-items:center;margin-bottom:10px"><input type="checkbox" wire:model="beta" style="accent-color:#8b5cf6"> Show a BETA label in the switch</label>
        <button type="button" class="db-btn sm" wire:click="saveSettings">Save settings</button>
        <div class="db-note" style="margin-top:6px">These apply straight away, without publishing.</div>
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
                @if (count($row['split']) > 1)<div class="colh">Column {{ $c + 1 }} · {{ $span }}/12</div>@endif
                @forelse ($col as $i => $p)
                  @php $def = $registry[$p['b']] ?? null; $isHidden = in_array($p['b'], $hidden, true); @endphp
                  <div class="de-blk {{ $isHidden ? 'hid' : '' }}" @if ($canEdit) draggable="true" x-on:dragstart="drag($event, { r: {{ $r }}, c: {{ $c }}, i: {{ $i }} })" @endif>
                    <span class="de-grip">⋮⋮</span>
                    <span>
                      <span class="t">{{ $names[$p['b']] ?? $p['b'] }}</span>
                      <span class="s" style="display:block">{{ $isHidden ? 'Hidden everywhere: no shop sees it' : 'From ' . ucfirst($def['origin'] ?? '') . ' · ' . ($def['reads'] ?? '') }}</span>
                    </span>
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
