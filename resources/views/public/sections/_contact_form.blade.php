{{-- MARKER-PATCH-158-G24 — contact_form public renderer (v2) --}}
@php
  $c = $c ?? [];

  // Layout
  $widthMap = ['narrow'=>'440px','medium'=>'580px','wide'=>'720px','full'=>'100%'];
  $formWidth = $widthMap[$c['form_width'] ?? 'medium'] ?? '580px';
  $hAlign = $c['text_align'] ?? 'center';

  $padTokens = ['none'=>'0','compact'=>'40px','normal'=>'80px','spacious'=>'120px'];
  $padTop    = $padTokens[$c['padding_top']    ?? 'normal'] ?? '80px';
  $padBot    = $padTokens[$c['padding_bottom'] ?? 'normal'] ?? '80px';

  // Background
  $bgMode  = $c['bg_mode'] ?? 'none';
  $bgColor = $c['bg_color'] ?? '#ffffff';
  $gradF   = $c['bg_gradient_from'] ?? '#ffffff';
  $gradT   = $c['bg_gradient_to']   ?? '#fafafa';

  // Colors
  $textColor     = ($c['text_color']      ?? '') ?: '#0a0a0a';
  $textColorBody = ($c['text_color_body'] ?? '') ?: 'rgba(0,0,0,0.65)';
  $accentColor   = ($c['accent_color']    ?? '') ?: null;

  // Fields
  $showPhone   = (bool)($c['show_phone']   ?? true);
  $showMessage = (bool)($c['show_message'] ?? true);
  $labelName    = $c['label_name']    ?? 'Name';
  $labelEmail   = $c['label_email']   ?? 'Email';
  $labelPhone   = $c['label_phone']   ?? 'Phone';
  $labelMessage = $c['label_message'] ?? 'Message';
  $placeholderMessage = $c['placeholder_message'] ?? 'How can we help you?';
  $messageRows = max(2, min(20, (int)($c['message_rows'] ?? 5)));

  // Input style
  $inputStyle  = $c['input_style']  ?? 'default';   // default | minimal | filled
  $inputRadius = $c['input_radius'] ?? 'medium';
  $radiusMap   = ['none'=>'0','small'=>'4px','medium'=>'8px','large'=>'12px','pill'=>'9999px'];
  $inputRadiusVal = $radiusMap[$inputRadius] ?? '8px';

  // Button + text
  $submitLabel  = $c['submit_label']  ?? 'Send message';
  $successText  = $c['success_text']  ?? 'Thanks! We\'ll be in touch soon.';
  $privacyText  = trim($c['privacy_text'] ?? '');

  // Heading with accent
  $headingHtml = e($c['heading'] ?? '');
  $accentWords = trim($c['accent_words'] ?? '');
  if ($accentWords !== '' && stripos($headingHtml, e($accentWords)) !== false) {
      $headingHtml = str_ireplace(
          e($accentWords),
          '<span class="p-cf-accent">' . e($accentWords) . '</span>',
          $headingHtml
      );
  }

  // Advanced
  $anchorId    = trim($c['anchor_id'] ?? '');
  $customClass = trim($c['custom_classes'] ?? '');
  $hideMobile  = !empty($c['hide_on_mobile']);
  $hideDesktop = !empty($c['hide_on_desktop']);

  $instId = 'p-cf-' . ($section->id ?? uniqid());
@endphp

