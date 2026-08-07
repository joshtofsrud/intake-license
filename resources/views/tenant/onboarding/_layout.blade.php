{{--
  Shared layout for the 8-step onboarding wizard.
  Each step extends this and yields content into the @yield('screen') slot.
  Mockup parity: dark + lime, Inter, top bar, step pills, screen card,
  actions footer.

  Required vars passed by OnboardingWizardController:
    $currentStep  (1..8)
    $totalSteps   (always 8)
    $tenant       (the current tenant)

  Optional vars a step may pass:
    $eyebrow      (default: "Step {$currentStep} of {$totalSteps}")
    $title        (the screen-title h2 text)
    $subtitle     (the screen-sub paragraph text)
--}}
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  @include('partials.mobile-input-zoom') {{-- MARKER-MOBILE-INPUT-ZOOM --}}
  <meta name="csrf-token" content="{{ csrf_token() }}">
  <title>Onboarding — {{ $tenant->name }} · Intake</title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
  <style>
    :root {
      --bg: #0a0a0a;
      --bg-2: #131313;
      --bg-3: #1a1a1a;
      --line: #1f1f1f;
      --line-2: #2a2a2a;
      --text: #f0f0f0;
      --text-2: #c8c8c8;
      --text-3: #888;
      --text-4: #5a5a5a;
      --lime: #D4FF3F;
      --lime-text: #0a0a0a;
      --r-sm: 6px;
      --r: 10px;
      --r-lg: 14px;
    }
    * { box-sizing: border-box; margin: 0; padding: 0; }
    html, body {
      background: var(--bg); color: var(--text);
      font-family: 'Inter', -apple-system, BlinkMacSystemFont, sans-serif;
      font-size: 14px; line-height: 1.55;
      -webkit-font-smoothing: antialiased;
    }
    .shell { max-width: 1180px; margin: 0 auto; padding: 28px 24px 80px; }
    .top {
      display: flex; align-items: center; justify-content: space-between;
      margin-bottom: 22px;
    }
    .brand {
      display: inline-flex; align-items: center; gap: 9px;
      font-weight: 700; font-size: 14px;
    }
    .brand-mark {
      background: var(--lime); color: var(--lime-text);
      padding: 3px 7px; border-radius: 4px;
      font-weight: 800; letter-spacing: -0.02em;
    }
    .doctitle {
      font-size: 11px; text-transform: uppercase; letter-spacing: 0.08em;
      color: var(--text-3); margin-top: 4px;
    }
    .stepnav {
      display: grid; grid-template-columns: repeat(8, 1fr);
      gap: 6px; margin-bottom: 28px;
    }
    .step-pill {
      background: var(--bg-2); border: 1px solid var(--line);
      border-radius: var(--r-sm); padding: 10px 12px;
      transition: all 0.15s; position: relative;
      text-decoration: none; color: inherit;
      display: block;
    }
    .step-pill.active {
      border-color: var(--lime);
      background: linear-gradient(180deg, rgba(212,255,63,0.06), var(--bg-2));
    }
    .step-pill.done::after {
      content: '✓'; position: absolute; top: 8px; right: 8px;
      color: var(--lime); font-size: 9px; font-weight: 800;
    }
    .step-num {
      font-size: 9px; color: var(--text-3); font-weight: 700;
      text-transform: uppercase; letter-spacing: 0.08em;
    }
    .step-pill.active .step-num { color: var(--lime); }
    .step-name {
      font-size: 12px; font-weight: 600; color: var(--text-2);
      margin-top: 3px; line-height: 1.3;
    }
    .step-pill.active .step-name { color: var(--text); }
    .screen {
      background: var(--bg-2); border: 1px solid var(--line);
      border-radius: var(--r-lg); padding: 36px 40px;
      min-height: 540px;
    }
    .screen-header { margin-bottom: 28px; }
    .screen-eyebrow {
      font-size: 10.5px; font-weight: 700; color: var(--lime);
      text-transform: uppercase; letter-spacing: 0.1em; margin-bottom: 8px;
    }
    .screen-title {
      font-size: 28px; font-weight: 800; letter-spacing: -0.02em;
      line-height: 1.18; margin-bottom: 8px;
    }
    .screen-sub { color: var(--text-2); font-size: 14px; max-width: 600px; }
    .actions {
      display: flex; align-items: center; justify-content: space-between;
      margin-top: 32px; padding-top: 20px;
      border-top: 1px solid var(--line);
    }
    .btn {
      font-family: inherit; font-size: 14px; font-weight: 600;
      padding: 11px 22px; border-radius: var(--r);
      cursor: pointer; border: 1px solid transparent;
      transition: all 0.15s; text-decoration: none;
      display: inline-block;
    }
    .btn-primary { background: var(--lime); color: var(--lime-text); }
    .btn-primary:hover { transform: translateY(-1px); }
    .btn-primary:disabled { opacity: 0.6; cursor: not-allowed; }
    .btn-ghost {
      background: transparent; color: var(--text-3);
      border-color: var(--line-2);
    }
    .btn-ghost:hover { color: var(--text); border-color: var(--text-3); }
    .btn-skip {
      background: transparent; color: var(--text-3);
      text-decoration: underline; padding: 11px 8px;
      border: none; font-family: inherit; font-size: 14px; cursor: pointer;
    }
    .err {
      background: rgba(226,75,74,0.12); color: #f39999;
      border: 1px solid rgba(226,75,74,0.3);
      border-radius: var(--r); padding: 10px 14px;
      font-size: 13px; margin-bottom: 16px; display: none;
    }
    .err.show { display: block; }
    @media (max-width: 900px) {
      .stepnav { grid-template-columns: repeat(4, 1fr); }
      .stepnav .step-pill:nth-child(n+5) { display: none; }
    }
    @yield('extra-styles')
  </style>
</head>
<body>

<div class="shell">

  <div class="top">
    <div>
      <div class="brand"><span class="brand-mark">I</span> intake</div>
      <div class="doctitle">Setting up {{ $tenant->name }}</div>
    </div>
  </div>

  @php
    $steps = [
      ['slug' => 'industry', 'name' => 'Industry'],
      ['slug' => 'identity', 'name' => 'Identity'],
      ['slug' => 'booking',  'name' => 'Booking'],
      ['slug' => 'hours',    'name' => 'Hours'],
      ['slug' => 'services', 'name' => 'Services'],
      ['slug' => 'team',     'name' => 'Team'],
      ['slug' => 'payment',  'name' => 'Payment'],
      ['slug' => 'done',     'name' => 'Done'],
    ];
  @endphp

  <nav class="stepnav">
    @foreach($steps as $i => $step)
      @php
        $stepNum = $i + 1;
        $cls = 'step-pill';
        if ($stepNum === $currentStep) $cls .= ' active';
        elseif ($stepNum < $currentStep) $cls .= ' done';
      @endphp
      <a href="{{ route('tenant.onboarding.wizard.' . $step['slug'], []) }}"
         class="{{ $cls }}">
        <div class="step-num">{{ str_pad($stepNum, 2, '0', STR_PAD_LEFT) }}</div>
        <div class="step-name">{{ $step['name'] }}</div>
      </a>
    @endforeach
  </nav>

  <div class="screen">
    @yield('screen')
  </div>

</div>

@yield('scripts')

</body>
</html>
