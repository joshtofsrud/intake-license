{{-- MARKER-DASH-TEMPLATES: the dashboard switch, one button per template the
     person can use. $inline = true draws Overview's inline-styled version
     (that page doesn't load dashboard-simple.css). --}}
@php
  $inline   = $inline ?? false;
  $segStyle = $segStyle ?? null;

@endphp
<div class="ia-viewseg"@if ($inline) style="display:inline-flex;background:var(--ia-surface);border:1px solid var(--ia-border);border-radius:9px;padding:3px"@elseif ($segStyle) style="{{ $segStyle }}"@endif>
  @foreach ($switchTemplates as $sw)
    @php
      $swTitle = $sw['label'] . ($sw['beta'] ? ' (beta)' : '');
      $swIcon  = \App\Support\Dashboard\DashboardTemplates::ICONS[$sw['icon']] ?? \App\Support\Dashboard\DashboardTemplates::ICONS['overview'];
      $swLabel = e($sw['label']) . ($sw['beta'] ? ' <span style="font-size:9.5px;font-weight:700;letter-spacing:.06em;opacity:.7">BETA</span>' : '');
    @endphp
    @if ($sw['key'] === $activeTemplate['key'])
      <button type="button" class="on" title="{{ $swTitle }}" aria-label="{{ $swTitle }}"@if ($inline) style="padding:6px 13px;font-size:12px;font-weight:600;border-radius:6px;background:var(--ia-surface-2);color:var(--ia-text);border:none;cursor:pointer;font-family:inherit"@endif>{!! $swIcon !!}<span class="vs-lb">{!! $swLabel !!}</span></button>
    @else
      <form method="POST" action="{{ route('tenant.dashboard.view') }}"@if ($inline) style="margin:0;display:inline"@endif>
        @csrf<input type="hidden" name="view" value="{{ $sw['key'] }}">
        <button type="submit" title="{{ $swTitle }}" aria-label="{{ $swTitle }}"@if ($inline) style="padding:6px 13px;font-size:12px;font-weight:600;border-radius:6px;background:none;color:var(--ia-text-dim);border:none;cursor:pointer;font-family:inherit"@endif>{!! $swIcon !!}<span class="vs-lb">{!! $swLabel !!}</span></button>
      </form>
    @endif
  @endforeach
</div>
