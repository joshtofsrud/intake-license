<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Sign in — Intake</title>
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
  <style>
    *{box-sizing:border-box;margin:0;padding:0}
    body{font-family:'Inter',-apple-system,sans-serif;background:#0c0c0c;color:#f0f0f0;min-height:100vh;display:flex;flex-direction:column;align-items:center;justify-content:center;padding:24px;-webkit-font-smoothing:antialiased}
    :root{--accent:#BEF264;--accent-text:#0a0a0a;--bg2:#1a1a1a;--border:rgba(255,255,255,.1);--muted:rgba(255,255,255,.4)}
    .logo{display:flex;align-items:center;gap:8px;font-size:18px;font-weight:700;margin-bottom:32px}
    .logo-mark{width:28px;height:28px;background:var(--accent);border-radius:6px;display:flex;align-items:center;justify-content:center;font-size:13px;font-weight:800;color:var(--accent-text)}
    .card{background:var(--bg2);border:0.5px solid var(--border);border-radius:16px;padding:36px;width:100%;max-width:400px}
    h1{font-size:22px;font-weight:700;margin-bottom:8px}
    p{font-size:14px;color:var(--muted);margin-bottom:24px;line-height:1.6}
    label{display:block;font-size:12px;font-weight:500;color:var(--muted);margin-bottom:6px;text-transform:uppercase;letter-spacing:.05em}
    input{width:100%;padding:10px 14px;background:rgba(255,255,255,.05);border:0.5px solid var(--border);border-radius:8px;color:#f0f0f0;font-size:14px;font-family:inherit;margin-bottom:16px;transition:border-color .12s}
    input:focus{outline:none;border-color:var(--accent)}
    .btn{width:100%;padding:12px;background:var(--accent);color:var(--accent-text);border:none;border-radius:8px;font-size:14px;font-weight:600;cursor:pointer}
    .links{display:flex;justify-content:space-between;margin-top:20px;font-size:13px;color:var(--muted)}
    .links a{color:var(--muted);transition:color .12s}
    .links a:hover{color:#f0f0f0}
    .divider{border:none;border-top:0.5px solid var(--border);margin:20px 0}
    .subdomain-form{display:flex;gap:8px;margin-bottom:16px}
    .subdomain-form input{margin-bottom:0;flex:1}
    .subdomain-suffix{display:flex;align-items:center;font-size:13px;color:var(--muted);white-space:nowrap;padding:10px 0}
  </style>
</head>
<body>
  <div class="logo">
    <div class="logo-mark">I</div>
    intake
  </div>

  <div class="card">
    <h1>Sign in to your shop</h1>
    <p>Enter your shop's subdomain to go to your login page.</p>

    <label>Your subdomain</label>
    <div class="subdomain-form">
      <input type="text" id="subdomain-input" placeholder="yourshop" autocomplete="off">
      <div class="subdomain-suffix">.intake.works</div>
    </div>
    <button type="button" class="btn" onclick="goToShop()">Go to my shop →</button>

    <hr class="divider">

    <div class="links">
      <a href="{{ route('platform.signup') }}">Create an account</a>
      <a href="{{ route('marketing.home') }}">← Back to intake.works</a>
    </div>
  </div>

  <script>
  function goToShop() {
    var sub = document.getElementById('subdomain-input').value.trim().toLowerCase();
    if (!sub) { document.getElementById('subdomain-input').focus(); return; }
    window.location.href = 'https://' + sub + '.intake.works/admin/login';
  }
  document.getElementById('subdomain-input').addEventListener('keydown', function(e) {
    if (e.key === 'Enter') goToShop();
  });
  </script>
</body>
</html>
