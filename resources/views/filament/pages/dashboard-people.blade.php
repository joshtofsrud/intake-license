<x-filament-panels::page>
@include('filament.partials.dashboards-style')
{{-- MARKER-DASH-PEOPLE --}}
<style>
  .pp-sh td { background:var(--db-card); border-bottom:1px solid var(--sx-line-2); padding-top:10px; padding-bottom:10px; }
  .pp-sh label { display:flex; align-items:center; gap:10px; flex-wrap:wrap; }
  .pp-sh .db-sub { margin:0; }
  .pp-t input[type=checkbox] { accent-color:#8b5cf6 !important; width:15px; height:15px; flex:none; }
  .pp-t td.pp-c { width:34px; padding-right:0; }
  .pp-t td { padding-top:9px; padding-bottom:9px; }
  .pp-why { font-size:12px; color:var(--db-amber); margin-top:2px; }
  .pp-bar { position:sticky; bottom:14px; z-index:20; display:flex; gap:10px; align-items:center; flex-wrap:wrap; margin-top:14px; padding:10px 14px;
    border:1px solid var(--sx-line-2); border-radius:9px; background:var(--db-card); box-shadow:0 8px 28px rgba(0,0,0,.35); }
  html:not(.dark) .pp-bar { box-shadow:0 8px 28px rgba(0,0,0,.12); }
  .pp-prev { display:grid; gap:8px; font-size:13.5px; line-height:1.55; }
  .pp-prev .no { color:var(--db-amber); }
  .pp-prev ul { margin:4px 0 0; padding-left:18px; color:var(--sx-dim); font-size:12.5px; }
  .pp-filters { display:flex; gap:10px; flex-wrap:wrap; align-items:center; padding:14px 0 10px; }
  .pp-filters .db-in { width:auto; min-width:170px; }
  .pp-filters .db-in.find { min-width:260px; }
</style>

<div class="sx-root">
  @include('filament.partials.dashboards-tabs', ['tab' => 'people'])

  @php $defName = $templates[$default]->name ?? $default; @endphp
  <div class="db-legend">
    <b>What each person sees.</b> The dashboard they picked, if it's live and their shop is offered it; otherwise the default ({{ $defName }}).
    <b>Follows the default</b> means they haven't picked, so they change when the default changes.
    <b>Fell back</b> means they picked one they can't have now (retired, not published, or their shop isn't offered it), so they see the default.
    <b>Moving</b> someone sets their pick: they stop following the default. Moving them to the default clears their pick instead.
    A move never changes which shops are offered a dashboard. Deactivated people aren't listed.
    @if (! $canManage) <b>You have view access.</b> Only admins with full Tenants access can move people. @endif
  </div>

  <div class="db-h3">By dashboard<span class="meta">{{ number_format($total) }} {{ \Illuminate\Support\Str::plural('person', $total) }} in {{ number_format($totalShops) }} {{ \Illuminate\Support\Str::plural('shop', $totalShops) }}</span></div>
  <div class="db-scroll"><table class="db-t">
    <thead>
      <tr><th>Dashboard</th><th>Status</th><th>Seeing it now</th><th class="db-hide-m">Chose it</th><th class="db-hide-m">Fell back</th><th></th></tr>
    </thead>
    <tbody>
      @foreach ($templates as $t)
        @php $s = $summary[$t->key] ?? null; @endphp
        @continue(! $s || ($t->state !== 'live' && $t->state !== 'beta' && ! $s['seeing'] && ! $s['picked'] && ! $s['fell']))
        <tr class="{{ $t->state === 'retired' && ! $s['fell'] ? 'dim' : '' }}">
          <td>
            <span class="db-nm">{{ $t->name }}</span>
            <div class="db-sub">{{ $t->is_builtin ? 'Built-in' : 'Made here' }}@if ($t->key === $default) · the default @endif</div>
          </td>
          <td>
            @if ($t->state === 'retired')<span class="db-tag off">Retired</span>
            @elseif ($t->live_version_id && in_array($t->state, ['live', 'beta'], true))<span class="db-tag live">Live</span>
            @else<span class="db-tag draft">Draft only</span>@endif
          </td>
          <td class="db-num">
            {{ number_format($s['seeing']) }} {{ \Illuminate\Support\Str::plural('person', $s['seeing']) }}
            <div class="db-sub">{{ number_format($s['shops']) }} {{ \Illuminate\Support\Str::plural('shop', $s['shops']) }}@if ($t->key === $default && $s['following']) · {{ number_format($s['following']) }} follow the default @endif</div>
          </td>
          <td class="db-hide-m db-num">{{ number_format($s['picked']) }}</td>
          <td class="db-hide-m db-num">@if ($s['fell'])<span class="db-warn">{{ number_format($s['fell']) }}</span>@else 0 @endif</td>
          <td class="r">
            <span class="db-acts">
              @if ($s['seeing'] || $s['picked'] || $s['fell'])
                <button type="button" class="db-lnk" wire:click="showTemplate('{{ $t->key }}')">View people</button>
              @endif
              @if ($canManage && ($s['picked'] + $s['fell']) > 0)
                <button type="button" class="db-lnk" wire:click="startMove('{{ $t->key }}')">Move people…</button>
              @endif
            </span>
          </td>
        </tr>
      @endforeach
      @if (($summary['_gone']['fell'] ?? 0) > 0)
        <tr class="dim"><td><span class="db-nm">A dashboard that no longer exists</span></td><td></td><td></td><td class="db-hide-m"></td><td class="db-hide-m db-num"><span class="db-warn">{{ number_format($summary['_gone']['fell']) }}</span></td><td></td></tr>
      @endif
    </tbody>
  </table></div>

  <div class="db-h3">People<span class="meta">{{ number_format($matchCount) }} {{ \Illuminate\Support\Str::plural('person', $matchCount) }} in {{ number_format($shopCount) }} {{ \Illuminate\Support\Str::plural('shop', $shopCount) }}</span></div>
  <div class="pp-filters">
    <input type="text" class="db-in find" placeholder="Find a shop or person" wire:model.live.debounce.300ms="find">
    <select class="db-in" wire:model.live="tpl">
      <option value="">Every dashboard</option>
      @foreach ($templates as $t)
        <option value="{{ $t->key }}">{{ $t->name }}{{ $t->state === 'retired' ? ' (retired)' : '' }}</option>
      @endforeach
    </select>
    <select class="db-in" wire:model.live="show">
      <option value="all">Everyone</option>
      <option value="picked">Picked their own</option>
      <option value="default">Follows the default</option>
      <option value="fell">Fell back to the default</option>
    </select>
    @if ($find !== '' || $tpl !== '' || $show !== 'all')
      <button type="button" class="db-btn sm" wire:click="clearFilters">Clear</button>
    @endif
    @if ($canManage && $matchCount)
      <button type="button" class="db-btn sm" wire:click="selectMatching">Tick all {{ number_format($matchCount) }}</button>
    @endif
  </div>

  @if (! $total)
    <div class="db-note" style="padding:18px 0">No active people yet.</div>
  @elseif (! $matchCount)
    <div class="db-note" style="padding:18px 0">No one matches that. <button type="button" class="db-lnk" wire:click="clearFilters">Clear the filters</button></div>
  @else
    <div class="db-scroll"><table class="db-t pp-t">
      <thead><tr>@if ($canManage)<th></th>@endif<th>Person</th><th>Chose</th><th>Sees now</th></tr></thead>
      <tbody>
        @foreach ($shops as $tid => $shop)
          @php $ids = array_column($shop['people'], 'id'); $allOn = $ids && ! array_diff($ids, $sel); @endphp
          <tr class="pp-sh">
            <td colspan="{{ $canManage ? 4 : 3 }}">
              <label>
                @if ($canManage)<input type="checkbox" wire:key="shop-{{ $tid }}-{{ $allOn ? 'on' : 'off' }}" @checked($allOn) wire:click="toggleShop('{{ $tid }}')" aria-label="Tick everyone in {{ $shop['shop'] }}">@endif
                <span class="db-nm">{{ $shop['shop'] }}</span>
                <span class="db-sub">{{ $shop['plan'] }} · {{ count($shop['people']) }} {{ \Illuminate\Support\Str::plural('person', count($shop['people'])) }}</span>
                <a class="db-lnk dim" style="font-size:12px" href="{{ \App\Filament\Pages\Dashboards::getUrl(['shop' => $tid]) }}">What this shop is offered →</a>
              </label>
            </td>
          </tr>
          @foreach ($shop['people'] as $p)
            <tr>
              @if ($canManage)<td class="pp-c"><input type="checkbox" value="{{ $p['id'] }}" wire:model.live="sel" aria-label="Tick {{ $p['name'] }}"></td>@endif
              <td><span class="db-nm" style="font-weight:500">{{ $p['name'] }}</span><div class="db-sub">{{ $p['role'] }}@if ($p['email']) · {{ $p['email'] }}@endif</div></td>
              <td>
                @if ($p['status'] === 'default')<span class="db-tag draft">Follows the default</span>
                @elseif ($p['status'] === 'picked')<span class="db-tag live">{{ $templates[$p['pick']]->name ?? $p['pick'] }}</span>
                @else<span class="db-tag pend">{{ $templates[$p['pick']]->name ?? 'Gone' }}</span>@endif
                @if ($p['status'] === 'fell')<div class="pp-why">Fell back: {{ $p['why'] }}</div>@endif
              </td>
              <td>{{ $templates[$p['effective']]->name ?? $p['effective'] }}@if ($p['custom']) <span class="db-sub" style="display:inline">· customized</span>@endif</td>
            </tr>
          @endforeach
        @endforeach
      </tbody>
    </table></div>
    @if ($shopCount > count($shops))
      <div class="db-bar"><button type="button" class="db-btn" wire:click="more">Show more shops ({{ $shopCount - count($shops) }} left)</button></div>
    @endif
  @endif

  @if ($canManage && $selCount)
    <div class="pp-bar">
      <span class="db-note"><b>{{ number_format($selCount) }}</b> {{ \Illuminate\Support\Str::plural('person', $selCount) }} ticked in {{ number_format($selShops) }} {{ \Illuminate\Support\Str::plural('shop', $selShops) }}</span>
      <span style="margin-left:auto"></span>
      <button type="button" class="db-btn" wire:click="clearSel">Clear</button>
      <button type="button" class="db-btn p" wire:click="startMove">Move to another dashboard…</button>
    </div>
  @endif

  <div class="db-h3">Recent moves<span class="meta">Undo puts people back as they were, unless they've changed dashboard since</span></div>
  @if ($moves->isEmpty())
    <div class="db-note" style="padding:6px 0 12px">No moves yet. Moves you make here, or while retiring a dashboard, are listed so you can undo them.</div>
  @else
    <div class="db-scroll"><table class="db-t">
      <tbody>
        @foreach ($moves as $m)
          <tr class="{{ $m->undone_at ? 'dim' : '' }}">
            <td>
              <span class="db-nm" style="font-weight:500">{{ number_format($m->people) }} {{ \Illuminate\Support\Str::plural('person', $m->people) }} in {{ number_format($m->shops) }} {{ \Illuminate\Support\Str::plural('shop', $m->shops) }} → {{ $m->to_name }}</span>
              <div class="db-sub">From {{ $m->from_label }}@if ($m->reason === 'retire') · while retiring a dashboard @endif</div>
            </td>
            <td class="db-note db-hide-m">{{ $m->admin_name ?: 'Someone' }}<div class="db-sub">{{ $m->created_at->diffForHumans() }}</div></td>
            <td class="r">
              @if ($m->undone_at)<span class="db-tag off">Undone {{ $m->undone_at->diffForHumans() }}</span>
              @elseif ($canManage)<button type="button" class="db-btn sm" wire:click="undo({{ $m->id }})"
                  data-confirm="Undo this move?&#10;{{ $m->people }} {{ \Illuminate\Support\Str::plural('person', $m->people) }} go back to the dashboard they had before. Anyone who has changed dashboard since is left as they are.">Undo</button>@endif
            </td>
          </tr>
        @endforeach
      </tbody>
    </table></div>
  @endif

  @if ($canManage)
    <x-filament::modal id="move-people" width="lg">
      <x-slot name="heading">Move people to another dashboard</x-slot>
      <x-slot name="description">Nothing changes until you save.</x-slot>
      <div class="sx-root" style="display:grid;gap:14px">
        <div class="db-note"><b>{{ number_format($selCount) }}</b> {{ \Illuminate\Support\Str::plural('person', $selCount) }} in {{ number_format($selShops) }} {{ \Illuminate\Support\Str::plural('shop', $selShops) }}: {{ $selNames }}</div>
        <label class="db-note">Move them to
          <select class="db-in" style="margin-top:4px" wire:model.live="target">
            <option value="">Choose a dashboard</option>
            @foreach ($targets as $t)
              <option value="{{ $t->key }}">{{ $t->name }}{{ $t->key === $default ? ' (the default)' : '' }}</option>
            @endforeach
          </select>
        </label>
        @if ($preview)
          <div class="pp-prev">
            @if (count($preview['move']))
              <div><b>{{ number_format(count($preview['move'])) }}</b> {{ \Illuminate\Support\Str::plural('person', count($preview['move'])) }} will move to {{ $preview['target']->name }}.
                @if ($preview['toDefault']) Their pick is cleared, so they follow the default from now on.
                @else They stop following the default. @endif
                <ul>
                  @foreach ($preview['from'] as $fk => $n)<li>{{ $n }} from {{ $templates[$fk]->name ?? $fk }}</li>@endforeach
                </ul>
              </div>
            @else
              <div class="no">Nobody would move.</div>
            @endif
            @if ($preview['already'])<div>{{ number_format($preview['already']) }} already {{ $preview['already'] === 1 ? 'has' : 'have' }} it.</div>@endif
            @if ($preview['skip'])
              @php $skipN = array_sum(array_column($preview['skip'], 'n')); @endphp
              <div class="no">{{ number_format($skipN) }} can't have {{ $preview['target']->name }}, so they stay as they are:
                <ul>
                  @foreach (array_slice($preview['skip'], 0, 5) as $sk)<li>{{ $sk['shop'] }} ({{ $sk['n'] }}): {{ $sk['why'] }}</li>@endforeach
                  @if (count($preview['skip']) > 5)<li>and {{ count($preview['skip']) - 5 }} more {{ \Illuminate\Support\Str::plural('shop', count($preview['skip']) - 5) }}</li>@endif
                </ul>
                <a class="db-lnk" href="{{ \App\Filament\Pages\DashboardEditor::getUrl(['t' => $preview['target']->key]) }}">Change who gets {{ $preview['target']->name }} →</a>
              </div>
            @endif
          </div>
        @elseif ($target === '')
          <div class="db-note">Choose a dashboard to see who would move.</div>
        @endif
      </div>
      <x-slot name="footerActions">
        <x-filament::button wire:click="move" :disabled="! $preview || ! count($preview['move'])">Move {{ $preview ? number_format(count($preview['move'])) : '' }} {{ $preview ? \Illuminate\Support\Str::plural('person', count($preview['move'])) : 'people' }}</x-filament::button>
        <x-filament::button color="gray" x-on:click="$dispatch('close-modal', { id: 'move-people' })">Cancel</x-filament::button>
      </x-slot>
    </x-filament::modal>
  @endif
</div>
</x-filament-panels::page>
