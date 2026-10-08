{{-- custom_html marketing renderer.
     Renders author markup raw ({!! !!}) — the whole point of the section is
     dropping in HTML built elsewhere (e.g. in a design tool). No sanitizer by design;
     marketing pages are authored by the platform admin only.
     Honors the same content keys the shared editor (tenant/pages/sections/
     _custom_html) exposes, so no inspector control is dead. --}}
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

  $instId = 'mk-html-' . ($section->id ?? uniqid());
  // on: the markup sits inside the page width like every
  // other section. Off (older blocks): full browser width, as before.
  $fitWidth = ! in_array((string) ($c['fit_width'] ?? '0'), ['', '0', 'false'], true);
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

<section class="{{ $instId }} mk-custom-html {{ $customClass }}" @if($anchorId) id="{{ $anchorId }}" @endif>
  @if($fitWidth)<div class="mk-container">{!! $html !!}</div>@else{!! $html !!}@endif
</section>
