{{-- Block: Studio coloured tiles (MARKER-DASH-STUDIO). Each tile is a link to
     the page it sums up, and only the tiles this person's role can open are
     drawn. Charts are inline SVG and CSS; the tile colour comes from its tone. --}}
@php $sdTiles = $studio['tiles'] ?? []; @endphp
@if ($sdTiles)
<section class="sd-tiles sd-n{{ count($sdTiles) }}" data-sd-live="tiles">
  @foreach ($sdTiles as $i => $t)
    @php $c = $t['chart'] ?? []; @endphp
    <a class="sd-tile sd-t{{ $t['tone'] }} sd-in" style="--d:{{ $i * 90 }}ms" href="{{ $t['link'] }}" aria-label="{{ $t['title'] }}: {{ $t['pre'] }}{{ number_format($t['value']) }}, {{ $t['sub'] }}">
      <div class="sd-tile-h"><span>{{ $t['title'] }}</span><i aria-hidden="true">↗</i></div>
      <div class="sd-big"><b data-count="{{ $t['value'] }}" data-pre="{{ $t['pre'] }}">{{ $t['pre'] }}{{ number_format($t['value']) }}</b><small>{{ $t['sub'] }}</small></div>

      @if (($c['type'] ?? '') === 'bars')
        <div class="sd-bars" aria-hidden="true">
          @foreach ($c['bars'] as $b)
            <span style="--h:{{ max(4, round($b['h'] * 100)) }}%" class="{{ $b['now'] ? 'now' : '' }}{{ $b['n'] ? '' : ' zero' }}" title="{{ $b['label'] }}: {{ $b['n'] }}"></span>
          @endforeach
        </div>
        <div class="sd-axis" aria-hidden="true"><span>{{ $c['axis'][0] }}</span><span>{{ $c['axis'][1] }}</span><span>{{ $c['axis'][2] }}</span></div>

      @elseif (($c['type'] ?? '') === 'meter')
        @php $pct = $c['max'] ? min(100, round($c['used'] / $c['max'] * 100)) : 0; @endphp
        <div class="sd-meter" aria-hidden="true"><span style="--w:{{ $c['max'] ? max(2, $pct) : 0 }}%"></span></div>
        <div class="sd-axis"><span>{{ $c['label'] }}</span></div>

      @elseif (($c['type'] ?? '') === 'spark')
        @php
          $all = array_merge($c['cur'], $c['prev']); $mx = max(1, $all ? max($all) : 1); $n = max(2, $c['slots']);
          $pts = fn ($vals) => implode(' L', array_map(fn ($v, $k) => round($k * 300 / ($n - 1), 1) . ' ' . round(64 - ($v / $mx) * 56, 1), $vals, array_keys($vals)));
          $lastK = count($c['cur']) - 1;
        @endphp
        <svg class="sd-spark" viewBox="0 0 300 70" preserveAspectRatio="none" aria-hidden="true">
          @if (count($c['prev']) > 1)<path class="prev" d="M{{ $pts($c['prev']) }}"/>@endif
          @if (count($c['cur']) > 1)<path class="cur" pathLength="100" d="M{{ $pts($c['cur']) }}"/>@endif
          @if ($lastK >= 0)<circle cx="{{ round($lastK * 300 / ($n - 1), 1) }}" cy="{{ round(64 - ($c['cur'][$lastK] / $mx) * 56, 1) }}" r="4"/>@endif
        </svg>
        <div class="sd-axis" aria-hidden="true"><span>{{ $c['axis'][0] }}</span><span>{{ $c['axis'][1] }}</span></div>

      @elseif (($c['type'] ?? '') === 'stack')
        @php $sum = max(1, array_sum(array_column($c['parts'], 'n'))); @endphp
        <div class="sd-stack" aria-hidden="true">
          @foreach ($c['parts'] as $pi => $p)
            @if ($p['n'] > 0)<span class="p{{ $pi + 1 }}" style="--w:{{ round($p['n'] / $sum * 100, 1) }}%">{{ $p['n'] }}</span>@endif
          @endforeach
          @if (array_sum(array_column($c['parts'], 'n')) === 0)<span class="p0" style="--w:100%"></span>@endif
        </div>
        <div class="sd-key">@foreach ($c['parts'] as $pi => $p)<span><i class="p{{ $pi + 1 }}"></i>{{ $p['label'] }}</span>@endforeach</div>

      @elseif (($c['type'] ?? '') === 'ring')
        <div class="sd-ring">
          <svg viewBox="0 0 36 36" aria-hidden="true"><circle cx="18" cy="18" r="15.5"/>@if ($c['pct'] > 0)<circle class="v" cx="18" cy="18" r="15.5" pathLength="100" style="--p:{{ $c['pct'] }}"/>@endif</svg>
          <div><b>{{ $c['pct'] }}%</b><span>{{ $c['label'] }}</span></div>
        </div>
      @endif
    </a>
  @endforeach
</section>
@endif
