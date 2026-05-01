<x-filament-widgets::widget>
    <x-filament::section>
        <div wire:poll.30s style="color: #f0f0f0;">

            <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:14px;gap:14px;flex-wrap:wrap;">
                <div>
                    <div style="font-size:14px;font-weight:800;letter-spacing:-0.01em;display:inline-flex;align-items:center;gap:8px;">
                        <span style="background:#1a1a1a;border:1px solid #1f1f1f;border-radius:6px;width:22px;height:22px;display:inline-flex;align-items:center;justify-content:center;font-size:12px;">⚡</span>
                        Server health
                    </div>
                    <div style="font-size:11.5px;color:#888;font-weight:500;margin-top:2px;">droplet · refreshes every 30s</div>
                </div>
                <div style="font-size:11px;color:#888;">
                    @if(!empty($health['uptime']))
                        <strong style="color:#f0f0f0;font-weight:600;">Uptime</strong> {{ $health['uptime'] }}
                    @endif
                </div>
            </div>

            <div style="display:grid;grid-template-columns:1.4fr 1fr 1fr 1fr 1fr;gap:12px;">

                @php
                    $statusColors = [
                        'ok'   => ['bg' => 'rgba(52,211,153,0.15)',  'fg' => '#34D399', 'fill' => '#34D399'],
                        'warn' => ['bg' => 'rgba(245,158,11,0.15)',  'fg' => '#F59E0B', 'fill' => '#F59E0B'],
                        'err'  => ['bg' => 'rgba(239,68,68,0.15)',   'fg' => '#EF4444', 'fill' => '#EF4444'],
                    ];
                    $cardStyle = 'background:#1a1a1a;border:1px solid #1f1f1f;border-radius:10px;padding:14px 16px;position:relative;overflow:hidden;';
                    $labelStyle = 'font-size:10px;text-transform:uppercase;letter-spacing:0.08em;color:#888;font-weight:700;margin-bottom:6px;display:flex;align-items:center;justify-content:space-between;';
                    $valueStyle = "font-size:22px;font-weight:800;letter-spacing:-0.02em;line-height:1;font-feature-settings:'tnum';";
                    $unitStyle = 'font-size:13px;font-weight:600;color:#888;margin-left:4px;';
                    $detailStyle = "font-size:10.5px;color:#888;margin-top:6px;font-feature-settings:'tnum';";
                    $barOuter = 'margin-top:10px;height:5px;border-radius:3px;background:#202020;overflow:hidden;position:relative;';
                    $pillBase = 'font-size:9px;font-weight:800;text-transform:uppercase;letter-spacing:0.08em;padding:2px 6px;border-radius:3px;';
                @endphp

                {{-- CPU --}}
                <div style="{{ $cardStyle }}">
                    <div style="{{ $labelStyle }}">
                        <span>CPU load (1m)</span>
                        @if($health['cpu']['available'])
                            @php $c = $statusColors[$health['cpu']['status']] ?? $statusColors['ok']; @endphp
                            <span style="{{ $pillBase }}background:{{ $c['bg'] }};color:{{ $c['fg'] }};">{{ strtoupper($health['cpu']['status']) }}</span>
                        @endif
                    </div>
                    @if($health['cpu']['available'])
                        <div style="{{ $valueStyle }}">{{ number_format($health['cpu']['load_1m'], 2) }}</div>
                        <div style="{{ $detailStyle }}">
                            <strong style="color:#c8c8c8;font-weight:600;">5m</strong> {{ number_format($health['cpu']['load_5m'], 2) }} ·
                            <strong style="color:#c8c8c8;font-weight:600;">15m</strong> {{ number_format($health['cpu']['load_15m'], 2) }} ·
                            {{ $health['cpu']['cores'] }} cores
                        </div>
                        <div style="{{ $barOuter }}">
                            <div style="position:absolute;top:0;left:0;bottom:0;border-radius:3px;transition:width 0.4s ease;width:{{ $health['cpu']['load_pct'] }}%;background:{{ $c['fill'] }};"></div>
                        </div>
                    @else
                        <div style="font-size:13px;color:#5a5a5a;font-style:italic;">Unavailable</div>
                    @endif
                </div>

                {{-- Memory --}}
                <div style="{{ $cardStyle }}">
                    <div style="{{ $labelStyle }}">
                        <span>Memory</span>
                        @if($health['memory']['available'])
                            @php $c = $statusColors[$health['memory']['status']] ?? $statusColors['ok']; @endphp
                            <span style="{{ $pillBase }}background:{{ $c['bg'] }};color:{{ $c['fg'] }};">{{ strtoupper($health['memory']['status']) }}</span>
                        @endif
                    </div>
                    @if($health['memory']['available'])
                        <div style="{{ $valueStyle }}">{{ $health['memory']['used_gb'] }}<span style="{{ $unitStyle }}">GB</span></div>
                        <div style="{{ $barOuter }}">
                            <div style="position:absolute;top:0;left:0;bottom:0;border-radius:3px;width:{{ $health['memory']['pct'] }}%;background:{{ $c['fill'] }};"></div>
                        </div>
                        <div style="{{ $detailStyle }}">
                            <strong style="color:#c8c8c8;font-weight:600;">{{ $health['memory']['pct'] }}%</strong> of {{ $health['memory']['total_gb'] }} GB
                        </div>
                    @else
                        <div style="font-size:13px;color:#5a5a5a;font-style:italic;">Unavailable</div>
                    @endif
                </div>

                {{-- Disk --}}
                <div style="{{ $cardStyle }}">
                    <div style="{{ $labelStyle }}">
                        <span>Disk</span>
                        @if($health['disk']['available'])
                            @php $c = $statusColors[$health['disk']['status']] ?? $statusColors['ok']; @endphp
                            <span style="{{ $pillBase }}background:{{ $c['bg'] }};color:{{ $c['fg'] }};">{{ strtoupper($health['disk']['status']) }}</span>
                        @endif
                    </div>
                    @if($health['disk']['available'])
                        <div style="{{ $valueStyle }}">{{ $health['disk']['used_gb'] }}<span style="{{ $unitStyle }}">GB</span></div>
                        <div style="{{ $barOuter }}">
                            <div style="position:absolute;top:0;left:0;bottom:0;border-radius:3px;width:{{ $health['disk']['pct'] }}%;background:{{ $c['fill'] }};"></div>
                        </div>
                        <div style="{{ $detailStyle }}">
                            <strong style="color:#c8c8c8;font-weight:600;">{{ $health['disk']['pct'] }}%</strong> of {{ $health['disk']['total_gb'] }} GB
                        </div>
                    @else
                        <div style="font-size:13px;color:#5a5a5a;font-style:italic;">Unavailable</div>
                    @endif
                </div>

                {{-- PHP-FPM --}}
                <div style="{{ $cardStyle }}">
                    <div style="{{ $labelStyle }}">
                        <span>PHP-FPM</span>
                        @if($health['php_fpm']['available'])
                            @php $c = $statusColors[$health['php_fpm']['status']] ?? $statusColors['ok']; @endphp
                            <span style="{{ $pillBase }}background:{{ $c['bg'] }};color:{{ $c['fg'] }};">{{ strtoupper($health['php_fpm']['status']) }}</span>
                        @endif
                    </div>
                    @if($health['php_fpm']['available'])
                        <div style="{{ $valueStyle }}">{{ $health['php_fpm']['workers'] }}<span style="{{ $unitStyle }}">/ {{ $health['php_fpm']['max'] }} active</span></div>
                        <div style="{{ $barOuter }}">
                            <div style="position:absolute;top:0;left:0;bottom:0;border-radius:3px;width:{{ $health['php_fpm']['pct'] }}%;background:{{ $c['fill'] }};"></div>
                        </div>
                        <div style="{{ $detailStyle }}">workers + 1 master</div>
                    @else
                        <div style="font-size:13px;color:#5a5a5a;font-style:italic;">Unavailable</div>
                    @endif
                </div>

                {{-- DB --}}
                <div style="{{ $cardStyle }}">
                    <div style="{{ $labelStyle }}">
                        <span>Database</span>
                        @if($health['db']['available'])
                            @php $c = $statusColors[$health['db']['status']] ?? $statusColors['ok']; @endphp
                            <span style="{{ $pillBase }}background:{{ $c['bg'] }};color:{{ $c['fg'] }};">{{ strtoupper($health['db']['status']) }}</span>
                        @endif
                    </div>
                    @if($health['db']['available'])
                        <div style="{{ $valueStyle }}">{{ $health['db']['connections'] }}<span style="{{ $unitStyle }}">conns</span></div>
                        <div style="{{ $barOuter }}">
                            <div style="position:absolute;top:0;left:0;bottom:0;border-radius:3px;width:{{ $health['db']['pct'] }}%;background:{{ $c['fill'] }};"></div>
                        </div>
                        <div style="{{ $detailStyle }}">
                            <strong style="color:#c8c8c8;font-weight:600;">{{ $health['db']['pct'] }}%</strong> of {{ $health['db']['max'] }} max · {{ $health['db']['query_ms'] }}ms query
                        </div>
                    @else
                        <div style="font-size:13px;color:#5a5a5a;font-style:italic;">Unavailable</div>
                    @endif
                </div>

            </div>
        </div>
    </x-filament::section>
</x-filament-widgets::widget>
