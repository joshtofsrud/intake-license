<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>{{ $done ? 'Unsubscribed' : 'Unsubscribe' }} — Intake</title>
{{-- MARKER-PLATFORM-EMAIL --}}
<style>
  body{margin:0;background:#0c0c0c;color:#f0f0f0;font:16px/1.6 ui-sans-serif,system-ui,-apple-system,"Segoe UI",Inter,sans-serif;
    display:flex;align-items:center;justify-content:center;min-height:100vh;padding:24px}
  .box{max-width:520px;width:100%}
  .mark{width:34px;height:34px;border-radius:8px;background:#BEF264;display:block;margin-bottom:22px}
  h1{font-size:26px;font-weight:800;letter-spacing:-.02em;margin:0 0 10px}
  p{color:rgba(240,240,240,.7);margin:0 0 16px}
  b{color:#f0f0f0}
  button{background:#BEF264;border:0;border-radius:9px;color:#14180a;font:inherit;font-weight:700;font-size:15px;padding:11px 20px;cursor:pointer}
  .small{font-size:13px;color:rgba(240,240,240,.45);margin-top:22px;line-height:1.6}
</style>
</head>
<body>
<div class="box">
  <span class="mark"></span>
  @if($done)
    <h1>You're unsubscribed</h1>
    <p><b>{{ $email }}</b> won't get product news or marketing from Intake again.</p>
    <p>If you use Intake to run a shop, you'll still get anything about your own account — invoices,
       alerts and service notices. Those aren't marketing and you can't be opted out of them here.</p>
  @else
    <h1>Unsubscribe from Intake news?</h1>
    <p>This stops product news and marketing to <b>{{ $email }}</b>.</p>
    <p>Anything about your own account — invoices, alerts, service notices — keeps coming, because
       that isn't marketing.</p>
    <form method="POST" action="{{ route('platform.unsubscribe.confirm', ['e' => $e, 'sig' => $sig]) }}">
      @csrf
      <button type="submit">Unsubscribe</button>
    </form>
  @endif
  <div class="small">Intake Inc{{ \App\Services\Platform\PlatformMailer::postalAddress() ? ' · ' . \App\Services\Platform\PlatformMailer::postalAddress() : '' }}</div>
</div>
</body>
</html>
