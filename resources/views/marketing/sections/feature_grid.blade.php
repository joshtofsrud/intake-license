{{-- Feature grid. Content: heading, subheading, columns (2|3|4), features[{icon, title, body}] --}}
@php
    $cols = (int)($c['columns'] ?? 3);
    $cols = max(2, min(4, $cols));
@endphp
<section class="{{ $padding }}" @if(!empty($section->bg_color)) style="background:{{ $section->bg_color }}" @endif>
    <div class="mk-container">
        @if(!empty($c['heading']) || !empty($c['subheading']))
            <div style="text-align:center;margin-bottom:48px;max-width:640px;margin-left:auto;margin-right:auto">
                @if(!empty($c['heading']))     <h2>{{ $c['heading'] }}</h2>     @endif
                @if(!empty($c['subheading']))  <p style="font-size:18px">{{ $c['subheading'] }}</p>  @endif
            </div>
        @endif

        <div style="display:grid;grid-template-columns:repeat({{ $cols }}, minmax(0, 1fr));gap:32px">
            @foreach(($c['features'] ?? []) as $feature)
                <div>
                    @if(!empty($feature['icon']))
                        <div style="
                            width:48px;height:48px;
                            display:flex;align-items:center;justify-content:center;
                            background:rgba(124,58,237,.08);
                            border-radius:12px;
                            font-size:24px;
                            margin-bottom:16px
                        ">{{ $feature['icon'] }}</div>
                    @endif
                    <h3 style="font-size:18px;margin-bottom:8px">{{ $feature['title'] ?? '' }}</h3>
                    <p style="margin:0;font-size:15px">{{ $feature['body'] ?? '' }}</p>
                </div>
            @endforeach
        </div>
    </div>

    <style>
        @media (max-width: 760px) {
            section.{{ $padding }} > .mk-container > div:last-child {
                grid-template-columns: 1fr !important;
            }
        }
    </style>
</section>
