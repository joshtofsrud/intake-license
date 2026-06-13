{{-- MARKER-PATCH-261 — registry-driven mini-site preview. Fed a $tokens
     bundle (from App\Support\SiteTemplate) it renders a nav + hero + card
     row using ONLY those tokens, so a card thumbnail and the big preview can
     never drift from the real template. $scale lets the same markup serve a
     small card and a large modal. --}}
@php
  $t = $tokens;
  $accent   = $t['accent'] ?? '#BEF264';
  $bg       = $t['bg'] ?? '#ffffff';
  $text     = $t['text'] ?? '#111';
  $surface  = $t['surface'] ?? '#f2f2f2';
  $muted    = $t['muted'] ?? '#777';
  $heroBg   = $t['hero_bg'] ?? $bg;
  $heroText = $t['hero_text'] ?? $text;
  $radius   = (int)($t['button_radius'] ?? 8);
  $btnStyle = $t['button_style'] ?? 'solid';
  $hWeight  = (int)($t['heading_weight'] ?? 700);
  $hCase    = $t['heading_transform'] ?? 'none';
  $fHead    = $t['font_heading'] ?? 'Inter';
  $fBody    = $t['font_body'] ?? 'Inter';
  $accentText = \App\Support\ColorHelper::accentTextColor($accent);
  $btn = $btnStyle === 'outline'
      ? "background:transparent;border:1.5px solid {$accent};color:{$text}"
      : "background:{$accent};border:1.5px solid {$accent};color:{$accentText}";
@endphp
<div class="fs" style="background:{{ $bg }};color:{{ $text }};font-family:'{{ $fBody }}',sans-serif">
  <div class="fs-nav" style="border-bottom:.5px solid {{ $surface }}">
    <span class="fs-logo" style="font-family:'{{ $fHead }}',sans-serif;font-weight:{{ $hWeight }};color:{{ $accent }};text-transform:{{ $hCase }}">{{ $currentTenant->name ?? 'Your Shop' }}</span>
    <span style="color:{{ $muted }}">Services</span>
    <span style="color:{{ $muted }}">About</span>
    <span style="color:{{ $muted }}">Contact</span>
    <span class="fs-btn" style="{{ $btn }};border-radius:{{ $radius }}px">Book</span>
  </div>
  <div class="fs-hero" style="background:{{ $heroBg }};color:{{ $heroText }}">
    <div class="fs-eyebrow" style="color:{{ $accent }}">Now booking</div>
    <h1 style="font-family:'{{ $fHead }}',sans-serif;font-weight:{{ $hWeight }};text-transform:{{ $hCase }}">Great service, made simple.</h1>
    <p style="color:{{ $heroText }};opacity:.7">Friendly service and easy online booking — see you soon.</p>
    <span class="fs-btn" style="{{ $btn }};border-radius:{{ $radius }}px">Book now →</span>
  </div>
  <div class="fs-sec">
    <div class="fs-sec-h" style="font-family:'{{ $fHead }}',sans-serif;font-weight:{{ $hWeight }};text-transform:{{ $hCase }}">What we do</div>
    <div class="fs-cards">
      <div style="background:{{ $surface }}"></div>
      <div style="background:{{ $surface }}"></div>
      <div style="background:{{ $surface }}"></div>
    </div>
  </div>
</div>
