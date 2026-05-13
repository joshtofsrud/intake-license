{{-- Mini dashboard preview rendering with the passed-in token values.
     $theme: 'b' or 'c'
     $tokens: array of token_key => value from form state. --}}

@php
    // Fallbacks if a token is missing in the form data.
    $t = $tokens;
    $g = fn($k, $default) => $t[$k] ?? $default;

    // Default fallback values (slate-steel for b, dark premium for c).
    if ($theme === 'b') {
        $defaults = [
            'ia-bg' => '#F7F8FA', 'ia-surface' => '#FFFFFF',
            'ia-border' => 'rgba(15,20,25,.10)',
            'ia-text' => '#0F1419', 'ia-text-muted' => 'rgba(15,20,25,.62)',
            'ia-side-bg' => '#1E2A3A', 'ia-side-text' => 'rgba(255,255,255,.5)',
            'ia-side-active-bg' => 'rgba(255,255,255,.08)',
            'ia-side-active-text' => '#f5f5f5',
        ];
    } else {
        $defaults = [
            'ia-bg' => '#0d0d0d', 'ia-surface' => '#1c1c1c',
            'ia-border' => 'rgba(255,255,255,.13)',
            'ia-text' => '#f0f0f0', 'ia-text-muted' => 'rgba(255,255,255,.78)',
            'ia-side-bg' => '#0c0c0c', 'ia-side-text' => 'rgba(255,255,255,.4)',
            'ia-side-active-bg' => 'rgba(255,255,255,.08)',
            'ia-side-active-text' => '#f0f0f0',
        ];
    }

    $bg     = $g('ia-bg',     $defaults['ia-bg']);
    $surf   = $g('ia-surface', $defaults['ia-surface']);
    $bdr    = $g('ia-border', $defaults['ia-border']);
    $text   = $g('ia-text',   $defaults['ia-text']);
    $muted  = $g('ia-text-muted', $defaults['ia-text-muted']);
    $sideBg = $g('ia-side-bg', $defaults['ia-side-bg']);
    $sideTx = $g('ia-side-text', $defaults['ia-side-text']);
    $sideActBg = $g('ia-side-active-bg', $defaults['ia-side-active-bg']);
    $sideActTx = $g('ia-side-active-text', $defaults['ia-side-active-text']);
    $accent = '#3B5A78'; // platform default
@endphp

<div style="background: {{ $bg }}; border: 1px solid {{ $bdr }};
            border-radius: 12px; overflow: hidden;
            box-shadow: 0 6px 22px rgba(0,0,0,.10);
            color: {{ $text }}; font-size: 12px;">
    <div style="display: grid; grid-template-columns: 140px 1fr; min-height: 320px;">

        {{-- Sidebar --}}
        <div style="background: {{ $sideBg }}; padding: 12px 0; color: {{ $sideTx }};">
            <div style="display: flex; align-items: center; gap: 8px;
                        padding: 4px 14px 14px;
                        border-bottom: 1px solid rgba(255,255,255,.06);
                        margin-bottom: 8px;">
                <div style="width: 20px; height: 20px; background: {{ $accent }};
                            color: #fff; border-radius: 4px;
                            display: flex; align-items: center; justify-content: center;
                            font-size: 10px; font-weight: 700;">M</div>
                <div style="color: #fff; font-weight: 500; font-size: 11.5px;">Mountainview</div>
            </div>
            <div style="padding: 6px 12px; font-size: 11px;
                        background: {{ $sideActBg }}; color: {{ $sideActTx }};
                        border-left: 2px solid {{ $accent }};">Dashboard</div>
            <div style="padding: 6px 14px; font-size: 11px;">Register</div>
            <div style="padding: 6px 14px; font-size: 11px;">Schedule</div>
            <div style="padding: 6px 14px; font-size: 11px;">Customers</div>
        </div>

        {{-- Main --}}
        <div style="padding: 16px 18px;">
            <div style="font-size: 15px; font-weight: 700; letter-spacing: -.01em;
                        margin-bottom: 12px;">Dashboard</div>

            {{-- Stats --}}
            <div style="display: grid; grid-template-columns: repeat(3, 1fr); gap: 8px; margin-bottom: 12px;">
                @foreach([['Revenue','$485'],['Appts','3/6'],['Walk-ins','2']] as $stat)
                    <div style="background: {{ $surf }}; border: 1px solid {{ $bdr }};
                                border-radius: 8px; padding: 10px 12px;">
                        <div style="font-size: 9px; text-transform: uppercase;
                                    letter-spacing: .1em; opacity: .5; font-weight: 600;
                                    margin-bottom: 4px;">{{ $stat[0] }}</div>
                        <div style="font-size: 15px; font-weight: 700; letter-spacing: -.01em;">{{ $stat[1] }}</div>
                    </div>
                @endforeach
            </div>

            {{-- Appointment rows --}}
            <div style="background: {{ $surf }}; border: 1px solid {{ $bdr }}; border-radius: 8px;">
                @foreach([['9:00','Sarah C.','Tune-up','Paid'],['10:30','Marcus R.','Brake','Pay']] as $row)
                    <div style="display: grid; grid-template-columns: 44px 1fr auto;
                                gap: 10px; align-items: center;
                                padding: 8px 12px;
                                border-bottom: 1px solid {{ $bdr }};">
                        <div style="font-weight: 600; font-variant-numeric: tabular-nums; font-size: 11px;">{{ $row[0] }}</div>
                        <div>
                            <div style="font-weight: 500; font-size: 11.5px;">{{ $row[1] }}</div>
                            <div style="font-size: 10px; color: {{ $muted }};">{{ $row[2] }}</div>
                        </div>
                        <div style="background: {{ $accent }}; color: #fff;
                                    padding: 3px 9px; border-radius: 4px;
                                    font-size: 9.5px; font-weight: 600;">{{ $row[3] }}</div>
                    </div>
                @endforeach
            </div>
        </div>
    </div>
</div>
