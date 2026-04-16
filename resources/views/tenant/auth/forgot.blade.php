<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Reset password — {{ $currentTenant->name }}</title>
  @if($currentTenant->favicon_url)<link rel="icon" href="{{ $currentTenant->favicon_url }}">@endif
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600&display=swap" rel="stylesheet">
  <style>
    *{box-sizing:border-box;margin:0;padding:0}
    body{font-family:'Inter',-apple-system,sans-serif;background:#0f0f0f;color:#f0f0f0;min-height:100vh;display:flex;align-items:center;justify-content:center;padding:24px;-webkit-font-smoothing:antialiased}
    :root{--accent:{{ $currentTenant->accent_color ?? '#BEF264' }};--accent-text:{{ \App\Support\ColorHelper::accentTextColor($currentTenant->accent_color ?? '#BEF264') }};--bg2:#1a1a1a;--border:rgba(255,255,255,.1);--muted:rgba(255,255,255,.4)}
    .card{background:var(--bg2);border:0.5px solid var(--border);border-radius:16px;padding:36px;width:100%;max-width:400px}
    h1{font-size:20px;font-weight:600;margin-bottom:8px}
    p{font-size:14px;color:var(--muted);margin-bottom:24px;line-height:1.55}
    label{display:block;font-size:12px;font-weight:500;color:var(--muted);margin-bottom:6px;text-transform:uppercase;letter-spacing:.05em}
    input{width:100%;padding:10px 14px;background:rgba(255,255,255,.05);border:0.5px solid var(--border);border-radius:8px;color:#f0f0f0;font-size:14px;font-family:inherit;margin-bottom:16px;transition:border-color .12s}
    input:focus{outline:none;border-color:var(--accent)}
    .btn{width:100%;padding:12px;background:var(--accent);color:var(--accent-text);border:none;border-radius:8px;font-size:14px;font-weight:600;cursor:pointer}
    .success{background:rgba(99,153,34,.18);color:#97C459;border-radius:8px;padding:10px 14px;font-size:13px;margin-bottom:16px}
    .back{display:block;text-align:center;margin-top:18px;font-size:13px;color:var(--muted)}
    .back:hover{color:#f0f0f0}
  </style>
</head>
<body>
<div class="card">
  <h1>Reset password</h1>
  <p>Enter your email and we'll send a reset link if an account exists.</p>

  @if(session('reset_sent'))
    <div class="success">Check your inbox — a reset link is on its way.</div>
  @endif

  <form method="POST" action="{{ route('tenant.login') }}?forgot=1">
    @csrf
    <label>Email</label>
    <input type="email" name="email" required autofocus placeholder="you@example.com">
    <button type="submit" class="btn">Send reset link</button>
  </form>

  <a href="{{ route('tenant.login') }}" class="back">← Back to sign in</a>
</div>
</body>
</html>
