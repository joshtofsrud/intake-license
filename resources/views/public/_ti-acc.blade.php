{{-- MARKER-SHOP-TI-ACCORDION: Text + image, Accordion style, on shop sites.
     Port of marketing/sections/text_image (accordion branch) in the shop
     section's own classes and colors. Included by public/sections/_text_image
     inside .p-ti-wrap, with that partial's variables. Several items; opening
     one fades its text in and crossfades the image beside the list. Optional:
     open the first on load, several open at once, move on by itself, or open
     items as visitors scroll (the block holds in place while they scroll). --}}
@php
  $taItems = $c['acc_items'] ?? [];
  if (is_string($taItems)) { $d = json_decode($taItems, true); $taItems = is_array($d) ? $d : []; }
  if (! is_array($taItems)) $taItems = [];
  $taItems = array_values(array_filter($taItems, fn ($it) => is_array($it) && (trim((string) ($it['title'] ?? '')) !== '' || trim((string) ($it['body'] ?? '')) !== '')));
  $taFlag   = fn ($k, $d) => array_key_exists($k, $c) ? ! in_array((string) $c[$k], ['', '0', 'false'], true) : $d;
  $taFirst  = $taFlag('acc_first_open', true);
  $taMulti  = $taFlag('acc_multi', false);
  $taScroll = $taFlag('acc_scroll', false) && ! $taMulti && count($taItems) > 1;
  $taLen    = ['short' => 0.5, 'medium' => 0.75, 'long' => 1.1][$c['acc_scroll_len'] ?? 'medium'] ?? 0.75;
  $taAuto   = $taFlag('acc_auto', false) && ! $taMulti && ! $taScroll;
  $taSecs   = max(3, min(20, (int) ($c['acc_auto_secs'] ?? 6)));
  $taIcon   = ($c['acc_icon'] ?? 'plus') === 'arrow' ? 'arrow' : 'plus';
  $taNums   = ($c['acc_numbers'] ?? 'show') !== 'hide';
  $taMid    = ($c['acc_img_valign'] ?? 'middle') !== 'top';
  $taAccent = $accentColor ?: 'var(--p-accent, ' . $textColor . ')';
  $taLine   = 'color-mix(in srgb, ' . $textColor . ' 14%, transparent)';
  $taDim    = 'color-mix(in srgb, ' . $textColor . ' 45%, transparent)';
  $taSafe   = fn ($u) => preg_match('#^(https?://|/|\#|mailto:|tel:)#i', trim((string) $u)) ? trim((string) $u) : '#';
  $taI      = $instId;
@endphp
<style>
  .{{ $taI }} .ti-intro { margin-bottom: 36px; }
  .{{ $taI }} .ti-intro .p-ti-body { margin-bottom: 0; @if($hAlign === 'center') margin-left: auto; margin-right: auto; @elseif($hAlign === 'right') margin-left: auto; @endif }
  .{{ $taI }} .ti-grid { display: grid; grid-template-columns: {{ $ratioCols }}; gap: clamp(28px, 5vw, 64px); align-items: {{ $taMid ? 'center' : 'start' }}; }
  .{{ $taI }} .ti-grid > .ti-side-img { order: {{ $imgRight ? 2 : 1 }}; }
  .{{ $taI }} .ti-grid > .ti-side-text { order: {{ $imgRight ? 1 : 2 }}; }
  .{{ $taI }} .ti-list { border-top: 1px solid {{ $taLine }}; text-align: left; color: {{ $textColor }}; }
  .{{ $taI }} .ti-item { border-bottom: 1px solid {{ $taLine }}; position: relative; }
  .{{ $taI }} .ti-item::before { content: ""; position: absolute; left: 0; top: -1px; height: 1.5px; width: 0; background: {{ $taAccent }}; }
  .{{ $taI }} .ti-item.is-open::before { width: 100%; transition: width .45s ease; }
  .{{ $taI }}.ti-auto .ti-item.is-open::before { width: 0; transition: none; }
  .{{ $taI }}.ti-auto .ti-item.is-open.is-run::before { width: 100%; transition: width {{ $taSecs }}s linear; }
  .{{ $taI }} .ti-head { display: flex; align-items: center; gap: 16px; width: 100%; background: none; border: 0; color: inherit; text-align: left; padding: 22px 0; font-size: clamp(17px, 1.9vw, 21px); font-weight: 600; letter-spacing: -.01em; font-family: inherit; cursor: pointer; }
  .{{ $taI }} .ti-num { font-size: 12px; color: {{ $taDim }}; font-weight: 500; font-variant-numeric: tabular-nums; width: 22px; flex: none; }
  .{{ $taI }} .ti-t { flex: 1; transition: opacity .2s; }
  .{{ $taI }} .ti-item:not(.is-open) .ti-t { opacity: .62; }
  .{{ $taI }} .ti-item:not(.is-open) .ti-head:hover .ti-t { opacity: 1; }
  .{{ $taI }} .ti-ic { position: relative; width: 18px; height: 18px; flex: none; }
  .{{ $taI }} .ti-item.is-open .ti-ic { color: {{ $taAccent }}; }
  .{{ $taI }} .ti-ic span { position: absolute; top: 8px; height: 2px; border-radius: 2px; background: currentColor; transition: transform .25s ease; }
