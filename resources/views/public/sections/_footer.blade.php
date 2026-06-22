{{-- MARKER-PATCH-158-G26 — footer public renderer (v2) --}}
@php
  $c = $c ?? [];

  // Layout
  $layout       = $c['layout']        ?? 'columns';
  $bottomLayout = $c['bottom_layout'] ?? 'split';
  $hAlign       = $c['text_align']    ?? 'left';

  $padTokens = ['compact'=>'40px','normal'=>'64px','spacious'=>'96px'];
  $padTop    = $padTokens[$c['padding_top']    ?? 'normal'] ?? '64px';
  $padBot    = $padTokens[$c['padding_bottom'] ?? 'normal'] ?? '40px';

  // Background
  $bgMode  = $c['bg_mode']  ?? 'color';
  $bgColor = $c['bg_color'] ?? '#0a0a0a';
  $gradF   = $c['bg_gradient_from'] ?? '#0a0a0a';
  $gradT   = $c['bg_gradient_to']   ?? '#1a1a1a';

  $borderTop = $c['border_top'] ?? 'none';

  // Colors
  $textColor  = ($c['text_color']  ?? '') ?: '#ffffff';
  $linkColor  = ($c['link_color']  ?? '') ?: 'rgba(255,255,255,0.65)';
  $mutedColor = ($c['muted_color'] ?? '') ?: 'rgba(255,255,255,0.4)';

  // Brand
  $showLogo = (bool)($c['show_logo'] ?? true);
  $tagline  = trim($c['tagline_override'] ?? '');
  if ($tagline === '' && isset($tenant)) {
      $tagline = $tenant->tagline ?? '';
  }

  $footerBg = $bgMode === 'gradient' ? $gradF : $bgColor;
  $logoUrl  = $showLogo && isset($tenant) ? \App\Support\ColorHelper::pickLogo($tenant, $footerBg) : null;

  // MARKER-PATCH-158-G28 — logo size control
  $logoSizeMap = [
      'small'  => '22px',
      'medium' => '28px',
      'large'  => '40px',
      'xl'     => '56px',
  ];
  $logoHeight = $logoSizeMap[$c['logo_size'] ?? 'medium'] ?? '28px';

  // Link columns
  $linkColumns = $c['link_columns'] ?? [];
  if (is_string($linkColumns)) { $d = json_decode($linkColumns, true); $linkColumns = is_array($d) ? $d : []; }
  if (!is_array($linkColumns)) $linkColumns = [];

  // Social links
  $socialLinks = $c['social_links'] ?? [];
  if (is_string($socialLinks)) { $d = json_decode($socialLinks, true); $socialLinks = is_array($d) ? $d : []; }
  if (!is_array($socialLinks)) $socialLinks = [];

  // MARKER-PATCH-305 — contact info is editable in the footer (email falls back
  // to the account email). Previously gated on $tenant->phone/address/hours,
  // which aren't tenant columns, so the toggles never did anything.
  $cPhone   = trim($c['contact_phone']   ?? '');
  $cEmail   = trim($c['contact_email']   ?? '') ?: ($tenant->email_from_address ?? $tenant->notification_email ?? '');
  $cAddress = trim($c['contact_address'] ?? '');
  $cHours   = trim($c['contact_hours']   ?? '');
  $showPhone   = (bool)($c['show_phone']   ?? false) && $cPhone   !== '';
  $showEmail   = (bool)($c['show_email']   ?? true)  && $cEmail   !== '';
  $showAddress = (bool)($c['show_address'] ?? false) && $cAddress !== '';
  $showHours   = (bool)($c['show_hours']   ?? false) && $cHours   !== '';
  $contactEmail = $cEmail;

  $hasContactBlock = $showPhone || $showEmail || $showAddress || $showHours;

  // Copyright with {year} + {name} templating
  $copyTpl = $c['copyright_text'] ?? '';
  if (trim($copyTpl) === '') {
      $copyTpl = '© {year} {name}. All rights reserved.';
  }
  $copyText = str_replace(
      ['{year}', '{name}'],
      [date('Y'), $tenant->name ?? ''],
      $copyTpl
  );

  // MARKER-PATCH-158-G26B — per-section "Powered by Intake" toggle restored.
  // Default true. The layout-level badge is suppressed when a footer section
  // exists (G26a), so this is the only place the badge would render on pages
  // that have a footer section.
  $showPoweredBy = (bool)($c['show_powered_by'] ?? true);

  // MARKER-PATCH-158-G29 — inline contact form
  $showForm        = (bool)($c['show_form'] ?? false);
  $formHeading     = $c['form_heading']      ?? 'Get in touch';
  $formDescription = $c['form_description']  ?? '';
  $formButton      = $c['form_button_label'] ?? 'Send';
  $formSuccess     = $c['form_success_text'] ?? "Thanks! We'll be in touch soon.";
  $formShowPhone    = (bool)($c['form_show_phone']    ?? true);   // MARKER-PATCH-394
  $formRequirePhone = (bool)($c['form_require_phone'] ?? false);

  // Advanced
  $anchorId    = trim($c['anchor_id'] ?? '');
  $customClass = trim($c['custom_classes'] ?? '');
  $hideMobile  = !empty($c['hide_on_mobile']);
  $hideDesktop = !empty($c['hide_on_desktop']);

  $instId = 'p-ftr-' . ($section->id ?? uniqid());
  // MARKER-PATCH-303B — define CTA accent vars BEFORE the <style> that uses them
  $ctaAccent  = ($tenant->accent_color ?? '') ?: '#3FD16B';
  $ctaBtnText = \App\Support\ColorHelper::accentTextColor($ctaAccent);

  // Social platform icons (simple SVG, single file)
  $iconFor = function($platform) {
      $icons = [
          'instagram' => '<svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2"><rect x="2" y="2" width="20" height="20" rx="5"/><circle cx="12" cy="12" r="4"/><circle cx="17.5" cy="6.5" r=".5" fill="currentColor"/></svg>',
          'facebook'  => '<svg viewBox="0 0 24 24" width="18" height="18" fill="currentColor"><path d="M22 12a10 10 0 1 0-11.56 9.88V14.9H8v-2.9h2.44v-2.2c0-2.4 1.43-3.74 3.62-3.74 1.05 0 2.14.19 2.14.19v2.36h-1.2c-1.2 0-1.57.74-1.57 1.5V12h2.66l-.42 2.9h-2.24v6.98A10 10 0 0 0 22 12z"/></svg>',
          'twitter'   => '<svg viewBox="0 0 24 24" width="18" height="18" fill="currentColor"><path d="M18.244 2.25h3.308l-7.227 8.26 8.502 11.24H16.17l-5.214-6.817L4.99 21.75H1.68l7.73-8.835L1.254 2.25H8.08l4.713 6.231zm-1.161 17.52h1.833L7.084 4.126H5.117z"/></svg>',
          'youtube'   => '<svg viewBox="0 0 24 24" width="18" height="18" fill="currentColor"><path d="M23.5 6.2a3 3 0 0 0-2.1-2.1C19.5 3.5 12 3.5 12 3.5s-7.5 0-9.4.6A3 3 0 0 0 .5 6.2 31 31 0 0 0 0 12a31 31 0 0 0 .5 5.8 3 3 0 0 0 2.1 2.1c1.9.6 9.4.6 9.4.6s7.5 0 9.4-.6a3 3 0 0 0 2.1-2.1 31 31 0 0 0 .5-5.8 31 31 0 0 0-.5-5.8zM9.6 15.6V8.4l6.2 3.6z"/></svg>',
          'tiktok'    => '<svg viewBox="0 0 24 24" width="18" height="18" fill="currentColor"><path d="M19.6 7.5a6 6 0 0 1-3.5-1v8a5.5 5.5 0 1 1-5.5-5.5v2.7a2.8 2.8 0 1 0 2.8 2.8V2h2.7a4 4 0 0 0 3.5 4z"/></svg>',
          'linkedin'  => '<svg viewBox="0 0 24 24" width="18" height="18" fill="currentColor"><path d="M19 3H5a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2V5a2 2 0 0 0-2-2zM8.34 17.34H5.67V9.67h2.67zM7 8.34a1.55 1.55 0 1 1 0-3.1 1.55 1.55 0 0 1 0 3.1zm11.34 9H15.67v-3.86c0-.94-.02-2.16-1.31-2.16-1.32 0-1.52 1.03-1.52 2.09v3.93H10.18V9.67h2.56v1.05h.04a2.8 2.8 0 0 1 2.52-1.38c2.7 0 3.2 1.78 3.2 4.08v3.92z"/></svg>',
          'pinterest' => '<svg viewBox="0 0 24 24" width="18" height="18" fill="currentColor"><path d="M12 2a10 10 0 0 0-3.65 19.31c-.07-.79-.13-2 .03-2.87.14-.79 1-5 1-5s-.26-.51-.26-1.27c0-1.2.7-2.1 1.56-2.1.74 0 1.1.56 1.1 1.22 0 .74-.47 1.85-.72 2.88-.2.86.43 1.57 1.28 1.57 1.53 0 2.7-1.6 2.7-3.94 0-2.06-1.48-3.5-3.6-3.5a3.73 3.73 0 0 0-3.9 3.74c0 .74.29 1.54.64 1.97a.26.26 0 0 1 .06.25l-.24 1c-.05.13-.13.16-.27.1-1-.47-1.62-1.95-1.62-3.13 0-2.55 1.85-4.9 5.34-4.9 2.8 0 4.99 2 4.99 4.67 0 2.8-1.76 5.04-4.2 5.04-.82 0-1.6-.43-1.86-.93l-.5 1.93c-.18.7-.68 1.58-1.02 2.12A10 10 0 1 0 12 2z"/></svg>',
          'github'    => '<svg viewBox="0 0 24 24" width="18" height="18" fill="currentColor"><path d="M12 2a10 10 0 0 0-3.16 19.49c.5.09.69-.22.69-.48v-1.7c-2.78.6-3.37-1.34-3.37-1.34-.45-1.16-1.11-1.47-1.11-1.47-.9-.62.07-.6.07-.6 1 .07 1.53 1.04 1.53 1.04.9 1.54 2.34 1.1 2.91.84.09-.65.35-1.09.63-1.34-2.22-.25-4.55-1.11-4.55-4.94 0-1.1.39-1.99 1.03-2.69-.1-.26-.45-1.27.1-2.65 0 0 .84-.27 2.75 1.03a9.6 9.6 0 0 1 5 0c1.91-1.3 2.75-1.03 2.75-1.03.55 1.38.2 2.4.1 2.65.64.7 1.03 1.6 1.03 2.69 0 3.84-2.34 4.68-4.57 4.93.36.31.68.92.68 1.85v2.74c0 .27.18.58.69.48A10 10 0 0 0 12 2z"/></svg>',
          'website'   => '<svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><path d="M2 12h20M12 2a15 15 0 0 1 0 20M12 2a15 15 0 0 0 0 20"/></svg>',
          'email'     => '<svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2"><rect x="2" y="4" width="20" height="16" rx="2"/><path d="M2 7l10 6 10-6"/></svg>',
      ];
      return $icons[$platform] ?? $icons['website'];
  };
