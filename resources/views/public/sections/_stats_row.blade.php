{{-- MARKER-PATCH-158-G27 — stats_row public renderer (v2) --}}
@php
  $c = $c ?? [];

  // Stats normalization
  $stats = $c['stats'] ?? [];
  if (is_string($stats)) { $d = json_decode($stats, true); $stats = is_array($d) ? $d : []; }
  if (!is_array($stats)) $stats = [];

  // Filter out empty rows (no number AND no label)
  $stats = array_values(array_filter($stats, fn($s) =>
      trim($s['number'] ?? '') !== '' || trim($s['label'] ?? '') !== ''
  ));

  // Layout
  $colsSetting = $c['columns'] ?? 'auto';
  $count = max(1, count($stats));
  $cols = $colsSetting === 'auto' ? min($count, 4) : (int)$colsSetting;
  if ($cols < 1) $cols = 1;

  $numberSize = $c['number_size'] ?? 'large';
  $numberSizeMap = [
      'medium' => 'clamp(28px, 4vw, 40px)',
      'large'  => 'clamp(36px, 5vw, 56px)',
      'huge'   => 'clamp(48px, 7vw, 80px)',
  ];
  $numberFs = $numberSizeMap[$numberSize] ?? $numberSizeMap['large'];

  $statsAlign = $c['stats_align'] ?? 'center';
  $hAlign     = $c['text_align']  ?? 'center';
  $divider    = $c['divider']     ?? 'none';

  $padTokens = ['none'=>'0','compact'=>'40px','normal'=>'80px','spacious'=>'120px'];
  $padTop    = $padTokens[$c['padding_top']    ?? 'normal'] ?? '80px';
  $padBot    = $padTokens[$c['padding_bottom'] ?? 'normal'] ?? '80px';

  // Background
  $bgMode  = $c['bg_mode']  ?? 'none';
  $bgColor = $c['bg_color'] ?? '#ffffff';
  $gradF   = $c['bg_gradient_from'] ?? '#ffffff';
  $gradT   = $c['bg_gradient_to']   ?? '#fafafa';

  // Colors
  $textColor     = ($c['text_color']      ?? '') ?: '#0a0a0a';
  $textColorBody = ($c['text_color_body'] ?? '') ?: 'rgba(0,0,0,0.6)';
  $accentColor   = ($c['accent_color']    ?? '') ?: null;

  // Heading with accent
  $headingHtml = e($c['heading'] ?? '');
  $accentWords = trim($c['accent_words'] ?? '');
  if ($accentWords !== '' && stripos($headingHtml, e($accentWords)) !== false) {
      $headingHtml = str_ireplace(
          e($accentWords),
          '<span class="p-stats-accent">' . e($accentWords) . '</span>',
          $headingHtml
      );
  }

  // Advanced
  $anchorId    = trim($c['anchor_id'] ?? '');
  $customClass = trim($c['custom_classes'] ?? '');
  $hideMobile  = !empty($c['hide_on_mobile']);
  $hideDesktop = !empty($c['hide_on_desktop']);

  $instId = 'p-stats-' . ($section->id ?? uniqid());
@endphp

<style>
.{{ $instId }} {
  padding-top: {{ $padTop }};
  padding-bottom: {{ $padBot }};
  @if($bgMode === 'color') background: {{ $bgColor }};
  @elseif($bgMode === 'gradient') background: linear-gradient({{ (int)($c['bg_gradient_angle'] ?? 135) }}deg, {{ $gradF }} 0%, {{ $gradT }} 100%);
  @endif
}
.{{ $instId }} .p-stats-wrap {
  max-width: {{ (int)($c['content_max_width'] ?? 1200) }}px;
  margin: 0 auto;
  padding: 0 clamp(20px, 5vw, 48px);
}
.{{ $instId }} .p-stats-head {
  text-align: {{ $hAlign }};
  margin-bottom: 48px;
  color: {{ $textColor }};
}
.{{ $instId }} .p-stats-eyebrow {
  font-family: ui-monospace, monospace;
  font-size: 11px;
  text-transform: uppercase;
  letter-spacing: 0.12em;
  color: {{ $accentColor ?? $textColor }};
  margin-bottom: 12px;
  opacity: .9;
}
.{{ $instId }} .p-stats-heading {
  font-size: clamp(22px, 3vw, 36px);
  font-weight: 500;
  letter-spacing: -.02em;
  margin: 0 0 12px;
  line-height: 1.15;
  color: {{ $textColor }};
}
.{{ $instId }} .p-stats-accent { color: {{ $accentColor ?? '#BEF264' }}; }
.{{ $instId }} .p-stats-sub {
  font-size: 16px;
  line-height: 1.55;
  color: {{ $textColorBody }};
  margin: 0;
  max-width: 640px;
  @if($hAlign === 'center') margin-left: auto; margin-right: auto; @endif
}

