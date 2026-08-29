{{-- MARKER-CONTRIBUTIONS — reachable by anyone typing the URL, so it confirms
     nothing about payment state. The receipt comes from Stripe. --}}
<!DOCTYPE html>
<html lang="en"><head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Thank you — Intake</title>
<meta name="robots" content="noindex, nofollow">
<link rel="icon" href="{{ asset('favicon.svg') }}" type="image/svg+xml">
<link rel="stylesheet" href="{{ asset('css/fonts.css') }}">
@include('invest._styles')
</head><body>

<nav><div class="wrap">
  <a class="brand" href="/"><img src="{{ asset('icon.svg') }}" alt="" width="26" height="26"> intake</a>
</div></nav>

<section class="hero"><div class="wrap">
  <span class="eyebrow">Thank you</span>
  <h1>That means a lot.</h1>
  <p class="lede">Stripe will email you a receipt. Your contribution backs the work and buys nothing —
    no equity, no ownership, no return — which is exactly what you chose, and I'm grateful for it.</p>
  <p class="fine">Questions, or want to talk about the round instead?
    <a href="{{ route('marketing.invest') }}" style="color:var(--lime)">Back to the page</a>.</p>
</div></section>

<footer><div class="wrap">intake · intake.works</div></footer>
</body></html>
