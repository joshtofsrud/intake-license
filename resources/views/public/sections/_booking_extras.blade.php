{{--
  MARKER-PATCH-601 — booking marketing sections renderer.
  Renders one slot ('before' or 'after' the booking form) worth of marketing
  sections configured in the booking editor. Each section is a plain array:
    { id, type, position, ...fields }
  Types: hero | cta | feature_grid | custom_html.
  Rich fields honored: headline, subtext, button label/url, secondary button,
  bg_color, bg_image_url, text_color, align (left|center|right),
  pad_top, pad_bottom (px). Feature grid adds a features[] repeater.

  Usage:  @include('public.sections._booking_extras', ['slot' => 'before'])
  Expects $bookingSections in scope (array). Safe if empty/missing.
--}}
@php
  $__all = $bookingSections ?? [];
  $__slot = $slot ?? 'before';
  $__list = array_values(array_filter(is_array($__all) ? $__all : [], function ($s) use ($__slot) {
      return is_array($s) && ($s['position'] ?? 'before') === $__slot;
  }));
@endphp

@foreach($__list as $__s)
  @php
    $type   = $__s['type'] ?? 'cta';
    $bg     = trim($__s['bg_color'] ?? '');
    $bgImg  = trim($__s['bg_image_url'] ?? '');
    $txt    = trim($__s['text_color'] ?? '');
    $align  = in_array(($__s['align'] ?? 'center'), ['left','center','right'], true) ? $__s['align'] : 'center';
    $padT   = is_numeric($__s['pad_top'] ?? null) ? (int) $__s['pad_top'] : 56;
    $padB   = is_numeric($__s['pad_bottom'] ?? null) ? (int) $__s['pad_bottom'] : 56;
    $sid    = 'bx-' . substr(md5(($__s['id'] ?? '') . $type . $loop->index), 0, 8);
    // Compose the section background: image (cover) over color, or just color.
    $bgCss = '';
    if ($bgImg !== '') {
        $bgCss = "background-image:url('".e($bgImg)."');background-size:cover;background-position:center;";
        if ($bg !== '') $bgCss = "background-color:".e($bg).";".$bgCss;
    } elseif ($bg !== '') {
        $bgCss = "background-color:".e($bg).";";
    }
    $styleAttr = $bgCss . "padding-top:{$padT}px;padding-bottom:{$padB}px;text-align:{$align};";
    if ($txt !== '') $styleAttr .= "color:".e($txt).";";
  @endphp

  <section class="bx-section bx-{{ $type }} {{ $sid }}" style="{{ $styleAttr }}">
    <div class="bx-inner">
      @if($type === 'custom_html')
        {!! $__s['html'] ?? '' !!}

      @elseif($type === 'feature_grid')
        @if(!empty($__s['headline']))<h2 class="bx-h">{{ $__s['headline'] }}</h2>@endif
        @if(!empty($__s['subtext']))<p class="bx-sub">{{ $__s['subtext'] }}</p>@endif
        @php $feats = is_array($__s['features'] ?? null) ? $__s['features'] : []; @endphp
        @if(count($feats))
          <div class="bx-grid">
            @foreach($feats as $f)
              <div class="bx-card">
                @if(!empty($f['icon']))<div class="bx-card-icon">{{ $f['icon'] }}</div>@endif
                @if(!empty($f['title']))<div class="bx-card-title">{{ $f['title'] }}</div>@endif
                @if(!empty($f['text']))<div class="bx-card-text">{{ $f['text'] }}</div>@endif
              </div>
            @endforeach
          </div>
        @endif

      @else
        {{-- hero + cta share the same headline/subtext/buttons layout --}}
        @if(!empty($__s['eyebrow']))<div class="bx-eyebrow">{{ $__s['eyebrow'] }}</div>@endif
        @if(!empty($__s['headline']))<h2 class="bx-h {{ $type === 'hero' ? 'bx-h--lg' : '' }}">{{ $__s['headline'] }}</h2>@endif
        @if(!empty($__s['subtext']))<p class="bx-sub">{{ $__s['subtext'] }}</p>@endif
        @php
          $btnL = trim($__s['btn_label'] ?? '');  $btnU = trim($__s['btn_url'] ?? '');
          $b2L  = trim($__s['btn2_label'] ?? '');  $b2U = trim($__s['btn2_url'] ?? '');
        @endphp
        @if($btnL !== '' || $b2L !== '')
          <div class="bx-actions">
            @if($btnL !== '')<a href="{{ $btnU ?: '#' }}" class="bx-btn bx-btn--primary">{{ $btnL }}</a>@endif
            @if($b2L !== '')<a href="{{ $b2U ?: '#' }}" class="bx-btn bx-btn--ghost">{{ $b2L }}</a>@endif
          </div>
        @endif
      @endif
    </div>
  </section>
@endforeach
