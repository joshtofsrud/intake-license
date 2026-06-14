{{-- MARKER-PATCH-297 — image_gallery public render (enriched). --}}
@php
  $images = $c['images'] ?? [];
  if (is_string($images)) { $dd = json_decode($images, true); $images = is_array($dd) ? $dd : []; }
  if (!is_array($images)) $images = [];

  $cols   = (int)($c['columns'] ?? 3);
  $shape  = $c['image_shape'] ?? 'square';
  $aspect = ['square'=>'1 / 1','landscape'=>'4 / 3','portrait'=>'3 / 4','auto'=>'auto'][$shape] ?? '1 / 1';
  $gap    = ['tight'=>'6px','normal'=>'12px','loose'=>'20px'][$c['gap'] ?? 'normal'] ?? '12px';
  $radius = ($c['radius'] ?? 'default') === 'default'
              ? 'var(--p-r)'
              : (['none'=>'0','sm'=>'6px','md'=>'12px','lg'=>'20px'][$c['radius'] ?? ''] ?? 'var(--p-r)');
  $hover        = ($c['hover_zoom'] ?? true) ? true : false;
  $showCaptions = !empty($c['show_captions']);
  $heading      = trim($c['heading'] ?? '');
  $sub          = trim($c['subheading'] ?? '');
  $uid          = 'g' . substr(md5(($section->id ?? '') . ($heading ?: 'gal')), 0, 6);
@endphp

<style>
.{{ $uid }}-grid { display:grid; grid-template-columns: repeat({{ $cols }}, 1fr); gap: {{ $gap }}; }
.{{ $uid }}-item { @if($aspect !== 'auto') aspect-ratio: {{ $aspect }}; @endif overflow:hidden; border-radius: {{ $radius }}; background: rgba(0,0,0,.06); }
.{{ $uid }}-item img { width:100%; object-fit:cover; display:block; transition: transform .3s; @if($aspect === 'auto') height:auto; @else height:100%; @endif }
@if($hover).{{ $uid }}-item:hover img { transform: scale(1.04); }@endif
.{{ $uid }}-fig { margin:0; }
.{{ $uid }}-cap { font-size:13px; opacity:.7; margin-top:6px; }
@media (max-width:768px){ .{{ $uid }}-grid{ grid-template-columns:1fr 1fr; } }
@media (max-width:480px){ .{{ $uid }}-grid{ grid-template-columns:1fr; } }
</style>

<section class="p-section--tight"@if(!empty($c['anchor_id'])) id="{{ $c['anchor_id'] }}"@endif>
  <div class="p-container">
    @if($heading !== '' || $sub !== '')
      <div style="margin-bottom:18px">
        @if($heading !== '')<h2 style="font-size:26px;font-weight:700;line-height:1.15;margin:0 0 6px">{{ $heading }}</h2>@endif
        @if($sub !== '')<p style="opacity:.7;margin:0;max-width:60ch">{{ $sub }}</p>@endif
      </div>
    @endif

    @if(!empty($images))
      <div class="{{ $uid }}-grid">
        @foreach($images as $img)
          @php
            $url = is_array($img) ? ($img['url'] ?? '') : $img;
            $cap = is_array($img) ? ($img['caption'] ?? '') : '';
            $alt = is_array($img) ? ($img['alt'] ?? '') : '';
          @endphp
          @if($url !== '')
            <figure class="{{ $uid }}-fig">
              <div class="{{ $uid }}-item"><img src="{{ $url }}" alt="{{ $alt }}" loading="lazy"></div>
              @if($showCaptions && $cap !== '')<figcaption class="{{ $uid }}-cap">{{ $cap }}</figcaption>@endif
            </figure>
          @endif
        @endforeach
      </div>
    @else
      <div class="{{ $uid }}-grid">
        @for($i = 0; $i < $cols; $i++)
          <div class="{{ $uid }}-item" style="display:flex;align-items:center;justify-content:center;font-size:13px;opacity:.25">Image</div>
        @endfor
      </div>
    @endif
  </div>
</section>
