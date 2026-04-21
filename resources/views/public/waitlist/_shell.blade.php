@php
  $headingFont = $currentTenant->font_heading ?? 'Inter';
  $bodyFont    = $currentTenant->font_body    ?? 'Inter';
  $fontFamilies = array_unique([$headingFont, $bodyFont]);
  $fontQuery = implode('&family=', array_map(fn($f) => str_replace(' ', '+', $f) . ':wght@400;500;600;700', $fontFamilies));
  $accent = $currentTenant->accent_color ?? '#BEF264';
  $accentText = \App\Support\ColorHelper::accentTextColor($accent);
@endphp
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="csrf-token" content="{{ csrf_token() }}">
  <title>{{ $pageTitle ?? 'Waitlist' }} — {{ $currentTenant->name }}</title>
  @if($currentTenant->favicon_url)<link rel="icon" href="{{ $currentTenant->favicon_url }}">@endif
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link href="https://fonts.googleapis.com/css2?family={{ $fontQuery }}&display=swap" rel="stylesheet">
  <style>
    :root {
      --p-accent: {{ $accent }};
      --p-accent-text: {{ $accentText }};
      --p-text: {{ $currentTenant->text_color ?? '#111' }};
      --p-bg:   {{ $currentTenant->bg_color ?? '#fff' }};
      --p-muted: rgba(0,0,0,.55);
      --p-soft:  rgba(0,0,0,.06);
      --p-font-heading: '{{ $headingFont }}', -apple-system, sans-serif;
      --p-font-body: '{{ $bodyFont }}', -apple-system, sans-serif;
    }
    *,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
    body{font-family:var(--p-font-body);background:var(--p-bg);color:var(--p-text);font-size:16px;line-height:1.6;-webkit-font-smoothing:antialiased}
    a{color:inherit;text-decoration:none}
    button{font-family:inherit;cursor:pointer}
    .w-shell{max-width:620px;margin:0 auto;padding:clamp(32px,6vw,64px) clamp(20px,5vw,40px)}
    .w-header{text-align:center;margin-bottom:clamp(24px,5vw,40px)}
    .w-shop-name{font-size:13px;font-weight:500;color:var(--p-muted);text-transform:uppercase;letter-spacing:.08em;margin-bottom:8px}
    .w-title{font-family:var(--p-font-heading);font-size:clamp(26px,5vw,36px);font-weight:700;line-height:1.2;margin-bottom:8px}
    .w-subtitle{font-size:16px;color:var(--p-muted);line-height:1.6}
    .w-card{background:#fff;border:1px solid var(--p-soft);border-radius:12px;padding:clamp(24px,4vw,32px);box-shadow:0 1px 3px rgba(0,0,0,.04)}
    .w-label{display:block;font-size:13px;font-weight:500;color:var(--p-muted);margin-bottom:6px;text-transform:uppercase;letter-spacing:.04em}
    .w-input,.w-select,.w-textarea{width:100%;padding:12px 14px;border:1.5px solid var(--p-soft);border-radius:8px;font-family:inherit;font-size:15px;background:transparent;color:var(--p-text);transition:border-color .15s}
    .w-input:focus,.w-select:focus,.w-textarea:focus{outline:none;border-color:var(--p-accent)}
    .w-textarea{resize:vertical;min-height:80px;line-height:1.5}
    .w-row{margin-bottom:16px}
    .w-row-2{display:grid;grid-template-columns:1fr 1fr;gap:14px}
    @media (max-width:480px){.w-row-2{grid-template-columns:1fr}}
    .w-day-picker{display:flex;gap:6px;flex-wrap:wrap}
    .w-day-chip{display:inline-flex;align-items:center;justify-content:center;padding:8px 12px;border:1.5px solid var(--p-soft);border-radius:20px;font-size:13px;cursor:pointer;transition:all .15s;user-select:none;background:transparent;color:var(--p-text)}
    .w-day-chip input{position:absolute;opacity:0;pointer-events:none}
    .w-day-chip.is-checked{background:var(--p-accent);color:var(--p-accent-text);border-color:var(--p-accent)}
    .w-btn{display:inline-flex;align-items:center;justify-content:center;gap:8px;padding:14px 24px;border-radius:10px;font-size:15px;font-weight:600;border:2px solid transparent;transition:all .15s;white-space:nowrap;background:var(--p-accent);color:var(--p-accent-text);border-color:var(--p-accent)}
    .w-btn:hover{filter:brightness(.93)}
    .w-btn--outline{background:transparent;color:currentColor;border-color:currentColor;opacity:.7}
    .w-btn--outline:hover{opacity:1}
    .w-btn--full{width:100%}
    .w-btn--sm{padding:8px 14px;font-size:13px;border-radius:8px}
    .w-btn--danger{background:transparent;color:#A32D2D;border-color:#F7C1C1}
    .w-btn--danger:hover{background:#FCEBEB}
    .w-flash{padding:14px 18px;border-radius:8px;font-size:14px;margin-bottom:20px;line-height:1.5}
    .w-flash--success{background:#EAF3DE;color:#3B6D11}
    .w-flash--error{background:#FCEBEB;color:#A32D2D}
    .w-footer-note{text-align:center;font-size:13px;color:var(--p-muted);margin-top:32px;line-height:1.6}
    .w-footer-note a{color:var(--p-accent);font-weight:500;border-bottom:1px solid currentColor}
    .w-entry-row{display:flex;justify-content:space-between;align-items:flex-start;gap:16px;padding:18px 0;border-bottom:1px solid var(--p-soft)}
    .w-entry-row:last-child{border-bottom:none}
    .w-entry-name{font-size:17px;font-weight:600;line-height:1.3;margin-bottom:4px}
    .w-entry-meta{font-size:13.5px;color:var(--p-muted);line-height:1.5}
    .w-entry-status{display:inline-block;font-size:11px;text-transform:uppercase;letter-spacing:.06em;font-weight:600;padding:3px 9px;border-radius:4px;margin-left:8px;background:#f0f0f0;color:var(--p-muted)}
    .w-entry-status.is-fulfilled{background:#EAF3DE;color:#3B6D11}
    .w-offer-slot{text-align:center;padding:24px;background:var(--p-soft);border-radius:10px;margin:16px 0}
    .w-offer-slot-label{font-size:13px;color:var(--p-muted);text-transform:uppercase;letter-spacing:.06em}
    .w-offer-slot-time{font-size:22px;font-weight:700;margin-top:4px;font-family:var(--p-font-heading)}
    .w-offer-service{font-size:17px;color:var(--p-text);margin-top:4px}
  </style>
</head>
<body>
<div class="w-shell">
  <div class="w-header">
    <div class="w-shop-name">{{ $currentTenant->name }}</div>
    <h1 class="w-title">{{ $headerTitle ?? ($pageTitle ?? 'Waitlist') }}</h1>
    @if(!empty($headerSubtitle))<div class="w-subtitle">{{ $headerSubtitle }}</div>@endif
  </div>

  @if(session('success'))<div class="w-flash w-flash--success">{{ session('success') }}</div>@endif
  @if($errors->any())
    <div class="w-flash w-flash--error">
      @foreach($errors->all() as $err)<div>{{ $err }}</div>@endforeach
    </div>
  @endif

  {!! $slot ?? '' !!}
</div>

<script>
document.querySelectorAll('.w-day-chip input[type=checkbox]').forEach(function (cb) {
  var update = function () { cb.closest('.w-day-chip').classList.toggle('is-checked', cb.checked); };
  cb.addEventListener('change', update);
  update();
});
</script>
</body>
</html>
