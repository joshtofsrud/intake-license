<x-filament-panels::page>
@include('filament.partials.dashboards-style')
<style>
  .pv-bar { display:flex; gap:10px 14px; align-items:center; flex-wrap:wrap; padding:12px 0; border-bottom:1px solid var(--sx-line); }
  .pv-bar label { display:inline-flex; gap:8px; align-items:center; font-size:12.5px; color:var(--sx-faint); }
  .pv-bar select { width:auto; min-width:150px; }
  .pv-seg { display:inline-flex; border:1px solid var(--sx-line-2); border-radius:8px; overflow:hidden; }
  .pv-seg button { background:none; border:0; padding:5px 11px; font-size:12.5px; color:var(--sx-dim); cursor:pointer; }
  .pv-seg button + button { border-left:1px solid var(--sx-line-2); }
  .pv-seg button.on { background:var(--sx-vsoft); color:var(--db-txt); font-weight:500; }
  .pv-seg button[disabled] { opacity:.4; cursor:default; }
  .pv-stage { margin-top:14px; display:flex; justify-content:center; background:rgba(127,127,127,.06); border:1px solid var(--sx-line); border-radius:12px; padding:14px; }
  .pv-stage iframe { border:0; border-radius:8px; background:#111; width:100%; height:calc(100vh - 290px); min-height:560px; }
  .pv-stage.phone iframe { width:390px; height:780px; border-radius:22px; box-shadow:0 0 0 8px rgba(127,127,127,.25); }
</style>

<div class="sx-root">
  <div class="db-note" style="margin-bottom:8px">
    <a class="db-lnk" href="{{ \App\Filament\Pages\Dashboards::getUrl() }}">Dashboards</a> /
    <a class="db-lnk" href="{{ $back }}">{{ $b ? $blockName : $tpl?->name }}</a> / Preview
  </div>

  <div class="db-legend">
    <b>This is the shop's real dashboard</b>, drawn with its own figures, as someone with the chosen role sees it: blocks and links their role can't open are left out, just as on the shop.
    Nothing here can be clicked, and nothing is changed at the shop. People's own hidden and reordered blocks aren't applied.
    @if ($b) <b>A single block</b> is shown at the width you pick, on an otherwise empty dashboard. @endif
  </div>

  <div class="pv-bar">
    <label>Shop
      <select class="db-in" wire:model.live="shop">
        @foreach ($shops as $sid => $sn)<option value="{{ $sid }}">{{ $sn }}</option>@endforeach
      </select>
    </label>
    <label>Seen as
      <select class="db-in" wire:model.live="role">
        <option value="owner">Owner</option>
        @foreach ($roles as $rid => $rn)<option value="{{ $rid }}">{{ $rn }}</option>@endforeach
      </select>
    </label>
    @if ($tpl)
      <span class="pv-seg">
        <button type="button" class="{{ $v === 'live' ? 'on' : '' }}" wire:click="$set('v', 'live')" @disabled(! $hasLive)>Live{{ $tpl->liveVersion ? ' v' . $tpl->liveVersion->version : '' }}</button>
        <button type="button" class="{{ $v === 'draft' ? 'on' : '' }}" wire:click="$set('v', 'draft')" @disabled(! $hasDraft && $hasLive)>Draft</button>
      </span>
    @else
      <span class="pv-seg">
        @foreach ([4 => '⅓ width', 6 => '½', 8 => '⅔', 12 => 'Full'] as $ww => $wl)
          <button type="button" class="{{ $w === $ww ? 'on' : '' }}" wire:click="$set('w', {{ $ww }})">{{ $wl }}</button>
        @endforeach
      </span>
    @endif
    <span class="pv-seg">
      <button type="button" class="{{ $device === 'desktop' ? 'on' : '' }}" wire:click="$set('device', 'desktop')">Desktop</button>
      <button type="button" class="{{ $device === 'phone' ? 'on' : '' }}" wire:click="$set('device', 'phone')">Phone</button>
    </span>
    <span style="margin-left:auto"></span>
    <a class="db-btn" href="{{ $back }}">Close</a>
  </div>

  @if ($gets && ! $gets[0])
    <div class="db-note db-warn" style="padding-top:10px">{{ $tenant->name }} isn't offered this template ({{ $gets[1] }}), so it's shown here only as a preview.</div>
  @endif
  @if ($v === 'draft' && $tpl && $hasLive)
    <div class="db-note" style="padding-top:10px">Showing the draft. Shops see the live version until you publish.</div>
  @endif

  <div class="pv-stage {{ $device === 'phone' ? 'phone' : '' }}">
    @if ($src)
      <iframe src="{{ $src }}" title="Dashboard preview" wire:key="pv-{{ md5($src . $device) }}"></iframe>
    @else
      <div class="db-note" style="padding:40px">Pick a shop to preview.</div>
    @endif
  </div>
</div>
</x-filament-panels::page>