.{{ $instId }} .p-stats-grid {
  display: grid;
  grid-template-columns: repeat({{ $cols }}, 1fr);
  gap: 32px;
  @if($divider === 'line') gap: 0; @endif
}
.{{ $instId }} .p-stats-cell {
  text-align: {{ $statsAlign }};
  padding: 0 16px;
  @if($divider === 'line')
  border-left: 1px solid rgba(0,0,0,0.08);
  @endif
}
.{{ $instId }} .p-stats-cell:first-child {
  @if($divider === 'line') border-left: 0; padding-left: 0; @endif
}
@if($divider === 'dot')
.{{ $instId }} .p-stats-cell { position: relative; }
.{{ $instId }} .p-stats-cell + .p-stats-cell::before {
  content: '';
  position: absolute;
  left: -16px; top: 50%;
  width: 4px; height: 4px;
  border-radius: 50%;
  background: {{ $textColorBody }};
  opacity: .4;
}
@endif

.{{ $instId }} .p-stats-number {
  font-size: {{ $numberFs }};
  font-weight: 600;
  letter-spacing: -.03em;
  line-height: 1;
  color: {{ $accentColor ?? $textColor }};
  margin: 0 0 10px;
}
.{{ $instId }} .p-stats-label {
  font-size: 14px;
  font-weight: 500;
  color: {{ $textColor }};
  margin: 0;
  letter-spacing: .01em;
}
.{{ $instId }} .p-stats-desc {
  font-size: 13px;
  color: {{ $textColorBody }};
  margin: 6px 0 0;
  line-height: 1.5;
}

@media (max-width: 720px) {
  .{{ $instId }} .p-stats-grid { grid-template-columns: repeat({{ min($cols, 2) }}, 1fr); gap: 24px; }
  .{{ $instId }} .p-stats-cell { border-left: 0 !important; padding-left: 0 !important; }
  .{{ $instId }} .p-stats-cell::before { display: none !important; }
}
@media (max-width: 480px) {
  .{{ $instId }} .p-stats-grid { grid-template-columns: 1fr; }
}

@if($hideMobile)
@media (max-width: 768px) { .{{ $instId }} { display: none; } }
@endif
@if($hideDesktop)
@media (min-width: 769px) { .{{ $instId }} { display: none; } }
@endif
</style>

<section class="{{ $instId }} p-stats-row {{ $customClass }}" @if($anchorId) id="{{ $anchorId }}" @endif>
  <div class="p-stats-wrap">

    @if(!empty($c['eyebrow']) || !empty($c['heading']) || !empty($c['subheading']))
      <div class="p-stats-head">
        @if(!empty($c['eyebrow']))
          <div class="p-stats-eyebrow">{{ $c['eyebrow'] }}</div>
        @endif
        @if(!empty($c['heading']))
          <h2 class="p-stats-heading">{!! $headingHtml !!}</h2>
        @endif
        @if(!empty($c['subheading']))
          <p class="p-stats-sub">{{ $c['subheading'] }}</p>
        @endif
      </div>
    @endif

    @if(!empty($stats))
      <div class="p-stats-grid">
        @foreach($stats as $s)
          <div class="p-stats-cell">
            @if(!empty($s['number']))
              <div class="p-stats-number">{{ $s['number'] }}</div>
            @endif
            @if(!empty($s['label']))
              <div class="p-stats-label">{{ $s['label'] }}</div>
            @endif
            @if(!empty($s['description']))
              <div class="p-stats-desc">{{ $s['description'] }}</div>
            @endif
          </div>
        @endforeach
      </div>
    @endif

  </div>
</section>
