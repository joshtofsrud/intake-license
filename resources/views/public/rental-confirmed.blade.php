<!DOCTYPE html>
{{-- reservation confirmation, inside the shop's own header and footer.
     MARKER-RENTAL-PAGES-THEME: styles come from public._rental-theme. --}}
@php
  $tname  = $currentTenant->name ?? 'Rentals';
  $paid   = (int) $rental->paid_cents >= (int) $rental->total_cents && (int) $rental->total_cents > 0;
@endphp
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
{!! \App\Support\SiteLook::schemeScript($currentTenant ?? tenant()) !!}
@include('partials.mobile-input-zoom')
<title>Reserved — {{ $tname }}</title>
@include('public._theme-fonts')
@include('public._rental-theme')
<style>
  .rc-tick { width: 60px; height: 60px; border-radius: 50%; background: var(--p-accent); color: var(--p-accent-text); display: grid; place-items: center; margin: 0 auto 18px; }
  .rc-num { font-family: ui-monospace, SFMono-Regular, Menlo, monospace; font-size: 14px; color: var(--rt-muted); margin-top: 6px; }
</style>
  @include('public._ga4')
  @include('public._site-scripts', ['ssPart' => 'head']) {{-- MARKER-SITE-SCRIPTS --}}
</head>
<body>
@include('public._chrome-inline', ['chromePos' => 'top'])
<div class="rt-wrap narrow" style="text-align:center">
  <div class="rc-tick" aria-hidden="true"><svg width="26" height="26" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.6" stroke-linecap="round" stroke-linejoin="round"><polyline points="5 12.5 10 17.5 19 7"/></svg></div>
  <h1 class="rt-h1">You're reserved</h1>
  <div class="rc-num">{{ $rental->rental_number }}</div>

  <div class="rt-card" style="margin-top:26px;text-align:left">
    @foreach($rental->lines->where('kind', 'unit') as $line)
      <div class="rt-kv"><span>Rental</span><span>{{ $line->name_snapshot }}</span></div>
    @endforeach
    <div class="rt-kv"><span>Pickup</span><span>{{ tlocal_datetime($rental->starts_at, 'D M j, g:i A') }}</span></div>
    <div class="rt-kv"><span>Return by</span><span>{{ tlocal_datetime($rental->due_at, 'D M j, g:i A') }}</span></div>
    <div class="rt-kv total"><span style="color:var(--p-text)">{{ $paid ? 'Paid' : 'Due at pickup' }}</span><span>{{ format_money($paid ? $rental->paid_cents : $rental->total_cents) }}</span></div>
  </div>

  <p class="rt-note" style="margin-top:18px">Bring photo ID at pickup. Need to change anything? Get in touch — your reservation number is above.</p>
  <div style="display:flex;gap:10px;justify-content:center;flex-wrap:wrap;margin-top:24px">
    <a class="rt-btn" href="/">Back to {{ $tname }}</a>
    <a class="rt-btn ghost" href="/account">Your account</a>
  </div>
</div>
@include('public._chrome-inline', ['chromePos' => 'bottom'])
@include('public._site-scripts', ['ssPart' => 'body']) {{-- MARKER-SITE-SCRIPTS --}}
</body>
</html>
