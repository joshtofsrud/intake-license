<x-filament-panels::page>
{{-- MARKER-DASH-DEFAULT: same line-and-row vocabulary as the Sales pages (.sx-root),
     so fixes.css's light-mode token overrides apply here too. --}}
<style>
  .sx-root { --sx-line:rgba(255,255,255,.075); --sx-line-2:rgba(255,255,255,.14); --sx-dim:#a3a3ab; --sx-faint:#74747d;
    --sx-violet:#8b5cf6; --sx-vsoft:rgba(139,92,246,.17); --sx-lime:#BEF264; --db-txt:#fff; font-size:14px; }
  html:not(.dark) .sx-root { --db-txt:#18181b; }
  .db-legend { color:var(--sx-faint); font-size:12.5px; line-height:1.6; padding:0 0 12px; border-bottom:1px solid var(--sx-line); }
  .db-legend b { color:var(--sx-dim); font-weight:500; }
  .db-t { width:100%; border-collapse:collapse; font-size:13.5px; }
  .db-t th { text-align:left; font-weight:500; color:var(--sx-faint); font-size:12.5px; padding:10px; border-bottom:1px solid var(--sx-line-2); white-space:nowrap; }
  .db-t td { padding:12px 10px; border-bottom:1px solid var(--sx-line); vertical-align:middle; }
  .db-t tr.on td { background:var(--sx-vsoft); }
  .db-t label { display:flex; align-items:center; gap:10px; cursor:pointer; }
  .db-t input[type=radio] { accent-color:#8b5cf6 !important; color:#8b5cf6 !important; width:15px; height:15px; }
  .db-nm { font-weight:600; color:var(--db-txt); }
  .db-sub { font-size:12px; color:var(--sx-faint); margin-top:2px; }
  .db-tag { display:inline-block; border-radius:4px; padding:1px 7px; font-size:11px; font-weight:600; background:rgba(190,242,100,.14); color:var(--sx-lime); margin-left:6px; }
  .db-beta { font-size:9.5px; font-weight:700; letter-spacing:.06em; opacity:.7; }
  .db-num { font-variant-numeric:tabular-nums; }
  .db-bar { display:flex; align-items:center; gap:10px; flex-wrap:wrap; padding:14px 0; }
  .db-bar .note { font-size:12.5px; color:var(--sx-faint); }
  .db-bar .note b { color:var(--db-txt); font-weight:600; }
  .db-btn { border:1px solid var(--sx-line-2); background:none; border-radius:7px; padding:6px 14px; font-weight:500; font-size:13px; cursor:pointer; color:inherit; }
  .db-btn.p { background:var(--sx-violet); border-color:var(--sx-violet); color:#fff; }
  .db-btn[disabled] { opacity:.45; cursor:default; }
  .db-warn { color:#fbbf24; }
  html:not(.dark) .db-warn { color:#b45309; }
  @media (max-width:860px) { .db-hide-m { display:none; } .db-t td, .db-t th { padding-left:6px; padding-right:6px; } }
</style>

<div class="sx-root">
  <div class="db-legend">
    <b>The default is the dashboard anyone who hasn't picked a view sees.</b>
    That's {{ number_format($counts['following']) }} {{ \Illuminate\Support\Str::plural('person', $counts['following']) }}
    across {{ number_format($counts['followingShops']) }} {{ \Illuminate\Support\Str::plural('shop', $counts['followingShops']) }} right now,
    including anyone whose pick is no longer offered. People who chose a view in their dashboard switch keep it, whatever the default is.
    Changing it takes effect on their next page load; nothing else about their dashboards changes.
  </div>

  <table class="db-t">
    <thead>
      <tr><th>Default</th><th class="db-hide-m">In the dashboard switch</th><th>Chose it themselves</th></tr>
    </thead>
    <tbody>
      @foreach ($templates as $t)
        <tr class="{{ $default === $t['key'] ? 'on' : '' }}">
          <td>
            <label>
              <input type="radio" name="dash-default" value="{{ $t['key'] }}" wire:model.live="default" @disabled(! $canManage)>
              <span>
                <span class="db-nm">{{ $t['name'] }}</span>
                @if ($saved === $t['key'])<span class="db-tag">Default now</span>@endif
                <span class="db-sub" style="display:block">{{ count(\App\Support\Dashboard\Layout::blockKeys($t['rows'])) }} blocks</span>
              </span>
            </label>
          </td>
          <td class="db-hide-m">{{ $t['label'] }}@if ($t['beta']) <span class="db-beta">BETA</span>@endif</td>
          <td class="db-num">{{ number_format($counts['picked'][$t['key']] ?? 0) }} {{ \Illuminate\Support\Str::plural('person', $counts['picked'][$t['key']] ?? 0) }}</td>
        </tr>
      @endforeach
    </tbody>
  </table>

  <div class="db-bar">
    @if ($canManage)
      @php $chosen = collect($templates)->firstWhere('key', $default); @endphp
      <button type="button" class="db-btn p" wire:click="save" @disabled($default === $saved)
        data-confirm="Make {{ $chosen['name'] ?? $default }} the default?&#10;{{ number_format($counts['following']) }} {{ \Illuminate\Support\Str::plural('person', $counts['following']) }} who haven't picked a view will see it on their next page load.">Save</button>
      <button type="button" class="db-btn" wire:click="resetChoice" @disabled($default === $saved)>Cancel</button>
      @if ($default !== $saved)
        <span class="note db-warn">Not saved yet. {{ number_format($counts['following']) }} {{ \Illuminate\Support\Str::plural('person', $counts['following']) }} would switch to {{ $chosen['name'] ?? $default }}.</span>
      @else
        <span class="note"><b>{{ collect($templates)->firstWhere('key', $saved)['name'] ?? '' }}</b> is the default.</span>
      @endif
    @else
      <span class="note">You have view access. Only admins with full Tenants access can change the default.</span>
    @endif
  </div>
</div>
</x-filament-panels::page>
