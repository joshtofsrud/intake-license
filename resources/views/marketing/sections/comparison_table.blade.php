{{-- Comparison table. Content: heading, competitors[], rows[{feature, values[]}] --}}
@php
    $competitors = $c['competitors'] ?? ['Intake', 'Other'];
    $rows = $c['rows'] ?? [];
    $renderValue = function ($v) {
        return match (strtolower((string)$v)) {
            'yes', 'true', '1'       => '<span style="color:var(--mk-accent);font-size:18px;font-weight:600">✓</span>',
            'no', 'false', '0', ''   => '<span style="color:rgba(255,255,255,.3);font-size:18px">✕</span>',
            'extra', 'add-on', 'paid'=> '<span style="color:#FBBF24;font-size:11px;font-weight:600;letter-spacing:.05em;text-transform:uppercase">Add-on</span>',
            default                   => '<span style="font-size:13px;color:var(--mk-text)">' . e($v) . '</span>',
        };
    };
@endphp
<section class="{{ $padding }}" @if(!empty($inlineStyle ?? \'\')) style="{{ $inlineStyle }}" @endif>
    <div class="mk-container">
        @if(!empty($c['heading']))
            <h2 class="mk-section-title" style="text-align:center;margin-bottom:40px">{{ $c['heading'] }}</h2>
        @endif

        <div style="max-width:900px;margin:0 auto;overflow-x:auto">
            <table style="
                width:100%;
                border-collapse:collapse;
                background: rgba(255,255,255,.02);
                border-radius:12px;
                overflow:hidden;
                border:0.5px solid var(--mk-border);
            ">
                <thead>
                    <tr style="background:rgba(255,255,255,.04);border-bottom:0.5px solid var(--mk-border)">
                        <th style="padding:14px 18px;text-align:left;font-size:11px;font-weight:600;color:var(--mk-muted);text-transform:uppercase;letter-spacing:.08em">Feature</th>
                        @foreach($competitors as $i => $name)
                            <th style="
                                padding: 14px 18px;
                                text-align: center;
                                font-size: 13px;
                                font-weight: 700;
                                {{ $i === 0 ? 'color: var(--mk-accent); background: rgba(190,242,100,.06)' : 'color: var(--mk-text)' }}
                            ">{{ $name }}</th>
                        @endforeach
                    </tr>
                </thead>
                <tbody>
                    @foreach($rows as $row)
                        <tr style="border-top:0.5px solid var(--mk-border)">
                            <td style="padding:14px 18px;font-size:14px;font-weight:500;color:var(--mk-text)">{{ $row['feature'] ?? '' }}</td>
                            @foreach(($row['values'] ?? []) as $i => $val)
                                <td style="padding:14px 18px;text-align:center;{{ $i === 0 ? 'background:rgba(190,242,100,.04)' : '' }}">
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
