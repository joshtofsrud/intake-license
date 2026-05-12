{{--
    Screen showcase — step-by-step section showing desktop + mobile side by side.

    Content schema:
      eyebrow        string
      step_num       int|string
      heading        string
      body           string
      points[]       string[]
      desktop_label  string
      desktop_lines[]  {label, value?, muted?, badge?, badge_color?, accent?}
      mobile_label   string
      mobile_lines[]   {label, value?, muted?, badge?, badge_color?, selected?, divider?}
      mobile_note    string
      flip           bool   — if true, screens left / text right (alternates rhythm)
--}}
@php
    $points       = $c['points'] ?? [];
    $desktopLines = $c['desktop_lines'] ?? [];
    $mobileLines  = $c['mobile_lines'] ?? [];
    $flip         = !empty($c['flip']);

    $badgeStyle = function(string $color): string {
        return match($color) {
            'green'  => 'background:rgba(190,242,100,.12);color:#BEF264;',
            'blue'   => 'background:rgba(56,138,221,.15);color:#85B7EB;',
            'amber'  => 'background:rgba(186,117,23,.2);color:#EF9F27;',
            'purple' => 'background:rgba(168,85,247,.15);color:#C084FC;',
            'red'    => 'background:rgba(239,68,68,.15);color:#F87171;',
            default  => 'background:rgba(255,255,255,.08);color:rgba(255,255,255,.6);',
        };
    };
@endphp

