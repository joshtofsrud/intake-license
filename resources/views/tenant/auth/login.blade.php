<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Sign in — {{ $currentTenant->name }}</title>
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
    .card{background:var(--bg2);border:0.5px solid var(--border);border-radius:16px;padding:36px;width:100%;max-width:400px}
    .logo-wrap{text-align:center;margin-bottom:28px}
    .logo-wrap img{height:40px;margin:0 auto 10px;display:block;border-radius:6px}
    .shop-name{font-size:18px;font-weight:600;color:var(--text)}
    .shop-sub{font-size:13px;color:var(--muted);margin-top:4px}
    h1{font-size:20px;font-weight:600;margin-bottom:24px;text-align:center}
    label{display:block;font-size:12px;font-weight:500;color:var(--muted);margin-bottom:6px;text-transform:uppercase;letter-spacing:.05em}
    input[type=email],input[type=password],input[type=text]{width:100%;padding:10px 14px;background:rgba(255,255,255,.05);border:0.5px solid var(--border);border-radius:8px;color:var(--text);font-size:14px;font-family:inherit;transition:border-color .12s;margin-bottom:16px}
    input:focus{outline:none;border-color:var(--accent)}
    .btn{width:100%;padding:12px;background:var(--accent);color:var(--accent-text);border:none;border-radius:8px;font-size:14px;font-weight:600;cursor:pointer;transition:filter .12s;margin-top:4px}
    .btn:hover{filter:brightness(.93)}
    .error{background:rgba(226,75,74,.15);color:#F09595;border-radius:8px;padding:10px 14px;font-size:13px;margin-bottom:16px}
    .success{background:rgba(99,153,34,.18);color:#97C459;border-radius:8px;padding:10px 14px;font-size:13px;margin-bottom:16px}
    .links{display:flex;justify-content:center;gap:20px;margin-top:20px;font-size:13px}
    .links a{color:var(--muted);transition:color .12s}
    .links a:hover{color:var(--text)}
    .remember{display:flex;align-items:center;gap:8px;font-size:13px;color:var(--muted);margin-bottom:16px;cursor:pointer}
    .remember input{width:auto;margin-bottom:0}
  </style>
</head>
<body>
<div class="card">
  <div class="logo-wrap">
    @if($currentTenant->logo_url)
      <img src="{{ $currentTenant->logo_url }}" alt="{{ $currentTenant->name }}">
    @endif
    <div class="shop-name">{{ $currentTenant->name }}</div>
    <div class="shop-sub">Staff portal</div>
  </div>

  @if(session('reset_sent'))
    <div class="success">If that email exists, a reset link has been sent.</div>
  @endif

  @if($errors->any())
    <div class="error">{{ $errors->first() }}</div>
  @endif

  <form method="POST" action="{{ route('tenant.login.submit') }}">
    @csrf
    <label>Email</label>
    <input type="email" name="email" value="{{ old('email') }}" required autofocus
      placeholder="you@example.com">

    <label>Password</label>
    <input type="password" name="password" required placeholder="••••••••">

    <label class="remember">
      <input type="checkbox" name="remember" value="1"> Remember me for 30 days
    </label>

    <button type="submit" class="btn">Sign in</button>
  </form>

  <div class="links">
    <a href="{{ route('tenant.login') }}?forgot=1">Forgot password?</a>
    <a href="/">← Back to site</a>
  </div>
</div>
</body>
</html>
