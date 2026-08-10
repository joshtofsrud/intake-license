{{-- MARKER-PATCH-263 — blueprint-driven mini-site preview. Renders the
     template's $layout (ordered blocks) using its $tokens, so each template
     draws its own SHAPE, not just its own colours. Same markup serves the
     card crop and the big modal (block CSS lives in templates/index). --}}
@php
  /* MARKER-CUSTOMIZER — every value is now a CSS variable read off this
     preview's own root, so the same markup serves the static template cards
     and the live customizer (which repaints by setting those variables).
     $tokenVars below is what declares them. */
  $t = $tokens;
  $rawAccent = $t['accent'] ?? '#BEF264';

  $tokenVars = implode(';', [
      '--t-accent: '      . $rawAccent,
      '--t-accent-text: ' . \App\Support\ColorHelper::accentTextColor($rawAccent),
      '--t-bg: '          . ($t['bg'] ?? '#ffffff'),
      '--t-text: '        . ($t['text'] ?? '#111'),
      '--t-surface: '     . ($t['surface'] ?? '#f2f2f2'),
      '--t-muted: '       . ($t['muted'] ?? '#777'),
      '--t-hero-bg: '     . ($t['hero_bg'] ?? $t['bg'] ?? '#ffffff'),
      '--t-hero-text: '   . ($t['hero_text'] ?? $t['text'] ?? '#111'),
      '--t-btn-r: '       . (int) ($t['button_radius'] ?? 8) . 'px',
      "--t-f-head: '"     . ($t['font_heading'] ?? 'Inter') . "', sans-serif",
      "--t-f-body: '"     . ($t['font_body'] ?? 'Inter') . "', sans-serif",
      '--t-h-weight: '    . (int) ($t['heading_weight'] ?? 700),
      '--t-h-case: '      . ($t['heading_transform'] ?? 'none'),
  ]);

  $accent   = 'var(--t-accent)';
  $bg       = 'var(--t-bg)';
  $text     = 'var(--t-text)';
  $surface  = 'var(--t-surface)';
  $muted    = 'var(--t-muted)';
  $heroBg   = 'var(--t-hero-bg)';
  $heroText = 'var(--t-hero-text)';
  $radius   = 'var(--t-btn-r)';
  $accentText = 'var(--t-accent-text)';

  /* Button style can't be a variable (it changes which properties apply), so
     it stays server-rendered; the customizer swaps a class instead. */
  $btnStyle = $t['button_style'] ?? 'solid';
  $btn = $btnStyle === 'outline'
      ? 'background:transparent;border:1.5px solid var(--t-accent);color:var(--t-text)'
      : ($btnStyle === 'ghost'
          ? 'background:var(--t-surface);border:1.5px solid transparent;color:var(--t-text)'
          : 'background:var(--t-accent);border:1.5px solid var(--t-accent);color:var(--t-accent-text)');

  $hStyle = 'font-family:var(--t-f-head);font-weight:var(--t-h-weight);text-transform:var(--t-h-case)';
  $shop   = $currentTenant->name ?? 'Your Business';
  $blocks = $layout ?? [];
@endphp
<div class="fs" data-fs-preview style="{{ $tokenVars }};background:var(--t-bg);color:var(--t-text);font-family:var(--t-f-body)">

  <div class="fs-nav" style="border-bottom:.5px solid {{ $surface }}">
    <span class="fs-logo" style="{{ $hStyle }};color:{{ $accent }}">{{ $shop }}</span>
    <span style="color:{{ $muted }}">Services</span>
    <span style="color:{{ $muted }}">About</span>
    <span style="color:{{ $muted }}">Contact</span>
    <span class="fs-btn" style="{{ $btn }};border-radius:{{ $radius }}">Book</span>
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
              <span class="fs-btn" style="{{ $btn }};border-radius:{{ $radius }}">{{ $cta }} →</span>
            </div>
            <div class="fs-hero-img" style="background:{{ $surface }}"></div>
          @else
            <div class="fs-eyebrow" style="color:{{ $accent }}">Now booking</div>
            <h1 style="{{ $hStyle }}">{{ $h }}</h1>
            <p style="opacity:.7">{{ $sub }}</p>
            <span class="fs-btn" style="{{ $btn }};border-radius:{{ $radius }}">{{ $cta }} →</span>
          @endif
        </div>
        @break

      @case('cta')
        <div class="fs-cta" style="background:{{ $surface }}">
          <div class="fs-sec-h" style="{{ $hStyle }}">{{ $h ?: 'Ready when you are.' }}</div>
          <span class="fs-btn" style="{{ $btn }};border-radius:{{ $radius }}">{{ $cta }}</span>
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
            <span class="fs-btn" style="{{ $btn }};border-radius:{{ $radius }}">Send</span>
          </div>
        </div>
        @break

      @case('footer')
        <div class="fs-footer" style="background:{{ $surface }};color:{{ $muted }}">{{ $shop }} · Open daily</div>
        @break

    @endswitch
  @endforeach
</div>