<style>
.sc-wrap{display:grid;grid-template-columns:1fr 1fr;gap:clamp(32px,5vw,72px);align-items:start;padding:clamp(48px,6vw,80px) 0;border-bottom:.5px solid var(--mk-border)}
.sc-wrap:last-of-type{border-bottom:none}
.sc-step-num{display:inline-flex;align-items:center;justify-content:center;width:32px;height:32px;border-radius:50%;background:var(--mk-accent);color:var(--mk-accent-text);font-size:13px;font-weight:700;margin-bottom:14px}
.sc-heading{font-size:clamp(20px,2.5vw,28px);font-weight:700;letter-spacing:-.02em;line-height:1.2;margin-bottom:10px}
.sc-body{font-size:14px;color:var(--mk-muted);line-height:1.7;margin-bottom:16px}
.sc-points{display:flex;flex-direction:column;gap:8px}
.sc-point{font-size:13px;color:rgba(255,255,255,.6);display:flex;align-items:flex-start;gap:8px;line-height:1.45}
.sc-point-dot{width:4px;height:4px;border-radius:50%;background:var(--mk-accent);flex-shrink:0;margin-top:8px}
.sc-screens{display:grid;grid-template-columns:1fr 1fr;gap:14px;align-items:start}
.sc-screen-label{font-size:10px;text-transform:uppercase;letter-spacing:.08em;color:var(--mk-dim);font-weight:600;margin-bottom:8px;text-align:center}
.sc-desktop{background:rgba(255,255,255,.02);border:.5px solid var(--mk-border);border-radius:10px;overflow:hidden}
.sc-desktop-bar{background:#0a0a0a;padding:8px 12px;display:flex;align-items:center;gap:6px;border-bottom:.5px solid var(--mk-border)}
.sc-desktop-dot{width:7px;height:7px;border-radius:50%}
.sc-desktop-body{padding:12px}
.sc-desktop-row{display:flex;align-items:center;justify-content:space-between;padding:7px 0;border-bottom:.5px solid rgba(255,255,255,.05);font-size:11px}
.sc-desktop-row:last-child{border-bottom:none}
.sc-desktop-label{color:rgba(255,255,255,.55)}
.sc-desktop-value{color:var(--mk-text);font-weight:500;text-align:right}
.sc-desktop-accent{color:var(--mk-accent);font-weight:600}
.sc-badge{font-size:9px;font-weight:600;padding:2px 6px;border-radius:4px}
.sc-phone{background:#0e0e0e;border-radius:20px;border:1.5px solid rgba(255,255,255,.15);overflow:hidden;margin:0 auto;max-width:160px}
.sc-phone-bar{height:24px;background:#0a0a0a;display:flex;align-items:center;justify-content:center}
.sc-phone-time{font-size:9px;font-weight:600;color:var(--mk-text)}
.sc-phone-body{padding:10px}
.sc-phone-row{padding:6px 0;border-bottom:.5px solid rgba(255,255,255,.06);font-size:10px}
.sc-phone-row:last-child{border-bottom:none}
.sc-phone-row-inner{display:flex;align-items:center;justify-content:space-between}
.sc-phone-label{color:rgba(255,255,255,.7);font-weight:500}
.sc-phone-value{color:var(--mk-muted)}
.sc-phone-muted{font-size:9px;color:var(--mk-dim);margin-top:2px}
.sc-phone-selected{background:rgba(190,242,100,.08);border:.5px solid rgba(190,242,100,.3);border-radius:6px;padding:6px 8px;margin-bottom:5px}
.sc-phone-selected .sc-phone-label{color:var(--mk-accent)}
.sc-note{font-size:11px;color:var(--mk-dim);text-align:center;margin-top:6px}
@media(max-width:860px){.sc-wrap{grid-template-columns:1fr}.sc-screens{grid-template-columns:1fr 1fr}}
@media(max-width:480px){.sc-screens{grid-template-columns:1fr}}
</style>

<div class="sc-wrap" style="{{ $flip ? 'direction:rtl' : '' }}">
    {{-- Text column --}}
    <div style="{{ $flip ? 'direction:ltr' : '' }}">
        @if(!empty($c['eyebrow']))
            <div class="mk-eyebrow">{{ $c['eyebrow'] }}</div>
        @endif
        @if(!empty($c['step_num']))
            <div class="sc-step-num">{{ $c['step_num'] }}</div>
        @endif
        <div class="sc-heading">{{ $c['heading'] ?? '' }}</div>
        @if(!empty($c['body']))
            <p class="sc-body">{{ $c['body'] }}</p>
        @endif
        @if(!empty($points))
            <div class="sc-points">
                @foreach($points as $pt)
                    <div class="sc-point"><div class="sc-point-dot"></div>{{ $pt }}</div>
                @endforeach
            </div>
        @endif
    </div>

    {{-- Screens column --}}
    <div style="{{ $flip ? 'direction:ltr' : '' }}">
        <div class="sc-screens">

            {{-- Desktop panel --}}
            <div>
                @if(!empty($c['desktop_label']))
                    <div class="sc-screen-label">{{ $c['desktop_label'] }}</div>
                @endif
                <div class="sc-desktop">
                    <div class="sc-desktop-bar">
                        <div class="sc-desktop-dot" style="background:#FF5F57"></div>
                        <div class="sc-desktop-dot" style="background:#FEBC2E"></div>
                        <div class="sc-desktop-dot" style="background:#28C840"></div>
                        <div style="flex:1;height:14px;background:rgba(255,255,255,.04);border-radius:3px;margin-left:6px"></div>
                    </div>
                    <div class="sc-desktop-body">
                        @foreach($desktopLines as $row)
                            @if(!empty($row['section']))
                                <div style="font-size:9px;text-transform:uppercase;letter-spacing:.07em;color:var(--mk-dim);padding:8px 0 4px;font-weight:600;border-bottom:.5px solid rgba(255,255,255,.05)">
                                    {{ $row['label'] }}
                                </div>
                            @else
                                <div class="sc-desktop-row">
                                    <span class="sc-desktop-label">{{ $row['label'] ?? '' }}</span>
                                    @if(!empty($row['badge']))
                                        <span class="sc-badge" style="{{ $badgeStyle($row['badge_color'] ?? 'default') }}">{{ $row['badge'] }}</span>
                                    @elseif(!empty($row['value']))
                                        <span class="{{ !empty($row['accent']) ? 'sc-desktop-accent' : 'sc-desktop-value' }}">
                                            {{ $row['value'] }}
                                        </span>
                                    @endif
                                </div>
                            @endif
                        @endforeach
                    </div>
                </div>
            </div>

            {{-- Mobile phone --}}
            <div>
                @if(!empty($c['mobile_label']))
                    <div class="sc-screen-label">{{ $c['mobile_label'] }}</div>
                @endif
                <div class="sc-phone">
                    <div class="sc-phone-bar">
                        <span class="sc-phone-time">9:41</span>
                    </div>
                    <div class="sc-phone-body">
                        @foreach($mobileLines as $row)
                            @if(!empty($row['selected']))
                                <div class="sc-phone-selected">
                                    <div class="sc-phone-row-inner">
                                        <span class="sc-phone-label">{{ $row['label'] ?? '' }}</span>
                                        @if(!empty($row['badge']))
                                            <span class="sc-badge" style="{{ $badgeStyle($row['badge_color'] ?? 'green') }}">{{ $row['badge'] }}</span>
                                        @elseif(!empty($row['value']))
                                            <span style="font-size:10px;color:var(--mk-accent);font-weight:600">{{ $row['value'] }}</span>
                                        @endif
                                    </div>
                                    @if(!empty($row['muted']))
                                        <div class="sc-phone-muted">{{ $row['muted'] }}</div>
                                    @endif
                                </div>
                            @elseif(!empty($row['divider']))
                                <div style="height:.5px;background:rgba(255,255,255,.06);margin:4px 0"></div>
                            @else
                                <div class="sc-phone-row">
                                    <div class="sc-phone-row-inner">
                                        <span class="sc-phone-label">{{ $row['label'] ?? '' }}</span>
                                        @if(!empty($row['badge']))
                                            <span class="sc-badge" style="{{ $badgeStyle($row['badge_color'] ?? 'default') }}">{{ $row['badge'] }}</span>
                                        @elseif(!empty($row['value']))
                                            <span class="sc-phone-value">{{ $row['value'] }}</span>
                                        @endif
                                    </div>
                                    @if(!empty($row['muted']))
                                        <div class="sc-phone-muted">{{ $row['muted'] }}</div>
                                    @endif
                                </div>
                            @endif
                        @endforeach
                    </div>
                </div>
                @if(!empty($c['mobile_note']))
                    <div class="sc-note">{{ $c['mobile_note'] }}</div>
                @endif
            </div>

        </div>
    </div>
</div>