@endphp

<style>
.{{ $instId }} {
  padding-top: {{ $padTop }};
  padding-bottom: {{ $padBot }};
  @if($bgMode === 'gradient')
  background: linear-gradient({{ (int)($c['bg_gradient_angle'] ?? 135) }}deg, {{ $gradF }} 0%, {{ $gradT }} 100%);
  @else
  background: {{ $bgColor }};
  @endif
  @if($borderTop === 'hairline')
  border-top: 1px solid rgba(255,255,255,0.06);
  @elseif($borderTop === 'divider')
  border-top: 1px solid rgba(255,255,255,0.12);
  @endif
  color: {{ $textColor }};
}
.{{ $instId }} .p-ftr-wrap {
  max-width: 1200px;
  margin: 0 auto;
  padding: 0 clamp(20px, 5vw, 48px);
}
.{{ $instId }} .p-ftr-top {
  display: grid;
  @if($layout === 'columns')
  grid-template-columns: minmax(0, 1.4fr) repeat({{ max(1, min(4, count($linkColumns) + ($hasContactBlock ? 1 : 0) + ($showForm ? 1 : 0))) }}, minmax(0, 1fr));
  gap: 48px;
  align-items: start;
  @elseif($layout === 'centered')
  grid-template-columns: 1fr;
  text-align: center;
  gap: 32px;
  @else
  grid-template-columns: 1fr;
  gap: 16px;
  text-align: {{ $hAlign }};
  @endif
  margin-bottom: 36px;
}
@media (max-width: 720px) {
  .{{ $instId }} .p-ftr-top { grid-template-columns: 1fr 1fr; gap: 24px; }
}
@media (max-width: 480px) {
  .{{ $instId }} .p-ftr-top { grid-template-columns: 1fr; }
}

