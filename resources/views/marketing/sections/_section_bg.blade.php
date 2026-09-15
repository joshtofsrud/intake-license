{{-- MARKER-MKT-SECTION-BG — image and gradient backgrounds for marketing
     sections, from the same Design-tab keys the tenant public renderers read.

     Include with ['bgId' => $bgId] and put {{ $bgId }} on the section's class.
     Emits nothing unless the section is in image or gradient mode, so a page
     that never touched the Design tab renders byte-for-byte as before.

     Colour mode is deliberately NOT handled here: marketing/page.blade.php
     already paints it from the section's bg_color column, and reading the
     Design tab's colour instead would repaint the live site from seeded
     values. Parallax is not ported to the marketing template. --}}
@php
  $bgMode  = $c['bg_mode'] ?? 'color';
  $imgUrl  = $c['bg_image_url'] ?? '';
  $isImage = $bgMode === 'image' && $imgUrl !== '';
  $isGrad  = $bgMode === 'gradient';

  if ($isImage || $isGrad) {
      $bgColor  = $c['bg_color'] ?? '#0a0a0a';
      $imgPos   = $c['bg_image_position'] ?? 'center';
      $imgSize  = $c['bg_image_size'] ?? 'cover';
      $gradFrom = $c['bg_gradient_from'] ?? '#1a1a1a';
      $gradTo   = $c['bg_gradient_to']   ?? '#0a0a0a';
      $gradDeg  = (int) ($c['bg_gradient_angle'] ?? 135);

      $overlayOpacity = max(0, min(100, (int) ($c['bg_overlay_opacity'] ?? 45)));
      $blurPx         = max(0, min(14, (int) ($c['bg_blur'] ?? 0)));
      $hex = ltrim((string) ($c['bg_overlay_color'] ?? '#000000'), '#');
      if (strlen($hex) === 3) { $hex = $hex[0].$hex[0].$hex[1].$hex[1].$hex[2].$hex[2]; }
      if (strlen($hex) !== 6 || ! ctype_xdigit($hex)) { $hex = '000000'; }
      // Alpha baked into rgba so the veil keeps opacity:1 — browsers drop
      // backdrop-filter when the element's own opacity is below 1.
      $overlayRgba = sprintf('rgba(%d,%d,%d,%s)',
          hexdec(substr($hex, 0, 2)), hexdec(substr($hex, 2, 2)), hexdec(substr($hex, 4, 2)),
          round($overlayOpacity / 100, 3));
      $needVeil = $isImage && ($overlayOpacity > 0 || $blurPx > 0);
  }
@endphp
@if($isImage || $isGrad)
<style>
  .{{ $bgId }} { position: relative; }
  @if($isImage)
  .{{ $bgId }} {
    background-color: {{ $bgColor }} !important;
    background-image: url('{{ $imgUrl }}') !important;
    background-size: {{ $imgSize }} !important;
    background-position: {{ $imgPos }} !important;
    background-repeat: no-repeat !important;
  }
  @else
  .{{ $bgId }} {
    background: linear-gradient({{ $gradDeg }}deg, {{ $gradFrom }} 0%, {{ $gradTo }} 100%) !important;
  }
  @endif
  .{{ $bgId }} > * { position: relative; z-index: 1; }
  @if($needVeil)
  .{{ $bgId }}::before {
    content: ''; position: absolute; inset: 0; z-index: 0; pointer-events: none;
    background: {{ $overlayRgba }};
    @if($blurPx > 0)
    backdrop-filter: blur({{ $blurPx }}px);
    -webkit-backdrop-filter: blur({{ $blurPx }}px);
    @endif
  }
  @endif
</style>
@endif
