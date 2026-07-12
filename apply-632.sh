#!/bin/bash
set -e
cd "$(git rev-parse --show-toplevel)"
cat > "resources/views/public/booking-choice.blade.php" <<'PATCH632_EOF'
@extends('public._booking-shell')
@php
  // MARKER-PATCH-598 — choice fork now extends _booking-shell. Theme/color vars
  // come from the shell; keep view-local bits + recompute the theme flag for pushed CSS.
  $pageTitle = 'Book online';
  $showBackLink = true;
  $isDark = (($bk['theme'] ?? 'light') === 'dark'); // .fcard:hover shadow uses it
@endphp

@push('styles')
<style>
  /* choice fork uses a rounder radius scale than the shell default */
  :root { --p-r: 10px; --p-r-lg: 16px; }
    .wrap{ max-width:760px; margin:0 auto; padding:32px 20px 60px; }
    .fork-intro{ text-align:center; margin-bottom:34px; }
    .fork-intro h1{ font-family:var(--p-font-heading); font-size:clamp(24px,5vw,32px); font-weight:700; letter-spacing:-.02em; margin-bottom:10px; }
    .fork-intro p{ font-size:15px; color:var(--p-muted); max-width:440px; margin:0 auto; line-height:1.55; }
    .fork{ display:flex; gap:16px; flex-wrap:wrap; }
    .fcard{ flex:1 1 280px; background:var(--p-card); border:1px solid var(--p-border); border-radius:var(--p-r-lg); padding:26px 24px; text-decoration:none; color:inherit; display:flex; flex-direction:column; transition:transform .12s, border-color .12s, box-shadow .12s; }
    .fcard:hover{ transform:translateY(-2px); border-color:var(--p-accent); box-shadow:0 10px 30px rgba(0,0,0,{{ $isDark ? '.4' : '.08' }}); }
    .fcard.lead{ border-color:var(--p-accent); }
    .ficon{ width:46px; height:46px; border-radius:12px; background:color-mix(in srgb, var(--p-accent) 16%, transparent); display:flex; align-items:center; justify-content:center; color:var(--p-accent); margin-bottom:18px; }
    .ficon svg{ width:24px; height:24px; }
    .fcard h2{ font-family:var(--p-font-heading); font-size:19px; font-weight:600; margin-bottom:7px; }
    .fcard p{ font-size:13.5px; color:var(--p-muted); line-height:1.5; flex:1; }
    .fmeta{ margin-top:16px; font-size:12px; font-weight:600; color:var(--p-muted); display:flex; align-items:center; gap:8px; }
    .fgo{ margin-top:18px; display:inline-flex; align-items:center; gap:7px; font-size:13px; font-weight:600; color:var(--p-accent); }
    .fnote{ text-align:center; margin-top:26px; font-size:12.5px; color:var(--p-muted); }
    @media (max-width:560px){ .fork{ flex-direction:column; } }
</style>
@endpush

@section('content')
  <div class="wrap">

    <div class="fork-intro">
      <h1>How would you like to book?</h1>
      <p>Pick what fits your visit — you can switch anytime.</p>
    </div>

    <div class="fork">
      <a class="fcard lead" href="{{ url('/book?flow=quick') }}" data-flow="quick">
        <div class="ficon">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><polygon points="13 2 3 14 12 14 11 22 21 10 12 10 13 2"/></svg>
        </div>
        <h2>Quick booking</h2>
        <p>Pick from our service menu and grab a time. Best for a single, standard job.</p>
        <div class="fmeta">3 steps · about a minute</div>
        <span class="fgo">Start quick
          <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M5 12h14M13 6l6 6-6 6"/></svg>
        </span>
      </a>

      <a class="fcard" href="{{ url('/book?flow=full') }}" data-flow="full">
        <div class="ficon" style="color:var(--p-muted);background:color-mix(in srgb, var(--p-text) 8%, transparent);">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M14.7 6.3a4 4 0 0 0-5.4 5.4L3 18v3h3l6.3-6.3a4 4 0 0 0 5.4-5.4l-2.6 2.6-2-2 2.6-2.6z"/></svg>
        </div>
        <h2>Full setup</h2>
        <p>Add each item, choose services per item, and review everything before you book.</p>
        <div class="fmeta">6 steps · full control</div>
        <span class="fgo" style="color:var(--p-muted);">Start full
          <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M5 12h14M13 6l6 6-6 6"/></svg>
        </span>
      </a>
    </div>

    <div class="fnote">Not sure? Quick booking covers most visits — you can always start over.</div>
  </div>
@endsection

@push('scripts')
  <script>
    // Record which path the customer chose (anonymous funnel signal).
    document.querySelectorAll('.fcard').forEach(function(el){
      el.addEventListener('click', function(){
        try {
          if (navigator.sendBeacon) {
            // MARKER-PATCH-632 — choosing a flow IS starting a booking; keeps the
            // "Bookings started" tile consistent with the funnel steps.
            navigator.sendBeacon('/funnel/track', new Blob([JSON.stringify({event_type:'booking_started'})], {type:'application/json'}));
            navigator.sendBeacon('/funnel/track', new Blob([JSON.stringify({event_type:'booking_step', step:'00 Chose ' + (el.dataset.flow === 'quick' ? 'Quick' : 'Full')})], {type:'application/json'}));
          }
        } catch(e){}
      });
    });
  </script>
@endpush
PATCH632_EOF
echo "patch-632 applied: booking_started fires from choice fork"