.{{ $instId }} .p-ftr-brand {
  @if($layout === 'centered') margin: 0 auto; @endif
  max-width: 340px;
}
.{{ $instId }} .p-ftr-logo {
  font-size: 19px;
  font-weight: 600;
  letter-spacing: -.01em;
  color: {{ $textColor }};
  text-decoration: none;
  display: flex;
  align-items: center;
  gap: 10px;
  margin-bottom: 12px;
  @if($layout === 'centered') justify-content: center; @endif
}
.{{ $instId }} .p-ftr-logo img { height: {{ $logoHeight }}; width: auto; }
.{{ $instId }} .p-ftr-tagline {
  font-size: 14px;
  line-height: 1.55;
  color: {{ $mutedColor }};
  margin: 0 0 16px;
}

.{{ $instId }} .p-ftr-social {
  display: flex;
  gap: 12px;
  @if($layout === 'centered') justify-content: center; @endif
}
.{{ $instId }} .p-ftr-social a {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  width: 32px; height: 32px;
  border-radius: 6px;
  color: {{ $linkColor }};
  transition: all 0.15s;
}
.{{ $instId }} .p-ftr-social a:hover {
  color: {{ $textColor }};
  background: rgba(255,255,255,0.05);
}

.{{ $instId }} .p-ftr-col-heading {
  font-size: 11px;
  font-weight: 600;
  text-transform: uppercase;
  letter-spacing: 0.12em;
  color: {{ $textColor }};
  margin: 0 0 14px;
  opacity: .85;
}
.{{ $instId }} .p-ftr-col ul {
  list-style: none;
  padding: 0;
  margin: 0;
  display: flex;
  flex-direction: column;
  gap: 9px;
}
.{{ $instId }} .p-ftr-col a {
  font-size: 13.5px;
  color: {{ $linkColor }};
  text-decoration: none;
  transition: color 0.15s;
}
.{{ $instId }} .p-ftr-col a:hover { color: {{ $textColor }}; }

