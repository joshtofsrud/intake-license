<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  @include('partials.mobile-input-zoom') {{-- MARKER-MOBILE-INPUT-ZOOM --}}
  <title>Choose location — {{ $currentTenant->name }}</title>
  @if($currentTenant->favicon_url)
    <link rel="icon" href="{{ $currentTenant->favicon_url }}">
  @endif
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600&display=swap" rel="stylesheet">
  <style>
    *{box-sizing:border-box;margin:0;padding:0}
    body{font-family:'Inter',-apple-system,sans-serif;background:var(--bg);color:var(--text);min-height:100vh;display:flex;align-items:center;justify-content:center;padding:24px;-webkit-font-smoothing:antialiased}
    :root{
      --accent: {{ $currentTenant->accent_color ?? '#BEF264' }};
      --accent-text: {{ \App\Support\ColorHelper::accentTextColor($currentTenant->accent_color ?? '#BEF264') }};
      --bg:     #0f0f0f;
      --bg2:    #1a1a1a;
      --text:   #f0f0f0;
      --muted:  rgba(255,255,255,.4);
      --border: rgba(255,255,255,.1);
    }
    .card{background:var(--bg2);border:0.5px solid var(--border);border-radius:16px;padding:36px;width:100%;max-width:440px}
    .logo-wrap{text-align:center;margin-bottom:24px}
    .logo-wrap img{height:40px;margin:0 auto 10px;display:block;border-radius:6px}
    .shop-name{font-size:18px;font-weight:600;color:var(--text)}
    .shop-sub{font-size:13px;color:var(--muted);margin-top:4px}
    h1{font-size:20px;font-weight:600;margin-bottom:8px;text-align:center}
    .lede{font-size:13px;color:var(--muted);text-align:center;margin-bottom:24px}
    .loc-list{display:flex;flex-direction:column;gap:10px;margin-bottom:18px}
    .loc-btn{
      display:flex;align-items:center;justify-content:space-between;gap:14px;
      width:100%;padding:14px 16px;
      background:rgba(255,255,255,.04);border:0.5px solid var(--border);border-radius:10px;
      color:var(--text);font-family:inherit;font-size:14px;text-align:left;
      cursor:pointer;transition:all .12s
    }
    .loc-btn:hover{background:rgba(255,255,255,.07);border-color:var(--accent)}
    .loc-btn.is-current{border-color:var(--accent);background:rgba(190,242,100,.04)}
    .loc-name{font-weight:600;font-size:15px}
    .loc-meta{font-size:12px;color:var(--muted);margin-top:3px}
    .loc-default-pill{
      font-size:10px;font-weight:600;text-transform:uppercase;letter-spacing:.06em;
      color:var(--accent-text);background:var(--accent);padding:3px 8px;border-radius:99px
    }
    .loc-arrow{color:var(--muted);font-size:18px;line-height:1}
    .error{background:rgba(226,75,74,.15);color:#F09595;border-radius:8px;padding:10px 14px;font-size:13px;margin-bottom:16px}
    .footer-link{text-align:center;margin-top:18px;font-size:13px}
    .footer-link a{color:var(--muted);transition:color .12s}
    .footer-link a:hover{color:var(--text)}
  </style>
</head>
<body>
<div class="card">

  <div class="logo-wrap">
    @if($currentTenant->logo_url)
      <img src="{{ $currentTenant->logo_url }}" alt="{{ $currentTenant->name }}">
    @endif
    <div class="shop-name">{{ $currentTenant->name }}</div>
    <div class="shop-sub">{{ Auth::guard('tenant')->user()?->name }}</div>
  </div>

  <h1>Choose your location</h1>
  <div class="lede">You have access to {{ $locations->count() }} locations. Pick where you're working today.</div>

  @if($errors->any())
    <div class="error">{{ $errors->first() }}</div>
  @endif

  <form method="POST" action="{{ route('tenant.select-location.store') }}">
    @csrf
    <div class="loc-list">
      @foreach($locations as $loc)
        <button type="submit" name="location_id" value="{{ $loc->id }}"
                class="loc-btn {{ $currentLocationId === $loc->id ? 'is-current' : '' }}">
          <div>
            <div class="loc-name">{{ $loc->name }}</div>
            <div class="loc-meta">
              @if($loc->city || $loc->state)
                {{ trim(($loc->city ?? '') . ($loc->state ? ', ' . $loc->state : '')) }}
              @else
                {{ $loc->slug }}
              @endif
            </div>
          </div>
          <div style="display:flex;align-items:center;gap:10px">
            @if($loc->is_default)
              <span class="loc-default-pill">Default</span>
            @endif
            <span class="loc-arrow">→</span>
          </div>
        </button>
      @endforeach
    </div>
  </form>

  <div class="footer-link">
    <a href="{{ route('tenant.logout') }}"
       onclick="event.preventDefault();document.getElementById('logout-form').submit();">Sign out</a>
  </div>
  <form id="logout-form" method="POST" action="{{ route('tenant.logout') }}" style="display:none">
    @csrf
  </form>

</div>
</body>
</html>