<style>
.{{ $instId }} {
  padding-top: {{ $padTop }};
  padding-bottom: {{ $padBot }};
  @if($bgMode === 'color') background: {{ $bgColor }};
  @elseif($bgMode === 'gradient') background: linear-gradient({{ (int)($c['bg_gradient_angle'] ?? 135) }}deg, {{ $gradF }} 0%, {{ $gradT }} 100%);
  @endif
}
.{{ $instId }} .p-cf-wrap {
  max-width: {{ $formWidth }};
  margin: 0 auto;
  padding: 0 clamp(20px, 5vw, 32px);
}
.{{ $instId }} .p-cf-head {
  text-align: {{ $hAlign }};
  margin-bottom: 32px;
  color: {{ $textColor }};
}
.{{ $instId }} .p-cf-eyebrow {
  font-family: ui-monospace, monospace;
  font-size: 11px;
  text-transform: uppercase;
  letter-spacing: 0.12em;
  color: {{ $accentColor ?? $textColor }};
  margin-bottom: 12px;
  opacity: .9;
}
.{{ $instId }} .p-cf-heading {
  font-size: clamp(22px, 3vw, 36px);
  font-weight: 500;
  letter-spacing: -.02em;
  margin: 0 0 12px;
  line-height: 1.15;
  color: {{ $textColor }};
}
.{{ $instId }} .p-cf-accent { color: {{ $accentColor ?? '#BEF264' }}; }
.{{ $instId }} .p-cf-sub {
  font-size: 16px;
  line-height: 1.55;
  color: {{ $textColorBody }};
  margin: 0;
  max-width: 540px;
  @if($hAlign === 'center') margin-left: auto; margin-right: auto; @endif
}
.{{ $instId }} .p-cf-flash {
  padding: 14px 16px;
  border-radius: {{ $inputRadiusVal }};
  font-size: 14px;
  margin-bottom: 16px;
  text-align: {{ $hAlign }};
}
.{{ $instId }} .p-cf-flash--success {
  background: rgba(48,179,84,0.08);
  color: #1f7a35;
  border: 1px solid rgba(48,179,84,0.25);
}
.{{ $instId }} .p-cf-flash--error {
  background: rgba(220,53,69,0.07);
  color: #b3232f;
  border: 1px solid rgba(220,53,69,0.25);
}

.{{ $instId }} .p-cf-form-group { margin-bottom: 16px; }
.{{ $instId }} .p-cf-form-row {
  display: grid;
  grid-template-columns: 1fr 1fr;
  gap: 14px;
}
@media (max-width: 560px) {
  .{{ $instId }} .p-cf-form-row { grid-template-columns: 1fr; }
}
.{{ $instId }} .p-cf-label {
  display: block;
  font-size: 13px;
  font-weight: 500;
  margin-bottom: 6px;
  color: {{ $textColor }};
}
.{{ $instId }} .p-cf-input {
  width: 100%;
  font-family: inherit;
  font-size: 15px;
  color: {{ $textColor }};
  padding: 11px 14px;
  border-radius: {{ $inputRadiusVal }};
  transition: all 0.15s;
  outline: none;
  @if($inputStyle === 'default')
  background: white;
  border: 1px solid rgba(0,0,0,0.15);
  @elseif($inputStyle === 'minimal')
  background: transparent;
  border: none;
  border-bottom: 1px solid rgba(0,0,0,0.2);
  border-radius: 0;
  padding-left: 0; padding-right: 0;
  @elseif($inputStyle === 'filled')
  background: rgba(0,0,0,0.04);
  border: 1px solid transparent;
  @endif
}
.{{ $instId }} .p-cf-input:focus {
  border-color: {{ $accentColor ?? '#BEF264' }};
  @if($inputStyle === 'minimal')
  border-bottom-color: {{ $accentColor ?? '#BEF264' }};
  @endif
}
.{{ $instId }} textarea.p-cf-input { resize: vertical; min-height: 100px; }

.{{ $instId }} .p-cf-submit {
  width: 100%;
  display: inline-flex;
  align-items: center;
  justify-content: center;
  padding: 13px 26px;
  border-radius: {{ $inputRadiusVal }};
  font-size: 15px;
  font-weight: 500;
  cursor: pointer;
  border: 0;
  background: {{ $accentColor ?? '#0a0a0a' }};
  color: {{ $accentColor ? '#0a1a00' : '#ffffff' }};
  transition: filter 0.15s;
}
.{{ $instId }} .p-cf-submit:hover { filter: brightness(1.05); }

.{{ $instId }} .p-cf-privacy {
  font-size: 12px;
  color: {{ $textColorBody }};
  margin-top: 14px;
  text-align: {{ $hAlign }};
  opacity: .8;
}
.{{ $instId }} .p-cf-footnote {
  font-family: ui-monospace, monospace;
  font-size: 12px;
  color: {{ $textColorBody }};
  margin-top: 24px;
  text-align: {{ $hAlign }};
}