.{{ $instId }} .p-ftr-contact-line {
  font-size: 13.5px;
  color: {{ $linkColor }};
  margin: 0 0 9px;
  line-height: 1.5;
}
.{{ $instId }} .p-ftr-contact-line a { color: {{ $linkColor }}; text-decoration: none; }
.{{ $instId }} .p-ftr-contact-line a:hover { color: {{ $textColor }}; }
.{{ $instId }} .p-ftr-contact-line strong {
  display: block;
  font-size: 11px;
  font-weight: 600;
  text-transform: uppercase;
  letter-spacing: 0.08em;
  color: {{ $mutedColor }};
  margin-bottom: 3px;
}

/* MARKER-PATCH-158-G29 — inline contact form */
.{{ $instId }} .p-ftr-form {
  display: flex;
  flex-direction: column;
  gap: 10px;
}
.{{ $instId }} .p-ftr-form-desc {
  font-size: 13px;
  color: {{ $mutedColor }};
  margin: 0 0 6px;
  line-height: 1.5;
}
.{{ $instId }} .p-ftr-form input,
.{{ $instId }} .p-ftr-form textarea {
  width: 100%;
  font-family: inherit;
  font-size: 13.5px;
  color: {{ $textColor }};
  padding: 9px 12px;
  background: rgba(255,255,255,0.06);
  border: 1px solid rgba(255,255,255,0.1);
  border-radius: 6px;
  outline: none;
  transition: border-color 0.15s;
}
.{{ $instId }} .p-ftr-form input:focus,
.{{ $instId }} .p-ftr-form textarea:focus {
  border-color: {{ $linkColor }};
}
.{{ $instId }} .p-ftr-form input::placeholder,
.{{ $instId }} .p-ftr-form textarea::placeholder {
  color: {{ $mutedColor }};
  opacity: .9;
}
.{{ $instId }} .p-ftr-form textarea { resize: vertical; min-height: 60px; }
.{{ $instId }} .p-ftr-form button {
  padding: 9px 18px;
  font-size: 13.5px;
  font-weight: 500;
  background: {{ $textColor }};
  color: {{ $bgColor }};
  border: 0;
  border-radius: 6px;
  cursor: pointer;
  transition: filter 0.15s;
}
.{{ $instId }} .p-ftr-form button:hover { filter: brightness(0.92); }
.{{ $instId }} .p-ftr-form-success {
  padding: 10px 12px;
  border-radius: 6px;
  background: rgba(190,242,100,0.1);
  border: 1px solid rgba(190,242,100,0.2);
  color: {{ $textColor }};
  font-size: 13px;
}
.{{ $instId }} .p-ftr-form-error {
  padding: 10px 12px;
  border-radius: 6px;
  background: rgba(255,100,100,0.1);
  border: 1px solid rgba(255,100,100,0.2);
  color: #ffaaaa;
  font-size: 13px;
}
.{{ $instId }} .p-ftr-form-heading {
  font-size: 11px;
  font-weight: 600;
  text-transform: uppercase;
  letter-spacing: 0.12em;
  color: {{ $textColor }};
  margin: 0 0 14px;
  opacity: .85;
}

