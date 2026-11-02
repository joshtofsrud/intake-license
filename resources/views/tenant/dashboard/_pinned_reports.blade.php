{{-- MARKER-REPORT-DELIVERY: reports this person pinned from Reports > Custom. Their own dashboard only. --}}
@php
  $rbPins = \App\Services\Tenant\ReportBuilder\PinnedReports::for(tenant(), \Illuminate\Support\Facades\Auth::guard('tenant')->user());
  $rbFmt = fn ($v, $t) => \App\Services\Tenant\ReportBuilder\ReportEngine::format($v, $t);
@endphp
@if(count($rbPins))
<style>
  .rbp-wrap{margin-top:28px}
  .rbp-h{display:flex;align-items:baseline;gap:10px;margin-bottom:10px}
  .rbp-h h2{font-size:12px;text-transform:uppercase;letter-spacing:.08em;font-weight:600;color:var(--ia-text-dim,#888);margin:0}
  .rbp-h span{font-size:11.5px;color:var(--ia-text-dim,#888)}
  .rbp-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(340px,1fr));gap:22px 28px}
  @media (max-width:560px){.rbp-grid{grid-template-columns:1fr}}
  .rbp{border-top:1px solid var(--ia-border,#222);padding-top:12px;min-width:0}
  .rbp-top{display:flex;gap:8px;align-items:baseline;margin-bottom:8px}
  .rbp-top a.nm{font-weight:600;font-size:14px;color:var(--ia-text,#eee);text-decoration:none}
  .rbp-top a.nm:hover{text-decoration:underline}
  .rbp-top .rg{font-size:11.5px;color:var(--ia-text-dim,#888);margin-left:auto;white-space:nowrap}
  .rbp table{width:100%;border-collapse:collapse;font-size:12.5px;font-variant-numeric:tabular-nums}
  .rbp th{text-align:left;font-size:10.5px;text-transform:uppercase;letter-spacing:.06em;color:var(--ia-text-dim,#888);font-weight:600;padding:5px 4px;border-bottom:1px solid var(--ia-border,#222)}
  .rbp td{padding:6px 4px;border-bottom:1px solid var(--ia-border,#1c1c1c)}
  .rbp .r{text-align:right;white-space:nowrap}
  .rbp tr.t td{font-weight:600;border-bottom:0}
  .rbp .num{font-size:30px;font-weight:600;letter-spacing:-.02em;line-height:1.1}
  .rbp .lb{font-size:12px;color:var(--ia-text-dim,#888);margin-top:4px}
  .rbp .bars div{display:grid;grid-template-columns:minmax(0,38%) 1fr auto;gap:8px;align-items:center;font-size:12px;padding:3px 0}
  .rbp .bars span:first-child{overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
  .rbp .bars i{display:block;height:7px;border-radius:4px;background:var(--ia-accent,#BEF264);opacity:.85}
  .rbp .more{font-size:11.5px;color:var(--ia-text-dim,#888);margin-top:6px}
  .rbp .more a{color:inherit}
</style>
<div class="rbp-wrap">
  <div class="rbp-h"><h2>Pinned reports</h2><span>yours only · refreshed every 15 minutes · pin or unpin under Reports › Custom › Share</span></div>
  <div class="rbp-grid">
    @foreach($rbPins as $pin)
      @php $link = route('tenant.reports.custom', ['open' => $pin['id']]); $tb = $pin['table']; @endphp
      <div class="rbp">
        <div class="rbp-top"><a class="nm" href="{{ $link }}">{{ $pin['name'] }}</a><span class="rg">{{ $pin['range'] ?? '' }}</span></div>
        @if($pin['error'])
          <div class="lb">{{ $pin['error'] }} <a href="{{ $link }}">Open it</a></div>
        @elseif(!$tb || $tb['count'] === 0)
          <div class="lb">Nothing matches this report right now. <a href="{{ $link }}">Open it</a></div>
        @elseif($pin['as'] === 'number')
          <div class="num">{{ $rbFmt($tb['total'][1] ?? ($tb['rows'][0][1] ?? null), $tb['types'][1]) }}</div>
          <div class="lb">{{ $tb['head'][1] }}{{ $tb['total'] ? ', total' : '' }} · {{ $tb['count'] }} {{ \Illuminate\Support\Str::plural(mb_strtolower($tb['head'][0]), $tb['count']) }}</div>
        @elseif($pin['as'] === 'chart')
          @php $vals = array_map(fn ($r) => max(0, (float) ($r[1] ?? 0)), array_slice($tb['rows'], 0, 8)); $mx = max($vals ?: [0]) ?: 1; @endphp
          <div class="lb" style="margin:0 0 6px">{{ $tb['head'][1] }}</div>
          <div class="bars">
            @foreach(array_slice($tb['rows'], 0, 8) as $i => $r)
              <div><span>{{ $r[0] }}</span><span><i style="width:{{ max(2, round($vals[$i] / $mx * 100)) }}%"></i></span><span class="r">{{ $rbFmt($r[1], $tb['types'][1]) }}</span></div>
            @endforeach
          </div>
        @else
          @php $cols = min(count($tb['head']), 4); @endphp
          <table>
            <tr>@for($c = 0; $c < $cols; $c++)<th class="{{ $c ? 'r' : '' }}">{{ $tb['head'][$c] }}</th>@endfor</tr>
            @foreach(array_slice($tb['rows'], 0, 6) as $r)
              <tr>@for($c = 0; $c < $cols; $c++)<td class="{{ $c ? 'r' : '' }}">{{ $c ? $rbFmt($r[$c], $tb['types'][$c]) : $r[0] }}</td>@endfor</tr>
            @endforeach
            @if($tb['total'])
              <tr class="t">@for($c = 0; $c < $cols; $c++)<td class="{{ $c ? 'r' : '' }}">{{ $c ? $rbFmt($tb['total'][$c], $tb['types'][$c]) : 'Total' }}</td>@endfor</tr>
            @endif
          </table>
        @endif
        @if($tb && ($tb['count'] > 6 || count($tb['head']) > 4) && $pin['as'] !== 'number')
          <div class="more">{{ $tb['count'] > 6 ? ($tb['count'] - 6) . ' more row' . ($tb['count'] - 6 === 1 ? '' : 's') : '' }}{{ $tb['count'] > 6 && count($tb['head']) > 4 ? ' and ' : '' }}{{ count($tb['head']) > 4 ? (count($tb['head']) - 4) . ' more column' . (count($tb['head']) - 4 === 1 ? '' : 's') : '' }} — <a href="{{ $link }}">open the full report</a></div>
        @endif
      </div>
    @endforeach
  </div>
</div>
@endif
