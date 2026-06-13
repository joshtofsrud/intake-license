{{-- MARKER-PATCH-263 — blueprint-driven mini-site preview. Renders the
     template's $layout (ordered blocks) using its $tokens, so each template
     draws its own SHAPE, not just its own colours. Same markup serves the
     card crop and the big modal (block CSS lives in templates/index). --}}
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
  $hStyle = "font-family:'{$fHead}',sans-serif;font-weight:{$hWeight};text-transform:{$hCase}";
  $shop   = $currentTenant->name ?? 'Your Business';
  $blocks = $layout ?? [];
@endphp
<div class="fs" style="background:{{ $bg }};color:{{ $text }};font-family:'{{ $fBody }}',sans-serif">

  <div class="fs-nav" style="border-bottom:.5px solid {{ $surface }}">
    <span class="fs-logo" style="{{ $hStyle }};color:{{ $accent }}">{{ $shop }}</span>
    <span style="color:{{ $muted }}">Services</span>
    <span style="color:{{ $muted }}">About</span>
    <span style="color:{{ $muted }}">Contact</span>
    <span class="fs-btn" style="{{ $btn }};border-radius:{{ $radius }}px">Book</span>
  </div>

  @foreach($blocks as $b)
    @php
      $type = $b['type'] ?? '';
      $var  = $b['variant'] ?? '';
      $h    = $b['h'] ?? '';
      $sub  = $b['sub'] ?? '';
      $cta  = $b['cta'] ?? 'Book now';
    @endphp
    @switch($type)

      @case('hero')
        <div class="fs-hero fs-hero--{{ $var ?: 'fullbleed' }}" style="background:{{ $heroBg }};color:{{ $heroText }}">
          @if($var === 'split')
            <div class="fs-hero-copy">
              <div class="fs-eyebrow" style="color:{{ $accent }}">Now booking</div>
              <h1 style="{{ $hStyle }}">{{ $h }}</h1>
              <p style="opacity:.7">{{ $sub }}</p>
              <span class="fs-btn" style="{{ $btn }};border-radius:{{ $radius }}px">{{ $cta }} →</span>
            </div>
            <div class="fs-hero-img" style="background:{{ $surface }}"></div>
          @else
            <div class="fs-eyebrow" style="color:{{ $accent }}">Now booking</div>
            <h1 style="{{ $hStyle }}">{{ $h }}</h1>
            <p style="opacity:.7">{{ $sub }}</p>
            <span class="fs-btn" style="{{ $btn }};border-radius:{{ $radius }}px">{{ $cta }} →</span>
          @endif
        </div>
        @break

      @case('cta')
        <div class="fs-cta" style="background:{{ $surface }}">
          <div class="fs-sec-h" style="{{ $hStyle }}">{{ $h ?: 'Ready when you are.' }}</div>
          <span class="fs-btn" style="{{ $btn }};border-radius:{{ $radius }}px">{{ $cta }}</span>
        </div>
        @break

      @case('text_image')
        <div class="fs-ti">
          <div class="fs-ti-copy">
            <div class="fs-sec-h" style="{{ $hStyle }}">{{ $h ?: 'Our approach' }}</div>
            <p style="color:{{ $muted }}">{{ $sub ?: 'Thoughtful work, every time.' }}</p>
          </div>
          <div class="fs-ti-img" style="background:{{ $surface }}"></div>
        </div>
        @break

      @case('gallery')
        <div class="fs-gallery">
          <div style="background:{{ $surface }}"></div><div style="background:{{ $surface }}"></div>
          <div style="background:{{ $surface }}"></div><div style="background:{{ $surface }}"></div>
        </div>
        @break

      @case('testimonial')
        <div class="fs-quote" style="background:{{ $surface }};color:{{ $muted }}">{{ $sub ?: '“A great experience, every time.”' }}</div>
        @break

      @case('stats')
        <div class="fs-stats">
          @for($i=0;$i<3;$i++)
            <div class="fs-stat"><div class="fs-stat-n" style="color:{{ $accent }};{{ $hStyle }}">★</div><div class="fs-stat-l" style="color:{{ $muted }}">Stat</div></div>
          @endfor
        </div>
        @break

      @case('steps')
        <div class="fs-sec">
          <div class="fs-sec-h" style="{{ $hStyle }}">{{ $h ?: 'How it works' }}</div>
          <div class="fs-steps">
            @for($i=1;$i<=3;$i++)
              <div class="fs-step"><span style="background:{{ $accent }};color:{{ $accentText }}">{{ $i }}</span><i style="background:{{ $surface }}"></i></div>
            @endfor
          </div>
        </div>
        @break

      @case('services')
        <div class="fs-sec">
          <div class="fs-sec-h" style="{{ $hStyle }}">{{ $h ?: 'What we offer' }}</div>
          <div class="fs-list">
            @for($i=0;$i<4;$i++)
              <div class="fs-list-row" style="border-bottom:.5px solid {{ $surface }}"><span style="background:{{ $surface }}"></span><b style="background:{{ $surface }}"></b></div>
            @endfor
          </div>
        </div>
        @break

      @case('feature')
        <div class="fs-sec">
          <div class="fs-sec-h" style="{{ $hStyle }}">{{ $h ?: 'Everything you need' }}</div>
          <div class="fs-cards"><div style="background:{{ $surface }}"></div><div style="background:{{ $surface }}"></div><div style="background:{{ $surface }}"></div></div>
        </div>
        @break

      @case('faq')
        <div class="fs-sec">
          <div class="fs-sec-h" style="{{ $hStyle }}">{{ $h ?: 'Good to know' }}</div>
          <div class="fs-faq">
            @for($i=0;$i<3;$i++)
              <div class="fs-faq-row" style="border-top:.5px solid {{ $surface }}"><span style="background:{{ $surface }}"></span></div>
            @endfor
          </div>
        </div>
        @break

      @case('contact')
        <div class="fs-sec">
          <div class="fs-sec-h" style="{{ $hStyle }}">{{ $h ?: 'Come say hi' }}</div>
          <div class="fs-contact">
            <div class="fs-input" style="background:{{ $surface }}"></div>
            <div class="fs-input" style="background:{{ $surface }}"></div>
            <span class="fs-btn" style="{{ $btn }};border-radius:{{ $radius }}px">Send</span>
          </div>
        </div>
        @break

      @case('footer')
        <div class="fs-footer" style="background:{{ $surface }};color:{{ $muted }}">{{ $shop }} · Open daily</div>
        @break

    @endswitch
  @endforeach
</div>