@if($hideMobile)
@media (max-width: 768px) { .{{ $instId }} { display: none; } }
@endif
@if($hideDesktop)
@media (min-width: 769px) { .{{ $instId }} { display: none; } }
@endif
</style>

<section class="{{ $instId }} p-contact-form {{ $customClass }}" @if($anchorId) id="{{ $anchorId }}" @endif>
  <div class="p-cf-wrap">

    @if(!empty($c['eyebrow']) || !empty($c['heading']) || !empty($c['subheading']))
      <div class="p-cf-head">
        @if(!empty($c['eyebrow']))
          <div class="p-cf-eyebrow">{{ $c['eyebrow'] }}</div>
        @endif
        @if(!empty($c['heading']))
          <h2 class="p-cf-heading">{!! $headingHtml !!}</h2>
        @endif
        @if(!empty($c['subheading']))
          <p class="p-cf-sub">{{ $c['subheading'] }}</p>
        @endif
      </div>
    @endif

    @if(session('contact_success'))
      <div class="p-cf-flash p-cf-flash--success">{{ $successText }}</div>
    @endif

    <form method="POST" action="/contact">
      @csrf
      {{-- MARKER-CONTACT-SPAM — the honeypot the controller has always
           checked for. This form never rendered it, so PATCH-399's check
           did nothing here; the footer form had it all along. --}}
      <input type="text" name="company_website" tabindex="-1" autocomplete="off" aria-hidden="true"
             style="position:absolute !important;left:-9999px !important;top:auto;width:1px;height:1px;opacity:0;pointer-events:none">
      {{-- Signed render time: a submission that arrives in under 3 seconds
           wasn't typed by a person. --}}
      <input type="hidden" name="form_started_at" value="{{ encrypt(time()) }}">

      @if($errors->any())
        <div class="p-cf-flash p-cf-flash--error">{{ $errors->first() }}</div>
      @endif

      <div class="p-cf-form-group">
        <label class="p-cf-label">{{ $labelName }} *</label>
        {{-- MARKER-CONTACT-NAMES — split into first and last, both required --}}
        <input type="text" name="first_name" class="p-cf-input" value="{{ old('first_name') }}" required placeholder="First name">
        <input type="text" name="last_name" class="p-cf-input" value="{{ old('last_name') }}" required placeholder="Last name" style="margin-top:8px">
      </div>

      @if($showPhone)
        <div class="p-cf-form-row">
          <div class="p-cf-form-group">
            <label class="p-cf-label">{{ $labelEmail }} *</label>
            <input type="email" name="email" class="p-cf-input" value="{{ old('email') }}" required placeholder="">
          </div>
          <div class="p-cf-form-group">
            <label class="p-cf-label">{{ $labelPhone }}</label>
            <input type="tel" name="phone" class="p-cf-input" value="{{ old('phone') }}" placeholder="">
          </div>
        </div>
      @else
        <div class="p-cf-form-group">
          <label class="p-cf-label">{{ $labelEmail }} *</label>
          <input type="email" name="email" class="p-cf-input" value="{{ old('email') }}" required placeholder="">
        </div>
      @endif

      @if($showMessage)
        <div class="p-cf-form-group">
          <label class="p-cf-label">{{ $labelMessage }} *</label>
          <textarea name="message" class="p-cf-input" rows="{{ $messageRows }}" required placeholder="{{ $placeholderMessage }}">{{ old('message') }}</textarea>
        </div>
      @else
        {{-- Hidden minimal placeholder so the backend's "required" validation
             on message still passes when the field is hidden. Sends a single
             space which trims to "" — actually fails validation. Need an
             actual default if hidden. --}}
        <input type="hidden" name="message" value="(no message)">
      @endif

      <button type="submit" class="p-cf-submit">{{ $submitLabel }}</button>

      @if($privacyText !== '')
        <div class="p-cf-privacy">{{ $privacyText }}</div>
      @endif
    </form>

    @if(!empty($c['note']))
      <div class="p-cf-footnote">{{ $c['note'] }}</div>
    @endif

  </div>
</section>
