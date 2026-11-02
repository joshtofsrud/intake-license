{{-- One panel on an engine-driven Reports tab. $p is built by TabPanels; every cell was escaped there. (MARKER-REPORT-TABS-ENGINE) --}}
<div class="rb-sec" id="rx-sec-{{ $p['id'] }}">
  <div class="rb-h">
    <h2>{{ $p['title'] }}</h2><span class="rb-hint">{{ $p['hint'] }}</span>
    @if(!empty($p['cfg']))
      <span class="rx-acts">
        <button class="rb-link" type="button" data-rx-explain="{{ $p['id'] }}" data-cfg="{{ json_encode($p['cfg']) }}" data-title="{{ $p['title'] }}">Explain</button>
        <button class="rb-link" type="button" data-rx-open="{{ $p['id'] }}">Open in builder →</button>
      </span>
    @endif
  </div>
  <div id="rx-ex-{{ $p['id'] }}"></div>

  @if(!empty($p['note']))
    <div class="rb-note"><div>{{ $p['note'] }}</div></div>
  @endif

  @if(!empty($p['figs']))
    <div class="rb-strip">
      @foreach($p['figs'] as $f)
        <div class="rb-fig"><div class="l">{{ $f['l'] }}</div><div class="v">{{ $f['v'] }}</div><div class="d {{ $f['cls'] }}">{!! $f['d'] !== '' ? e($f['d']) : '&nbsp;' !!}</div></div>
      @endforeach
    </div>
  @endif

  @if(!empty($p['bar']))
    <div class="rb-stack">@foreach($p['bar'] as $s)<span style="background:{{ $s['color'] }};flex:{{ max(0.0001, $s['flex']) }}"></span>@endforeach</div>
    <div class="rb-key" style="margin-bottom:22px">@foreach($p['bar'] as $s)<span><i style="background:{{ $s['color'] }}"></i>{{ $s['label'] }}</span>@endforeach</div>
  @endif

  @if(!empty($p['recon']))
    <div class="rb-lbl">How this reconciles</div>
    <div class="rx-recon">
      @foreach($p['recon']['rows'] as [$name, $why, $val])
        <div class="r"><span>{{ $name }}<span class="m">{{ $why }}</span></span><span>{{ $val }}</span></div>
      @endforeach
      <div class="r t"><span>Payments received</span><span>{{ $p['recon']['total'] }}</span></div>
    </div>
  @endif

  @if(!empty($p['table']))
    @php $t = $p['table']; @endphp
    @if($t['locked'])
      @php
        $fake = [];
        for ($i = 0; $i < $t['fake']; $i++) {
          $r = [];
          foreach ($t['head'] as $j => $h) $r[] = $j ? 34 + (($i * 13 + $j * 7) % 26) : 90 + (($i * 17) % 50);
          $fake[] = $r;
        }
      @endphp
      <div class="rb-lock">
        <div class="rb-tbl"><table class="ia-table rx-tbl">
          <thead><tr>@foreach($t['head'] as $j => $h)<th class="{{ $j ? 'num' : '' }}">{{ $h }}</th>@endforeach</tr></thead>
          <tbody>@foreach($fake as $r)<tr>@foreach($r as $j => $w)<td class="{{ $j ? 'num' : '' }}"><span class="rb-ph" style="width:{{ $w }}px"></span></td>@endforeach</tr>@endforeach</tbody>
        </table></div>
        <div class="rb-lockbadge"><span><b>Branded feature</b> — see your real {{ $t['what'] }}<button type="button" data-up="{{ $t['what'] }}">Upgrade</button></span></div>
      </div>
    @elseif(empty($t['rows']))
      <div class="rb-note"><div>{{ $t['empty'] ?? 'Nothing in this range.' }} <a href="{{ route('tenant.reports.' . $tab, ['range' => 'last_90', 'compare' => $q['compare']]) }}" class="rb-link">Try the last 90 days</a></div></div>
    @else
      <div class="rb-tbl"><table class="ia-table rx-tbl">
        <thead><tr>@foreach($t['head'] as $j => $h)<th class="{{ $j ? 'num' : '' }}">{{ $h }}</th>@endforeach</tr></thead>
        <tbody>
          @foreach($t['rows'] as $r)<tr>@foreach($r as $j => $c)<td class="{{ $j ? 'num' : '' }}">{!! $c !!}</td>@endforeach</tr>@endforeach
          @if($t['total'])<tr class="total">@foreach($t['total'] as $j => $c)<td class="{{ $j ? 'num' : '' }}">{!! $c !!}</td>@endforeach</tr>@endif
        </tbody>
      </table></div>
    @endif
  @endif

  @if(!empty($p['after']))
    <div class="rx-line">{!! !empty($p['afterHtml']) ? $p['after'] : e($p['after']) !!}</div>
  @endif
</div>