.{{ $instId }} .p-ftr-bottom {
  border-top: 1px solid rgba(255,255,255,0.06);
  padding-top: 22px;
  font-size: 12.5px;
  color: {{ $mutedColor }};
  text-align: {{ $hAlign }};
}
.{{ $instId }} .p-ftr-bottom.p-ftr-bottom--has-badge {
  display: flex;
  align-items: center;
  justify-content: space-between;
  flex-wrap: wrap;
  gap: 12px;
  text-align: left;
}
@media (max-width: 560px) {
  .{{ $instId }} .p-ftr-bottom.p-ftr-bottom--has-badge {
    flex-direction: column;
    align-items: flex-start;
    gap: 6px;
  }
}
.{{ $instId }} .p-ftr-bottom a {
  color: {{ $linkColor }};
  text-decoration: none;
}
.{{ $instId }} .p-ftr-bottom a:hover { color: {{ $textColor }}; }

@if($hideMobile)
@media (max-width: 768px) { .{{ $instId }} { display: none; } }
@endif
@if($hideDesktop)
@media (min-width: 769px) { .{{ $instId }} { display: none; } }
@endif
/* MARKER-PATCH-303 — pre-footer call-to-action band */
.{{ $instId }} .p-ftr-cta { border-bottom: 1px solid rgba(255,255,255,0.08); padding-bottom: 36px; margin-bottom: 44px; }
.{{ $instId }} .p-ftr-cta-inner { max-width: 1200px; margin: 0 auto; padding: 0 clamp(20px, 5vw, 48px); display: flex; align-items: center; justify-content: space-between; gap: 28px; flex-wrap: wrap; }
.{{ $instId }} .p-ftr-cta-eyebrow { font-size: 11px; letter-spacing: .16em; text-transform: uppercase; color: {{ $ctaAccent }}; margin-bottom: 10px; font-weight: 600; }
.{{ $instId }} .p-ftr-cta-h { font-size: clamp(22px, 3vw, 32px); line-height: 1.08; margin: 0; font-weight: 700; letter-spacing: -0.01em; color: {{ $textColor }}; }
.{{ $instId }} .p-ftr-cta-hl { color: {{ $ctaAccent }}; }
.{{ $instId }} .p-ftr-cta-actions { display: flex; align-items: center; gap: 16px; }
.{{ $instId }} .p-ftr-cta-btn { background: {{ $ctaAccent }}; color: {{ $ctaBtnText }}; font-weight: 700; font-size: 15px; padding: 14px 24px; border-radius: 10px; text-decoration: none; transition: filter .15s, transform .15s; white-space: nowrap; }
.{{ $instId }} .p-ftr-cta-btn:hover { filter: brightness(1.07); transform: translateY(-1px); }
.{{ $instId }} .p-ftr-cta-note { font-size: 13px; color: {{ $mutedColor }}; }
@media (max-width: 600px) { .{{ $instId }} .p-ftr-cta-inner { flex-direction: column; align-items: flex-start; gap: 18px; } }
</style>