@if($taIcon === 'plus')
  .{{ $taI }} .ti-ic span { left: 1px; width: 16px; }
  .{{ $taI }} .ti-ic span:nth-child(2) { transform: rotate(90deg); }
  .{{ $taI }} .ti-item.is-open .ti-ic span:nth-child(1) { transform: rotate(45deg); }
  .{{ $taI }} .ti-item.is-open .ti-ic span:nth-child(2) { transform: rotate(135deg); }
@else
  .{{ $taI }} .ti-ic span { width: 9px; }
  .{{ $taI }} .ti-ic span:nth-child(1) { left: 0; transform-origin: 100% 50%; transform: translateY(3px) rotate(40deg); }
  .{{ $taI }} .ti-ic span:nth-child(2) { left: 9px; transform-origin: 0 50%; transform: translateY(3px) rotate(-40deg); }
  .{{ $taI }} .ti-item.is-open .ti-ic span:nth-child(1) { transform: translateY(-3px) rotate(-40deg); }
  .{{ $taI }} .ti-item.is-open .ti-ic span:nth-child(2) { transform: translateY(-3px) rotate(40deg); }
@endif
  .{{ $taI }} .ti-panel { display: grid; grid-template-rows: 0fr; transition: grid-template-rows .4s ease; }
  .{{ $taI }} .ti-item.is-open .ti-panel { grid-template-rows: 1fr; }
  .{{ $taI }} .ti-panel > div { overflow: hidden; }
  .{{ $taI }} .ti-inner { padding: 0 0 24px {{ $taNums ? '38px' : '0' }}; opacity: 0; transform: translateY(6px); transition: opacity .3s ease, transform .3s ease; }
  .{{ $taI }} .ti-item.is-open .ti-inner { opacity: 1; transform: none; transition-delay: .12s; }
  .{{ $taI }} .ti-inner .p-ti-body { max-width: 46ch; margin-bottom: 0; }
  .{{ $taI }} .ti-inner .p-ti-btn { margin-top: 18px; padding: 9px 16px; font-size: 13px; }
  .{{ $taI }} .ti-inner .ti-img-in { display: none; }
  @if(! $taMid) .{{ $taI }} .ti-stage { position: sticky; top: 24px; } @endif
  .{{ $taI }} .ti-stage .p-ti-image { position: relative; }
  .{{ $taI }} .ti-stage .p-ti-image img, .{{ $taI }} .ti-stage .p-ti-image .p-ti-image-empty { position: absolute; inset: 0; opacity: 0; transform: scale(1.02); transition: opacity .45s ease, transform .6s ease; }
  .{{ $taI }} .ti-stage .p-ti-image .is-on { opacity: 1; transform: none; }
@if($imgAspect === 'auto')
  .{{ $taI }} .ti-stage .p-ti-image .is-on { position: relative; display: block; }
  .{{ $taI }} .ti-stage .p-ti-image img { height: auto; }
@endif
  .{{ $taI }} .ti-stage .p-ti-image img:not(.is-on) { pointer-events: none; }
@if($taScroll)
  .{{ $taI }} .ti-track { position: relative; }
  .{{ $taI }} .ti-pin { position: sticky; top: 80px; }
  .{{ $taI }} .ti-acc.is-scroll .ti-item.is-open::before { width: calc(var(--p, 0) * 100%); transition: none; }
  @media (prefers-reduced-motion: reduce) {
    .{{ $taI }} .ti-track { height: auto !important; }
    .{{ $taI }} .ti-pin { position: static; }
  }
  .{{ $taI }} .ti-track.ti-nofit { height: auto !important; }
  .{{ $taI }} .ti-track.ti-nofit .ti-pin { position: static; }
  @media (max-width: 768px) {
    .{{ $taI }} .ti-acc.is-scroll .ti-img-in { aspect-ratio: auto; background: none; }
    .{{ $taI }} .ti-acc.is-scroll .ti-img-in img { width: 100%; height: min(32vh, 62vw); object-fit: contain; }
    .{{ $taI }} .ti-acc.is-scroll .ti-head { padding: 16px 0; }
  }
