<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Set up your account — {{ $currentTenant->name }}</title>
  @if($currentTenant->favicon_url)<link rel="icon" href="{{ $currentTenant->favicon_url }}">@endif
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600&display=swap" rel="stylesheet">
  <style>
    *{box-sizing:border-box;margin:0;padding:0}
    body{font-family:'Inter',-apple-system,sans-serif;background:#0f0f0f;color:#f0f0f0;min-height:100vh;display:flex;align-items:center;justify-content:center;padding:24px;-webkit-font-smoothing:antialiased}
    :root{--accent:{{ $currentTenant->accent_color ?? '#BEF264' }};--accent-text:{{ \App\Support\ColorHelper::accentTextColor($currentTenant->accent_color ?? '#BEF264') }};--bg2:#1a1a1a;--border:rgba(255,255,255,.1);--muted:rgba(255,255,255,.4)}
    .card{background:var(--bg2);border:0.5px solid var(--border);border-radius:16px;padding:36px;width:100%;max-width:400px}
    h1{font-size:20px;font-weight:600;margin-bottom:8px}
    p{font-size:14px;color:var(--muted);margin-bottom:24px}
    label{display:block;font-size:12px;font-weight:500;color:var(--muted);margin-bottom:6px;text-transform:uppercase;letter-spacing:.05em}
    input{width:100%;padding:10px 14px;background:rgba(255,255,255,.05);border:0.5px solid var(--border);border-radius:8px;color:#f0f0f0;font-size:14px;font-family:inherit;margin-bottom:16px;transition:border-color .12s}
    input:focus{outline:none;border-color:var(--accent)}
    input[readonly]{color:var(--muted);cursor:default;background:rgba(255,255,255,.02)}
    .btn{width:100%;padding:12px;background:var(--accent);color:var(--accent-text);border:none;border-radius:8px;font-size:14px;font-weight:600;cursor:pointer}
    .error{background:rgba(226,75,74,.15);color:#F09595;border-radius:8px;padding:10px 14px;font-size:13px;margin-bottom:16px}
  </style>
</head>
<body>
<div class="card">
  <h1>Welcome to {{ $currentTenant->name }}</h1>
  <p>Hi {{ $user->name }} — set a password to finish setting up your account.</p>

  @if($errors->any())
    <div class="error">{{ $errors->first() }}</div>
  @endif

  <form method="POST" action="{{ route('tenant.team.setup.complete') }}">
    @csrf
    <input type="hidden" name="token" value="{{ $token }}">

    <label>Email</label>
    <input type="email" value="{{ $user->email }}" readonly>

    <label>New password</label>
    <input type="password" name="password" required minlength="8" placeholder="Min 8 characters" autofocus>

    <label>Confirm password</label>
    <input type="password" name="password_confirmation" required minlength="8" placeholder="Re-enter password">

    <button type="submit" class="btn">Set password &amp; continue</button>
  </form>
</div>
</body>
</html>
