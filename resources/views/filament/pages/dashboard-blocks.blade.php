<x-filament-panels::page>
@include('filament.partials.dashboards-style')

<div class="sx-root">
  @include('filament.partials.dashboards-tabs', ['tab' => 'blocks'])

  <div class="db-legend">
    <b>Built-in blocks</b> come from code, so what they show can't be changed here; you can hide one everywhere.
    <b>Made-here blocks</b> are built on this page from a shop's own data, a button, or a note.
    Every block reads only the shop it's on, and each person only sees blocks whose data their role can open.
    <b>Hide everywhere</b> takes a block off every dashboard straight away. Nothing is deleted, and Unhide puts it back where it was.
    Saving a change to a made-here block shows on every dashboard that has it on its next load, without republishing.
  </div>

  <div class="db-bar">
    <span style="display:inline-flex;gap:6px">
      @foreach (['all' => 'All', 'builtin' => 'Built-in', 'custom' => 'Made here', 'hidden' => 'Hidden'] as $fk => $fl)
        <button type="button" class="db-btn sm" style="{{ $filter === $fk ? 'background:var(--sx-vsoft);border-color:var(--sx-violet)' : '' }}" wire:click="$set('filter', '{{ $fk }}')">{{ $fl }}</button>
      @endforeach
    </span>
    <input type="search" class="db-in" style="width:200px" placeholder="Find a block" wire:model.live.debounce.300ms="find">
    <span class="note">{{ $counts['builtin'] }} built-in · {{ $counts['custom'] }} made here · {{ $counts['hidden'] }} hidden</span>
    @if ($canManage)
      <span style="margin-left:auto"></span>
      <a class="db-btn p" href="{{ \App\Filament\Pages\DashboardBlockBuilder::getUrl() }}">New block</a>
    @endif
  </div>

  <table class="db-t">
    <thead><tr><th>Block</th><th class="db-hide-m">Kind</th><th class="db-hide-m">Seen by people who can open</th><th class="db-hide-m">On templates</th><th></th></tr></thead>
    <tbody>
      @forelse ($list as $b)
        <tr class="{{ $b['hidden'] ? 'dim' : '' }}">
          <td>
            @if (! $b['builtin'])
              <a class="db-nm" style="text-decoration:none" href="{{ \App\Filament\Pages\DashboardBlockBuilder::getUrl(['k' => $b['key']]) }}">{{ $b['name'] }}</a>
            @else
              <span class="db-nm">{{ $b['name'] }}</span>
            @endif
            <div class="db-sub">{{ $b['desc'] }}</div>
          </td>
          <td class="db-hide-m">
            @if ($b['builtin'])<span class="db-tag draft">Built-in</span>@else<span class="db-tag live">Made here</span>@endif
            <div class="db-sub">{{ $b['reads'] }}</div>
          </td>
          <td class="db-hide-m">{{ $b['who'] ?? 'Everyone' }}</td>
          <td class="db-hide-m">
            @forelse ($b['used'] as $u)
              {{ $u['name'] }}@if (! $u['live'])<span class="db-sub" style="display:inline"> (draft)</span>@endif{{ ! $loop->last ? ',' : '' }}
            @empty
              <span class="db-sub" style="display:inline">Not on any template</span>
            @endforelse
            @if ($b['hidden'])<div><span class="db-tag off">Hidden everywhere</span></div>@endif
          </td>
          <td class="r">
            @if ($canManage)
              <span class="db-acts">
                @if (! $b['builtin'])
                  <a class="db-lnk" href="{{ \App\Filament\Pages\DashboardBlockBuilder::getUrl(['k' => $b['key']]) }}">Edit</a>
                  <button type="button" class="db-lnk dim" wire:click="duplicate('{{ $b['key'] }}')">Copy</button>
                @endif
                @if ($b['hidden'])
                  <button type="button" class="db-lnk" wire:click="unhide('{{ $b['key'] }}')">Unhide</button>
                @else
                  <button type="button" class="db-lnk dim" wire:click="hide('{{ $b['key'] }}')"
                    data-confirm="Hide {{ $b['name'] }} everywhere?&#10;It leaves every dashboard straight away{{ $b['used'] ? ' (' . collect($b['used'])->pluck('name')->implode(', ') . ')' : '' }}. Nothing is deleted, and Unhide puts it back where it was.">Hide everywhere</button>
                @endif
                @if (! $b['builtin'])
                  <button type="button" class="db-lnk red" wire:click="delete('{{ $b['key'] }}')"
                    data-confirm="Delete {{ $b['name'] }}?&#10;{{ $b['used'] ? 'It comes off ' . collect($b['used'])->pluck('name')->implode(', ') . ' straight away. ' : '' }}This can't be undone. Hide everywhere keeps it instead.">Delete</button>
                @endif
              </span>
            @endif
          </td>
        </tr>
      @empty
        <tr><td colspan="5" class="db-note" style="padding:18px 10px">No blocks match.</td></tr>
      @endforelse
    </tbody>
  </table>
</div>
</x-filament-panels::page>