<footer class="{{ $instId }} p-footer {{ $customClass }}" @if($anchorId) id="{{ $anchorId }}" @endif>
  @php
    $ctaOn      = (bool) ($c['cta_band'] ?? false);
    $ctaEyebrow = trim($c['cta_eyebrow'] ?? '');
    $ctaHeading = trim($c['cta_heading'] ?? '');
    $ctaHl      = trim($c['cta_highlight'] ?? '');
    $ctaBtn     = trim($c['cta_button_label'] ?? '');
    $ctaUrl     = trim($c['cta_button_url'] ?? '');
    $ctaNote    = trim($c['cta_note'] ?? '');
    $ctaAccent  = ($tenant->accent_color ?? '') ?: '#3FD16B';
    $ctaBtnText = \App\Support\ColorHelper::accentTextColor($ctaAccent);
    $ctaHeadingHtml = e($ctaHeading);
    if ($ctaHl !== '' && mb_stripos($ctaHeading, $ctaHl) !== false) {
        $ctaHeadingHtml = str_ireplace(e($ctaHl), '<span class="p-ftr-cta-hl">'.e($ctaHl).'</span>', e($ctaHeading));
    }
  @endphp
  @if($ctaOn && ($ctaHeading !== '' || $ctaBtn !== ''))
  <div class="p-ftr-cta">
    <div class="p-ftr-cta-inner">
      <div class="p-ftr-cta-text">
        @if($ctaEyebrow !== '')<div class="p-ftr-cta-eyebrow">{{ $ctaEyebrow }}</div>@endif
        @if($ctaHeading !== '')<h2 class="p-ftr-cta-h">{!! $ctaHeadingHtml !!}</h2>@endif
      </div>
      @if($ctaBtn !== '')
      <div class="p-ftr-cta-actions">
        <a href="{{ $ctaUrl ?: '#' }}" class="p-ftr-cta-btn">{{ $ctaBtn }}</a>
        @if($ctaNote !== '')<span class="p-ftr-cta-note">{{ $ctaNote }}</span>@endif
      </div>
      @endif
    </div>
  </div>
  @endif
  <div class="p-ftr-wrap">

    <div class="p-ftr-top">
      <div class="p-ftr-brand">
        @if($showLogo)
          <a href="/" class="p-ftr-logo">
            @if($logoUrl)
              <img src="{{ $logoUrl }}" alt="{{ $tenant->name ?? 'Logo' }}">
            @else
              {{ $tenant->name ?? 'Logo' }}
            @endif
          </a>
        @endif

        @if($tagline !== '')
          <p class="p-ftr-tagline">{{ $tagline }}</p>
        @endif

        @if(!empty($socialLinks))
          <div class="p-ftr-social">
            @foreach($socialLinks as $s)
              @php
                $platform = $s['platform'] ?? 'website';
                $url      = $s['url'] ?? '';
                if ($platform === 'email' && $url !== '' && !str_starts_with($url, 'mailto:')) {
                    $url = 'mailto:' . $url;
                }
              @endphp
              @if($url !== '')
                <a href="{{ $url }}" target="_blank" rel="noopener" aria-label="{{ ucfirst($platform) }}">
                  {!! $iconFor($platform) !!}
                </a>
              @endif
            @endforeach
          </div>
        @endif
      </div>

      @if($layout === 'columns' || $layout === 'centered')
        @foreach($linkColumns as $col)
          @php
            $heading = $col['heading'] ?? '';
            $links   = is_array($col['links'] ?? null) ? $col['links'] : [];
          @endphp
          @if($heading !== '' || !empty($links))
            <div class="p-ftr-col">
              @if($heading !== '')
                <h4 class="p-ftr-col-heading">{{ $heading }}</h4>
              @endif
              @if(!empty($links))
                <ul>
                  @foreach($links as $li)
                    @php $label = $li['label'] ?? ''; $url = $li['url'] ?? '#'; @endphp
                    @if($label !== '')
                      <li><a href="{{ $url }}">{{ $label }}</a></li>
                    @endif
                  @endforeach
                </ul>
              @endif
            </div>
          @endif
        @endforeach

        @if($hasContactBlock)
          <div class="p-ftr-col">
            <h4 class="p-ftr-col-heading">Contact</h4>
            @if($showPhone)
              <p class="p-ftr-contact-line">
                <strong>Phone</strong>
                <a href="tel:{{ $cPhone }}">{{ $cPhone }}</a>
              </p>
            @endif
            @if($showEmail)
              <p class="p-ftr-contact-line">
                <strong>Email</strong>
                <a href="mailto:{{ $contactEmail }}">{{ $contactEmail }}</a>
              </p>
            @endif
            @if($showAddress)
              <p class="p-ftr-contact-line">
                <strong>Address</strong>
                {{ $cAddress }}
              </p>
            @endif
            @if($showHours)
              <p class="p-ftr-contact-line">
                <strong>Hours</strong>
                {{ $cHours }}
              </p>
            @endif
          </div>
        @endif

        {{-- MARKER-PATCH-158-G29 — inline contact form column --}}
        @if($showForm)
          <div class="p-ftr-col">
            @if($formHeading !== '')
              <h4 class="p-ftr-form-heading">{{ $formHeading }}</h4>
            @endif

            @if(session('contact_success'))
              <div class="p-ftr-form-success">{{ $formSuccess }}</div>
            @else
              @if($formDescription !== '')
                <p class="p-ftr-form-desc">{{ $formDescription }}</p>
              @endif

              <form method="POST" action="/contact" class="p-ftr-form">
                @csrf
                {{-- MARKER-PATCH-399 honeypot — bots fill this; real users never see or focus it --}}
                <input type="text" name="company_website" tabindex="-1" autocomplete="off" aria-hidden="true"
                       style="position:absolute !important;left:-9999px !important;top:auto;width:1px;height:1px;opacity:0;pointer-events:none">
                @if($errors->any())
                  <div class="p-ftr-form-error">{{ $errors->first() }}</div>
                @endif
                <input type="text" name="name" placeholder="Your name" value="{{ old('name') }}" required>
                <input type="email" name="email" placeholder="you@example.com" value="{{ old('email') }}" required>
                @if($formShowPhone)
                  <input type="tel" name="phone" placeholder="{{ $formRequirePhone ? 'Phone' : 'Phone (optional)' }}" value="{{ old('phone') }}" {{ $formRequirePhone ? 'required' : '' }}>
                @endif
                <textarea name="message" rows="2" placeholder="How can we help?" required>{{ old('message') }}</textarea>
                <button type="submit">{{ $formButton }}</button>
              </form>
            @endif
          </div>
        @endif
      @endif
    </div>

    <div class="p-ftr-bottom {{ $showPoweredBy ? 'p-ftr-bottom--has-badge' : '' }}">
      <span>{{ $copyText }}</span>
      @if($showPoweredBy)
        <span>Powered by <a href="https://intake.works" target="_blank" rel="noopener">Intake</a></span>
      @endif
    </div>

  </div>
</footer>
