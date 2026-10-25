<x-filament-panels::page>
@include('filament.partials.dashboards-style')

<div class="sx-root">
  <div class="db-legend">
    <b>Shops only see a template's live version.</b> Edits stay in a draft no shop can see until you publish it.
    Every live template appears in every shop's dashboard switch.
    <b>The default</b> is what anyone who hasn't picked a view sees: {{ number_format($following) }} {{ \Illuminate\Support\Str::plural('person', $following) }}
    across {{ number_format($followingShops) }} {{ \Illuminate\Support\Str::plural('shop', $followingShops) }} right now, including anyone whose pick was retired.
    People who chose a view keep it.
  </div>

  <div class="db-bar">
    <span class="note">{{ $active->where('state', 'live')->count() }} live · {{ $active->where('state', 'draft')->count() }} draft only · {{ $active->where('pending', '>', 0)->count() }} with a draft waiting</span>
    @if ($canManage)
      <span style="margin-left:auto"></span>
      <x-filament::modal id="new-dashboard" width="md">
        <x-slot name="trigger"><button type="button" class="db-btn p">New template</button></x-slot>
        <x-slot name="heading">New template</x-slot>
        <x-slot name="description">It starts as a draft. No shop sees it until you publish it.</x-slot>
        <div class="sx-root" style="display:grid;gap:12px">
          <label class="db-note">Name
            <input type="text" class="db-in" style="margin-top:4px" wire:model="newName" maxlength="80" placeholder="e.g. Front desk">
          </label>
          <label class="db-note">Start from
            <select class="db-in" style="margin-top:4px" wire:model="newFrom">
              <option value="">A blank page</option>
              @foreach ($active as $t)
                <option value="{{ $t['key'] }}">A copy of {{ $t['name'] }}</option>
              @endforeach
            </select>
          </label>
        </div>
        <x-slot name="footerActions">
          <x-filament::button wire:click="create">Create draft</x-filament::button>
          <x-filament::button color="gray" x-on:click="$dispatch('close-modal', { id: 'new-dashboard' })">Cancel</x-filament::button>
        </x-slot>
      </x-filament::modal>
    @endif
  </div>

  <table class="db-t">
    <thead>
      <tr><th>Template</th><th>Status</th><th class="db-hide-m">In the switch</th><th class="db-hide-m">Chose it</th><th>Default</th><th></th></tr>
    </thead>
    <tbody>
      @foreach ($active as $t)
        <tr class="{{ $default === $t['key'] ? 'on' : '' }}">
          <td>
            <a class="db-nm" href="{{ \App\Filament\Pages\DashboardEditor::getUrl(['t' => $t['key']]) }}" style="text-decoration:none">{{ $t['name'] }}</a>
            <div class="db-sub">{{ $t['builtin'] ? 'Built-in' : 'Made here' }} · {{ $t['blocks'] }} {{ \Illuminate\Support\Str::plural('block', $t['blocks']) }}</div>
          </td>
          <td>
            @if ($t['state'] === 'live')<span class="db-tag live">Live v{{ $t['version'] }}</span>@else<span class="db-tag draft">Draft only</span>@endif
            @if ($t['pending'])<span class="db-tag pend">Draft waiting · {{ $t['pending'] }} {{ \Illuminate\Support\Str::plural('change', $t['pending']) }}</span>@endif
          </td>
          <td class="db-hide-m">{{ $t['label'] }}@if ($t['beta']) <span class="db-beta">BETA</span>@endif</td>
          <td class="db-hide-m db-num">{{ number_format($t['picked']) }}</td>
          <td>
            @if ($t['state'] === 'live')
              <label><input type="radio" name="dash-default" value="{{ $t['key'] }}" wire:model.live="default" @disabled(! $canManage)>
                @if ($saved === $t['key'])<span class="db-tag live">Default now</span>@endif</label>
            @else
              <span class="db-sub">Publish first</span>
            @endif
          </td>
          <td class="r">
            <span class="db-acts">
              <a class="db-lnk" href="{{ \App\Filament\Pages\DashboardEditor::getUrl(['t' => $t['key']]) }}">{{ $canManage ? 'Edit' : 'View' }}</a>
              @if ($canManage)
                <button type="button" class="db-lnk dim" wire:click="duplicate('{{ $t['key'] }}')">Duplicate</button>
                @if ($saved !== $t['key'])
                  <button type="button" class="db-lnk red" wire:click="retire('{{ $t['key'] }}')"
                    data-confirm="Retire {{ $t['name'] }}?&#10;It leaves every dashboard switch. {{ $t['picked'] }} {{ \Illuminate\Support\Str::plural('person', $t['picked']) }} who picked it will see the default on their next load. Its versions are kept, and you can restore it.">Retire</button>
                @endif
              @endif
            </span>
          </td>
        </tr>
      @endforeach
    </tbody>
  </table>

  <div class="db-bar">
    @if ($canManage)
      @php $chosen = $active->firstWhere('key', $default); @endphp
      <button type="button" class="db-btn p" wire:click="save" @disabled($default === $saved)
        data-confirm="Make {{ $chosen['name'] ?? $default }} the default?&#10;{{ number_format($following) }} {{ \Illuminate\Support\Str::plural('person', $following) }} who haven't picked a view will see it on their next page load.">Save default</button>
      <button type="button" class="db-btn" wire:click="resetChoice" @disabled($default === $saved)>Cancel</button>
      @if ($default !== $saved)
        <span class="note db-warn">Not saved yet. {{ number_format($following) }} {{ \Illuminate\Support\Str::plural('person', $following) }} would switch to {{ $chosen['name'] ?? $default }}.</span>
      @else
        <span class="note"><b>{{ $active->firstWhere('key', $saved)['name'] ?? '' }}</b> is the default.</span>
      @endif
    @else
      <span class="note">You have view access. Only admins with full Tenants access can change dashboards.</span>
    @endif
  </div>

  @if ($retired->isNotEmpty())
    <div class="db-h3">Retired<span class="meta">Not offered to any shop. Versions are kept.</span></div>
    <table class="db-t">
      <tbody>
        @foreach ($retired as $t)
          <tr class="dim">
            <td><span class="db-nm">{{ $t['name'] }}</span><div class="db-sub">Retired {{ $t['retired']?->format('M j') }} · {{ $t['blocks'] }} {{ \Illuminate\Support\Str::plural('block', $t['blocks']) }}</div></td>
            <td class="r">@if ($canManage)<button type="button" class="db-lnk" wire:click="restore('{{ $t['key'] }}')">Restore as draft</button>@endif</td>
          </tr>
        @endforeach
      </tbody>
    </table>
  @endif
</div>
</x-filament-panels::page>
