{{-- Comparison table. Content: heading, competitors[], rows[{feature, values[]}] --}}
@php
    $competitors = $c['competitors'] ?? ['Us', 'Other'];
    $rows = $c['rows'] ?? [];
    $renderValue = function ($v) {
        return match (strtolower((string)$v)) {
            'yes', 'true', '1'       => '<span style="color:#10B981;font-size:18px">✓</span>',
            'no', 'false', '0', ''   => '<span style="color:#EF4444;opacity:.5;font-size:18px">✕</span>',
            'extra', 'add-on', 'paid'=> '<span style="color:#F59E0B;font-size:12px;font-weight:600">ADD-ON</span>',
            default                   => '<span style="font-size:13px">' . e($v) . '</span>',
        };
    };
@endphp
<section class="{{ $padding }}" @if(!empty($section->bg_color)) style="background:{{ $section->bg_color }}" @endif>
    <div class="mk-container">
        @if(!empty($c['heading']))
            <h2 style="text-align:center;margin-bottom:40px">{{ $c['heading'] }}</h2>
        @endif

        <div style="max-width:900px;margin:0 auto;overflow-x:auto">
            <table style="width:100%;border-collapse:collapse;background:white;border-radius:12px;overflow:hidden;border:1px solid var(--mk-border)">
                <thead>
                    <tr style="background:#F9FAFB">
                        <th style="padding:16px 20px;text-align:left;font-size:13px;font-weight:600;color:var(--mk-text-muted);text-transform:uppercase;letter-spacing:.05em">Feature</th>
                        @foreach($competitors as $i => $name)
                            <th style="
                                padding: 16px 20px;
                                text-align: center;
                                font-size: 14px;
                                font-weight: 700;
                                {{ $i === 0 ? 'color: var(--mk-accent); background: rgba(124,58,237,.05)' : 'color: var(--mk-text)' }}
                            ">{{ $name }}</th>
                        @endforeach
                    </tr>
                </thead>
                <tbody>
                    @foreach($rows as $row)
                        <tr style="border-top:1px solid var(--mk-border)">
                            <td style="padding:14px 20px;font-size:14px;font-weight:500">{{ $row['feature'] ?? '' }}</td>
                            @foreach(($row['values'] ?? []) as $i => $val)
                                <td style="padding:14px 20px;text-align:center;{{ $i === 0 ? 'background:rgba(124,58,237,.02)' : '' }}">
                                    {!! $renderValue($val) !!}
                                </td>
                            @endforeach
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </div>
</section>
