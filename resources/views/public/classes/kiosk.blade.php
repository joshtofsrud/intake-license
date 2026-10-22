<!DOCTYPE html>
<html lang="en">
<head>
  {{-- MARKER-CLASSES-P10: self check-in on a studio tablet --}}
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="robots" content="noindex">
  @php
    $refreshTo = match ($screen) {
      'classes' => [60, route('tenant.customer.classes.kiosk', ['token' => $token])],
      'people'  => [90, route('tenant.customer.classes.kiosk', ['token' => $token])],
      'waiver'  => [180, route('tenant.customer.classes.kiosk.session', ['token' => $token, 'sessionId' => $session->id])],
      default   => [6, route('tenant.customer.classes.kiosk.session', ['token' => $token, 'sessionId' => $session->id])],
    };
  @endphp
  <meta http-equiv="refresh" content="{{ $refreshTo[0] }};url={{ $refreshTo[1] }}">
  <title>Self check-in — {{ $currentTenant->name }}</title>
  <link href="https://fonts.googleapis.com/css2?family={{ str_replace(' ', '+', $currentTenant->font_heading ?? 'Inter') }}:wght@400;500;600;700&family={{ str_replace(' ', '+', $currentTenant->font_body ?? 'Inter') }}:wght@400;500;600&display=swap" rel="stylesheet">
  <style>
    :root {
{!! \App\Support\DesignTokens::cssVars(\App\Support\DesignTokens::resolve($currentTenant)) !!}
      --p-r: 10px; --p-r-lg: 16px;
    }
    *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
    body { font-family: var(--p-font-body); background: var(--p-bg); color: var(--p-text); font-size: 17px; line-height: 1.5; -webkit-font-smoothing: antialiased; min-height: 100vh; }
    a { color: inherit; text-decoration: none; }
    button { font-family: inherit; cursor: pointer; }
    .k-top { display: flex; align-items: center; justify-content: space-between; padding: 20px 28px; border-bottom: 1px solid var(--p-border); }
    .k-brand { font-family: var(--p-font-heading); font-size: 20px; font-weight: 700; display: flex; align-items: center; gap: 10px; }
    .k-brand img { height: 30px; width: auto; border-radius: 6px; }
    .k-clock { font-size: 15px; opacity: .55; }
    .k-body { max-width: 980px; margin: 0 auto; padding: 32px 28px 60px; }
    .k-h { font-family: var(--p-font-heading); font-size: 30px; font-weight: 700; margin-bottom: 6px; }
    .k-sub { font-size: 16px; opacity: .55; margin-bottom: 26px; }
    .k-list { display: grid; gap: 12px; }
    .k-class { display: flex; align-items: center; gap: 20px; padding: 22px 24px; border: 1.5px solid var(--p-border); border-radius: var(--p-r-lg); }
    .k-class:active { border-color: var(--p-accent); }
    .k-class b { font-size: 22px; font-family: var(--p-font-heading); }
    .k-class .t { font-size: 20px; font-weight: 600; min-width: 110px; }
    .k-class .w { margin-left: auto; opacity: .55; font-size: 15px; }
    .k-names { display: grid; grid-template-columns: repeat(auto-fill, minmax(200px, 1fr)); gap: 12px; }
    .k-name { width: 100%; padding: 24px 16px; font-size: 20px; font-weight: 600; border-radius: var(--p-r-lg); border: 1.5px solid var(--p-border); background: transparent; color: var(--p-text); text-align: center; }
    .k-name:active { border-color: var(--p-accent); }
    .k-name.in { opacity: .45; cursor: default; }
    .k-name.in::after { content: ' ✓'; }
    .k-back { display: inline-block; margin-bottom: 18px; font-size: 16px; opacity: .6; }
    .k-note { margin-top: 26px; font-size: 15px; opacity: .55; }
    .k-msg { text-align: center; padding: 70px 10px; }
    .k-msg .ico { width: 84px; height: 84px; border-radius: 50%; display: flex; align-items: center; justify-content: center; margin: 0 auto 22px; background: var(--p-accent); color: var(--p-accent-text); font-size: 40px; }
    .k-msg .ico.warn { background: #FAEEDA; color: #8A5A10; }
    .k-msg h1 { font-family: var(--p-font-heading); font-size: 34px; margin-bottom: 8px; }
    .k-msg p { font-size: 18px; opacity: .6; }
    .k-waiver { white-space: pre-line; font-size: 16px; line-height: 1.65; padding: 18px 20px; background: var(--p-surface); border-radius: var(--p-r-lg); max-height: 44vh; overflow: auto; margin-bottom: 20px; }
    .k-check { display: flex; gap: 12px; align-items: center; font-size: 18px; margin-bottom: 16px; }
    .k-check input { width: 26px; height: 26px; }
    .k-input { width: 100%; padding: 16px 18px; font-size: 19px; border: 1.5px solid var(--p-border); border-radius: var(--p-r); background: transparent; color: var(--p-text); font-family: inherit; margin-bottom: 18px; }
    .k-btn { width: 100%; padding: 20px; font-size: 19px; font-weight: 600; border: none; border-radius: var(--p-r); background: var(--p-accent); color: var(--p-accent-text); }
    .k-err { color: #A32D2D; margin-bottom: 14px; font-size: 16px; }
  </style>
</head>
<body>
<div class="k-top">
  <div class="k-brand">@if($currentTenant->logo_url)<img src="{{ $currentTenant->logo_url }}" alt="">@endif{{ $currentTenant->name }}</div>
  <div class="k-clock">{{ now(tenant()->timezone())->format('g:i A') }}</div>
</div>
<div class="k-body">

@if($screen === 'classes')
  <div class="k-h">Check in for class</div>
  <div class="k-sub">Tap your class.</div>
  <div class="k-list">
    @forelse($sessions as $s)
      <a class="k-class" href="{{ route('tenant.customer.classes.kiosk.session', ['token' => $token, 'sessionId' => $s->id]) }}">
        <span class="t">{{ tlocal($s->starts_at, 'g:i A') }}</span>
        <b>{{ $s->template?->name }}</b>
        @if($s->instructor_snapshot)<span class="w">with {{ $s->instructor_snapshot }}</span>@endif
      </a>
    @empty
      <div class="k-msg" style="padding:40px 10px"><p>No classes are starting in the next hour.</p></div>
    @endforelse
  </div>

@elseif($screen === 'people')
  <a class="k-back" href="{{ route('tenant.customer.classes.kiosk', ['token' => $token]) }}">‹ All classes</a>
  <div class="k-h">{{ $session->template?->name }}</div>
  <div class="k-sub">{{ tlocal($session->starts_at, 'g:i A') }}{{ $session->instructor_snapshot ? ' with ' . $session->instructor_snapshot : '' }} · Tap your name.</div>
  @if($people->isEmpty())<div class="k-msg" style="padding:30px 10px"><p>Nobody is booked into this class yet.</p></div>@endif
  <div class="k-names">
    @foreach($people as $p)
      @if($p['in'])
        <span class="k-name in">{{ $p['label'] }}</span>
      @else
        <form method="POST" action="{{ route('tenant.customer.classes.kiosk.in', ['token' => $token, 'sessionId' => $session->id, 'registrationId' => $p['id']]) }}">
          @csrf
          <button type="submit" class="k-name">{{ $p['label'] }}</button>
        </form>
      @endif
    @endforeach
  </div>
  <div class="k-note">Not on the list? Please see the front desk.</div>

@elseif($screen === 'waiver')
  <div class="k-h">Hi {{ $name }}, one thing first</div>
  <div class="k-sub">Please read and sign the waiver.</div>
  @if($tried)<div class="k-err">Tick the box and type your full name to sign.</div>@endif
  <div class="k-waiver">{{ $waiver?->body }}</div>
  <form method="POST" action="{{ route('tenant.customer.classes.kiosk.in', ['token' => $token, 'sessionId' => $session->id, 'registrationId' => $registrationId]) }}">
    @csrf
    <label class="k-check"><input type="checkbox" name="waiver_agree" value="1"> I've read the waiver and agree to it.</label>
    <input type="text" name="waiver_name" class="k-input" maxlength="160" placeholder="Your full name" autocomplete="off">
    <button type="submit" class="k-btn">Sign and check in</button>
  </form>
  <a class="k-back" style="margin-top:18px" href="{{ route('tenant.customer.classes.kiosk.session', ['token' => $token, 'sessionId' => $session->id]) }}">‹ Not you? Go back</a>

@elseif($screen === 'done')
  <div class="k-msg"><div class="ico">✓</div><h1>You're in, {{ $name }}.</h1><p>Enjoy {{ $session->template?->name }}.</p></div>
@elseif($screen === 'already')
  <div class="k-msg"><div class="ico">✓</div><h1>You're already checked in.</h1><p>Enjoy class, {{ $name }}.</p></div>
@elseif($screen === 'desk')
  <div class="k-msg"><div class="ico warn">!</div><h1>Please see the front desk.</h1><p>They'll get you checked in, {{ $name }}.</p></div>
@else
  <div class="k-msg"><p>Something changed. Please try again.</p></div>
@endif

</div>
</body>
</html>
