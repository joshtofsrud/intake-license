{{-- MARKER-PATCH-306 — custom_html public renderer.
     Deliberately renders author markup raw ({!! !!}). This is the entire
     purpose of the section: tenant-authored HTML on the tenant's own page.
     No sanitizer by design — treat tenant page authors as trusted. --}}
@php
  $c = $c ?? [];

  $html        = (string)($c['html'] ?? '');
  $bgColor     = trim($c['bg_color'] ?? '');
  $anchorId    = trim($c['anchor_id'] ?? '');
  $customClass = trim($c['custom_classes'] ?? '');
  $hideMobile  = !empty($c['hide_on_mobile']);
  $hideDesktop = !empty($c['hide_on_desktop']);

  $padTokens = ['none'=>'0','compact'=>'24px','normal'=>'56px','spacious'=>'96px'];
  $padY      = $padTokens[$c['padding_y'] ?? 'normal'] ?? '56px';

  $instId = 'p-html-' . ($section->id ?? uniqid());
@endphp

<style>
.{{ $instId }} {
  padding-top: {{ $padY }};
  padding-bottom: {{ $padY }};
  @if($bgColor !== '')
  background: {{ $bgColor }};
  @endif
}
@if($hideMobile)
@media (max-width: 768px) { .{{ $instId }} { display: none; } }
@endif
@if($hideDesktop)
@media (min-width: 769px) { .{{ $instId }} { display: none; } }
@endif
</style>

<section class="{{ $instId }} p-custom-html {{ $customClass }}" @if($anchorId) id="{{ $anchorId }}" @endif>
  {!! $html !!}
</section>
