<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Setup — {{ $currentTenant->name }}</title>
  @if($currentTenant->favicon_url)<link rel="icon" href="{{ $currentTenant->favicon_url }}">@endif
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
  <style>
    *{box-sizing:border-box;margin:0;padding:0}
    body{font-family:'Inter',-apple-system,sans-serif;background:#0c0c0c;color:#f0f0f0;min-height:100vh;display:flex;flex-direction:column;align-items:center;padding:32px 16px;-webkit-font-smoothing:antialiased}
    :root{
      --accent:      {{ $currentTenant->accent_color ?? '#BEF264' }};
      --accent-text: {{ \App\Support\ColorHelper::accentTextColor($currentTenant->accent_color ?? '#BEF264') }};
      --bg2:   #1a1a1a;
      --bg3:   #222;
      --border:rgba(255,255,255,.1);
      --muted: rgba(255,255,255,.4);
    }
    .ob-header{width:100%;max-width:520px;margin-bottom:32px;text-align:center}
    .ob-brand{font-size:15px;font-weight:600;color:#f0f0f0;margin-bottom:24px}

    /* Progress dots */
    .ob-steps{display:flex;align-items:center;gap:8px;justify-content:center;margin-bottom:0}
    .ob-step-dot{width:8px;height:8px;border-radius:50%;background:var(--border);transition:all .2s}
    .ob-step-dot.done{background:rgba(190,242,100,.5)}
    .ob-step-dot.active{background:var(--accent);width:24px;border-radius:4px}
    .ob-step-line{flex:1;max-width:40px;height:1px;background:var(--border)}

    .ob-card{background:var(--bg2);border:0.5px solid var(--border);border-radius:16px;padding:36px;width:100%;max-width:520px}
    .ob-step-label{font-size:11px;text-transform:uppercase;letter-spacing:.09em;color:var(--muted);margin-bottom:8px}
    .ob-title{font-size:24px;font-weight:700;margin-bottom:8px;letter-spacing:-.01em}
    .ob-subtitle{font-size:15px;color:var(--muted);margin-bottom:28px;line-height:1.55}

    label{display:block;font-size:12px;font-weight:500;color:var(--muted);margin-bottom:6px;text-transform:uppercase;letter-spacing:.05em}
    input[type=text],input[type=number],input[type=file],textarea,select{width:100%;padding:10px 14px;background:rgba(255,255,255,.05);border:0.5px solid var(--border);border-radius:8px;color:#f0f0f0;font-size:14px;font-family:inherit;transition:border-color .12s;margin-bottom:16px}
    input[type=file]{padding:8px 14px;cursor:pointer}
    input:focus,textarea:focus,select:focus{outline:none;border-color:var(--accent)}
    .ob-color-row{display:flex;align-items:center;gap:10px;margin-bottom:16px}
    .ob-color-swatch{width:40px;height:40px;border-radius:8px;border:0.5px solid var(--border);overflow:hidden;flex-shrink:0;cursor:pointer}
    .ob-color-swatch input{width:52px;height:52px;margin:-6px;border:none;padding:0;cursor:pointer;background:none}
    .ob-color-text{flex:1;padding:10px 14px;background:rgba(255,255,255,.05);border:0.5px solid var(--border);border-radius:8px;color:#f0f0f0;font-size:14px;font-family:monospace}
    .ob-color-text:focus{outline:none;border-color:var(--accent)}
    .ob-hint{font-size:12px;color:var(--muted);margin-top:-12px;margin-bottom:16px;line-height:1.5}

    .ob-btn{width:100%;padding:13px;background:var(--accent);color:var(--accent-text);border:none;border-radius:10px;font-size:15px;font-weight:600;cursor:pointer;transition:filter .12s;margin-top:4px}
    .ob-btn:hover{filter:brightness(.93)}
    .ob-btn-ghost{background:transparent;border:0.5px solid var(--border);color:var(--muted);margin-top:10px}
    .ob-btn-ghost:hover{color:#f0f0f0;border-color:rgba(255,255,255,.2)}

    .ob-complete-icon{width:64px;height:64px;background:var(--accent);border-radius:50%;display:flex;align-items:center;justify-content:center;margin:0 auto 20px;font-size:28px}
    .ob-link-card{background:var(--bg3);border:0.5px solid var(--border);border-radius:10px;padding:14px 18px;display:flex;align-items:center;justify-content:space-between;margin-bottom:10px;text-decoration:none;color:#f0f0f0;transition:border-color .12s}
    .ob-link-card:hover{border-color:var(--accent)}
    .ob-link-label{font-size:14px;font-weight:500}
    .ob-link-url{font-size:12px;color:var(--muted);margin-top:2px}
    .ob-link-arrow{opacity:.4;font-size:16px}

    .error{background:rgba(226,75,74,.15);color:#F09595;border-radius:8px;padding:10px 14px;font-size:13px;margin-bottom:16px}
  </style>
</head>
<body>

<div class="ob-header">
  <div class="ob-brand">{{ $currentTenant->name }}</div>
  <div class="ob-steps">
    @php $steps = ['branding', 'services', 'complete']; @endphp
    @foreach($steps as $i => $s)
      <div class="ob-step-dot {{ $s === $step ? 'active' : ($loop->index < array_search($step, $steps) ? 'done' : '') }}"></div>
      @if(! $loop->last)<div class="ob-step-line"></div>@endif
    @endforeach
  </div>
</div>

<div class="ob-card">
  @if($step === 'branding')
    @include('tenant.onboarding._step-branding')
  @elseif($step === 'services')
    @include('tenant.onboarding._step-services')
  @else
    @include('tenant.onboarding._step-complete')
  @endif
</div>

<script>
document.querySelectorAll('input[type=color]').forEach(function(picker) {
  var textId = picker.id.replace('cp-','ct-');
  var text   = document.getElementById(textId);
  if (text) picker.addEventListener('input', function() { text.value = picker.value; });
});
document.querySelectorAll('.ob-color-text').forEach(function(text) {
  var pickId = text.id.replace('ct-','cp-');
  var picker = document.getElementById(pickId);
  if (picker) text.addEventListener('input', function() { if (/^#[0-9A-Fa-f]{6}$/.test(text.value)) picker.value = text.value; });
});
</script>
</body>
</html>
