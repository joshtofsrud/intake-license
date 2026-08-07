{{-- MARKER-REPPANEL-SETUP — standalone rep account setup page --}}
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
@include('partials.mobile-input-zoom') {{-- MARKER-MOBILE-INPUT-ZOOM --}}
<title>Set up your Intake rep account</title>
<style>
body{margin:0;background:#0a0c11;color:#e7e9f0;font-family:Inter,-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,sans-serif;display:grid;place-items:center;min-height:100vh}
.card{width:380px;max-width:92vw;background:#12151d;border:1px solid #262c39;border-radius:14px;padding:26px}
h1{font-size:18px;margin:0 0 4px;letter-spacing:-.01em}
.sub{color:#69707f;font-size:13px;margin-bottom:20px}
label{display:block;font-size:12.5px;color:#9aa3b4;margin:0 0 6px;font-weight:600}
input{width:100%;box-sizing:border-box;background:#171b25;border:1px solid #313847;border-radius:9px;padding:10px 12px;color:#e7e9f0;font-size:14px;margin-bottom:14px}
input:focus{outline:none;border-color:#38bdf8}
input[disabled]{opacity:.6}
button{width:100%;background:#0284c7;border:none;color:#fff;font-weight:700;font-size:14px;padding:11px;border-radius:9px;cursor:pointer}
button:hover{background:#0369a1}
.err{background:rgba(251,113,133,.1);border:1px solid rgba(251,113,133,.4);color:#fda4af;border-radius:9px;padding:9px 12px;font-size:12.5px;margin-bottom:14px}
</style>
</head>
<body>
<div class="card">
  <h1>Welcome, {{ $rep->name }}</h1>
  <div class="sub">{{ $rep->agency?->name }} · set a password to access your Intake rep dashboard.</div>

  @if ($errors->any())
    <div class="err">{{ $errors->first() }}</div>
  @endif

  <form method="POST" action="{{ url('/rep-setup/' . $token) }}">
    @csrf
    <label>Email</label>
    <input type="email" value="{{ $rep->email }}" disabled>
    <label>Password</label>
    <input type="password" name="password" required minlength="8" autofocus>
    <label>Confirm password</label>
    <input type="password" name="password_confirmation" required minlength="8">
    <button type="submit">Create account</button>
  </form>
</div>
</body>
</html>