@endif
  @media (max-width: 768px) {
    .{{ $taI }} .ti-grid { grid-template-columns: 1fr; }
    .{{ $taI }} .ti-grid > .ti-side-img { order: 2; }
    .{{ $taI }} .ti-stage { display: none; }
    .{{ $taI }} .ti-inner { padding-left: 0; }
    .{{ $taI }} .ti-inner .ti-img-in { display: block; margin-bottom: 16px; }
  }
  @media (prefers-reduced-motion: reduce) { .{{ $taI }} .ti-acc * { transition: none !important; } }
</style>
@if($taScroll)<div class="ti-track" data-ti-len="{{ $taLen }}"><div class="ti-pin">@endif
@if(! empty($c['eyebrow']) || ! empty($c['heading']) || trim((string) ($c['body'] ?? '')) !== '')
  <div class="ti-intro p-ti-text">
    @if(! empty($c['eyebrow']))<div class="p-ti-eyebrow">{{ $c['eyebrow'] }}</div>@endif
    @if(! empty($c['heading']))<h2 class="p-ti-heading">{!! $headingHtml !!}</h2>@endif
    @if(trim((string) ($c['body'] ?? '')) !== '')<div class="p-ti-body">{!! $bodyHtml !!}</div>@endif
  </div>
@endif
@if(! $taItems)
  <div class="p-ti-body" style="color:{{ $textColorBody }}">Add items to this section in the editor.</div>
@else
  <div class="ti-grid ti-acc" data-ti-acc data-ti-multi="{{ $taMulti ? 1 : 0 }}" data-ti-auto="{{ $taAuto ? $taSecs : 0 }}" data-ti-scroll="{{ $taScroll ? 1 : 0 }}">
    <div class="ti-side-text ti-list">
      @foreach($taItems as $i => $it)
        @php $taOpen = $taFirst && $i === 0; $taPid = $taI . '-p' . $i; @endphp
        <div class="ti-item {{ $taOpen ? 'is-open' : '' }}" data-i="{{ $i }}">
          <button type="button" class="ti-head" aria-expanded="{{ $taOpen ? 'true' : 'false' }}" aria-controls="{{ $taPid }}">
            @if($taNums)<span class="ti-num">{{ str_pad((string) ($i + 1), 2, '0', STR_PAD_LEFT) }}</span>@endif
            <span class="ti-t">{{ $it['title'] ?? '' }}</span>
            <span class="ti-ic" aria-hidden="true"><span></span><span></span></span>
          </button>
          <div class="ti-panel" id="{{ $taPid }}" role="region"><div><div class="ti-inner">
            @if(! empty($it['image_url']))<div class="p-ti-image ti-img-in"><img src="{{ $it['image_url'] }}" alt="{{ $it['image_alt'] ?? '' }}" loading="lazy"></div>@endif
            @if(trim((string) ($it['body'] ?? '')) !== '')<div class="p-ti-body" style="color:{{ $textColorBody }}">{!! nl2br(e($it['body'])) !!}</div>@endif
            @if(trim((string) ($it['cta_label'] ?? '')) !== '')<a class="p-ti-btn p-ti-btn--primary" href="{{ $taSafe($it['cta_url'] ?? '#') }}">{{ $it['cta_label'] }}</a>@endif
          </div></div></div>
        </div>
      @endforeach
    </div>
    <div class="ti-side-img ti-stage">{!! $tiFrameOpen !!}
      <div class="p-ti-image">
        @php $taShown = false; @endphp
        @foreach($taItems as $i => $it)
          @if(! empty($it['image_url']))
            <img src="{{ $it['image_url'] }}" alt="{{ $it['image_alt'] ?? '' }}" data-i="{{ $i }}" data-cap="{{ $it['title'] ?? '' }}" class="{{ ! $taShown ? 'is-on' : '' }}" loading="lazy">
            @php $taShown = true; @endphp
          @endif
        @endforeach
        @if(! $taShown)<div class="p-ti-image-empty is-on">No image</div>@endif
      </div>
    {!! $tiFrameClose !!}</div>
  </div>
@endif
@if($taScroll)</div></div>@endif
