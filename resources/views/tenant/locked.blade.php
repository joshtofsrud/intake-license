{{-- MARKER-TENANT-STANDING — replaces the admin app, never the public site. --}}
@php
  $suspended = ($standing['state'] ?? '') === 'suspended';
@endphp
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>{{ $suspended ? 'Account suspended' : 'Account on hold' }} — {{ $tenant->name }}</title>
  <style>
    body{margin:0;min-height:100vh;display:flex;align-items:center;justify-content:center;
      background:#0a0a0a;color:#e7e7ea;font:15px/1.6 -apple-system,BlinkMacSystemFont,"Segoe UI",Inter,sans-serif;padding:24px}
    .w{max-width:520px;text-align:center}
    .mk{width:56px;height:56px;border-radius:14px;background:rgba(251,191,36,.13);color:#FBBF24;
      display:flex;align-items:center;justify-content:center;font-size:25px;margin:0 auto 20px}
    h1{font-size:22px;margin:0 0 10px;letter-spacing:-.02em}
    p{color:#9a9aa2;font-size:14px;margin:0 auto;max-width:440px}
    .acts{margin-top:24px;display:flex;gap:9px;justify-content:center;flex-wrap:wrap}
    .btn{display:inline-block;padding:10px 18px;border-radius:9px;font-size:13.5px;text-decoration:none;
      border:1px solid #26272b;background:#1a1b1f;color:#e7e7ea}
    .btn.primary{background:#BEF264;border-color:#BEF264;color:#0a0a0a;font-weight:700}
    /* LEGEND — what is and isn't affected. Without this a shop assumes their
       customers are locked out too, and panics. */
    .legend{margin-top:26px;border:1px solid #26272b;border-radius:10px;padding:14px 16px;text-align:left}
    .legend h2{font-size:10.5px;letter-spacing:.09em;text-transform:uppercase;color:#6b6b73;margin:0 0 9px}
    .legend div{display:flex;gap:9px;font-size:12.5px;color:#9a9aa2;padding:3px 0}
    .legend .y{color:#BEF264;flex-shrink:0;width:14px}
    .legend .n{color:#fca5a5;flex-shrink:0;width:14px}
    .fine{margin-top:18px;font-size:11.5px;color:#6b6b73}
    .fine a{color:#9a9aa2}
  </style>
</head>
<body>
  <div class="w">
    <div class="mk">&#9888;</div>

    @if($suspended)
      <h1>Your account has been suspended</h1>
      <p>Access to Intake has been paused by our team.
        @if($standing['reason'])<br><span style="color:#c9c9cf">{{ $standing['reason'] }}</span>@endif
      </p>
      <div class="acts"><a class="btn primary" href="mailto:support@intake.works">Contact support</a></div>
    @else
      <h1>Your account is on hold</h1>
      <p>We weren't able to collect payment, and the grace period has passed. Update your card
         and everything comes straight back — your data, settings and history are untouched.</p>
      <div class="acts">
        <a class="btn primary" href="{{ route('tenant.billing.portal') }}">Update payment method</a>
        <a class="btn" href="mailto:support@intake.works">Contact support</a>
      </div>
    @endif

    <div class="legend">
      <h2>What this affects</h2>
      <div><span class="n">&#10005;</span> Staff access to Intake — the register, schedule, inventory and admin</div>
      <div><span class="y">&#10003;</span> Your booking page — customers can still book</div>
      <div><span class="y">&#10003;</span> Customer accounts — existing customers can still sign in</div>
      <div><span class="y">&#10003;</span> Gift card balance checks</div>
      <div><span class="y">&#10003;</span> All of your data — nothing has been deleted</div>
    </div>

    <div class="fine">Signed in at {{ $tenant->name }} ·
      <form method="POST" action="{{ route('tenant.logout') }}" style="display:inline">
        @csrf
        <button type="submit" style="background:none;border:none;padding:0;color:#9a9aa2;
          font:inherit;text-decoration:underline;cursor:pointer">Sign out</button>
      </form>
    </div>
  </div>
</body>
</html>
