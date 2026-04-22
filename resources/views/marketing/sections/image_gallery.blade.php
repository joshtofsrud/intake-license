{{-- Image gallery. Content: heading, images[], columns, gap_size, image_shape, image_radius --}}
@php
    $cols = (int)($c['columns'] ?? 3);
    $gapPx = ['none' => 0, 'small' => 4, 'normal' => 12, 'large' => 24][$c['gap_size'] ?? 'normal'] ?? 12;
    $aspect = ['square' => '1 / 1', 'landscape' => '4 / 3', 'portrait' => '3 / 4'][$c['image_shape'] ?? 'square'] ?? '1 / 1';
    $radius = ['none' => '0', 'normal' => '8px', 'large' => '16px'][$c['image_radius'] ?? 'normal'] ?? '8px';
@endphp
<section class="{{ $padding }}" @if(!empty($inlineStyle ?? \'\')) style="{{ $inlineStyle }}" @endif>
    <div class="mk-container">
        @if(!empty($c['heading']))
            <h2 class="mk-section-title" style="text-align:center;margin-bottom:32px">{{ $c['heading'] }}</h2>
        @endif

        @if(empty($c['images']))
            <p style="text-align:center;color:var(--mk-muted);font-size:14px;opacity:.6">No images added yet.</p>
        @else
            <div style="display:grid;grid-template-columns:repeat({{ $cols }}, minmax(0, 1fr));gap:{{ $gapPx }}px">
                @foreach($c['images'] as $img)
                    <div style="
                        aspect-ratio:{{ $aspect }};
                        border-radius:{{ $radius }};
                        overflow:hidden;
                        background: rgba(255,255,255,.04);
                        border: 0.5px solid var(--mk-border);
                    ">
                        <img src="{{ is_array($img) ? ($img['url'] ?? '') : $img }}" alt="" style="width:100%;height:100%;object-fit:cover">
                    </div>
                @endforeach
            </div>
        @endif
    </div>
</section>
