{{-- MARKER-CLASSES-P11: how classes are doing, over 7, 30 or 90 days --}}
@php
  $h = $an['headline']; $n = $h['now']; $t = $h['then'];
  $pct  = fn ($v) => $v === null ? '–' : round($v * 100) . '%';
  $pts  = function ($a, $b) { if ($a === null || $b === null) return null; $d = round(($a - $b) * 100); return ($d > 0 ? '+' : '') . $d . ' pts'; };
  $rel  = function ($a, $b) { if (! $b) return null; $d = round(($a - $b) / $b * 100); return ($d > 0 ? '+' : '') . $d . '%'; };
  $tone = function ($s, $goodUp = true) { if ($s === null || str_starts_with($s, '0')) return ''; return (str_starts_with($s, '+') === $goodUp) ? 'up' : 'down'; };
  [$from, $to] = $an['range'];
  $days = ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'];
@endphp
<style>
.an-bar{display:flex;flex-wrap:wrap;align-items:center;gap:10px 16px;margin:4px 0 16px}
.an-seg{display:inline-flex;border:0.5px solid var(--ia-border);border-radius:var(--ia-r-md);overflow:hidden}
.an-seg a{padding:6px 12px;font-size:12.5px;color:var(--ia-text-muted);text-decoration:none}
.an-seg a.on{background:var(--ia-surface-2);color:var(--ia-text)}
.an-range{font-size:12.5px;color:var(--ia-text-muted)}
.an-grid{display:grid;grid-template-columns:repeat(4,1fr);border-top:0.5px solid var(--ia-border);border-left:0.5px solid var(--ia-border);margin-bottom:28px}
.an-m{padding:14px 16px;border-right:0.5px solid var(--ia-border);border-bottom:0.5px solid var(--ia-border)}
.an-m .l{font-size:12px;color:var(--ia-text-muted)}
.an-m .v{font-size:22px;font-weight:600;margin:2px 0}
.an-m .d{font-size:12px;color:var(--ia-text-muted)}
.an-m .d.up{color:#84CC16}.an-m .d.down{color:#F59E0B}
.an-h{display:flex;flex-wrap:wrap;align-items:baseline;gap:4px 10px;margin:0 0 10px;padding-bottom:8px;border-bottom:0.5px solid var(--ia-border)}
.an-h b{font-size:14px}.an-h span{font-size:12.5px;color:var(--ia-text-muted)}
.an-heat{display:grid;grid-template-columns:64px repeat(7,minmax(0,1fr));gap:3px;font-size:11.5px;margin-bottom:8px}
.an-heat .dh{text-align:center;color:var(--ia-text-muted);padding-bottom:2px}
.an-heat .th{color:var(--ia-text-muted);font-family:ui-monospace,monospace;font-size:11px;align-self:center}
.an-cell{height:34px;border-radius:5px;padding:4px 6px;font-weight:600;font-size:11.5px}
.an-cell.e{border:0.5px dashed var(--ia-border)}
.an-cell.g{background:rgba(34,197,94,.22)}.an-cell.a{background:rgba(245,158,11,.30)}.an-cell.r{background:rgba(239,68,68,.30)}
.an-cell small{display:block;font-weight:400;opacity:.7;font-size:10px}
.an-key{display:flex;gap:14px;font-size:11.5px;color:var(--ia-text-muted);margin-bottom:28px}
.an-key i{display:inline-block;width:9px;height:9px;border-radius:2px;margin-right:5px}
.an-two{display:grid;grid-template-columns:1fr 1fr;gap:28px;margin-bottom:28px}
.an-tbl{width:100%;border-collapse:collapse;font-size:13px}
.an-tbl th{font-size:10.5px;text-transform:uppercase;letter-spacing:.07em;color:var(--ia-text-muted);font-weight:600;text-align:right;padding:6px 8px;border-bottom:0.5px solid var(--ia-border)}
.an-tbl th:first-child,.an-tbl td:first-child{text-align:left;padding-left:0}
.an-tbl td{text-align:right;padding:9px 8px;border-bottom:0.5px solid var(--ia-border);font-variant-numeric:tabular-nums}
.an-np{display:grid;grid-template-columns:repeat(4,1fr);border-left:0.5px solid var(--ia-border);margin-bottom:12px}
.an-np div{padding:12px 16px;border-right:0.5px solid var(--ia-border)}
.an-np b{display:block;font-size:22px}
.an-np span{font-size:12.5px;color:var(--ia-text-muted)}
.an-np i{display:block;height:4px;border-radius:2px;background:var(--ia-accent);margin:8px 0 4px}
.an-legend{font-size:12px;color:var(--ia-text-muted);line-height:1.55;margin:0 0 34px;max-width:780px}
@media(max-width:900px){.an-grid,.an-np{grid-template-columns:repeat(2,1fr)}.an-two{grid-template-columns:1fr}}
@media(max-width:640px){.an-heat{grid-template-columns:48px repeat(7,minmax(0,1fr))}.an-cell{font-size:10px;padding:3px}}
</style>

<div class="an-bar">
  <div class="an-seg">
    @foreach(\App\Services\ClassAnalytics::WINDOWS as $w)
      <a href="{{ route('tenant.classes.reports', ['days' => $w]) }}" class="{{ $an['days'] === $w ? 'on' : '' }}">{{ $w }} days</a>
    @endforeach
  </div>
  <span class="an-range">{{ tlocal($from, 'M j') }} – {{ tlocal($to, 'M j') }} · compared with the {{ $an['days'] }} days before</span>
</div>

<div class="an-grid">
  @php $d1 = $pts($n['fill'], $t['fill']); $d2 = $rel($n['visits'], $t['visits']); $d3 = $pts($n['noshow'], $t['noshow']); $d4 = $pts($n['late'], $t['late']); $d5 = $rel($h['revenue'], $h['revenue_then']); @endphp
  <div class="an-m"><div class="l">Average fill</div><div class="v">{{ $pct($n['fill']) }}</div><div class="d {{ $tone($d1) }}">{{ $d1 ?? $n['classes'] . ' classes' }}</div></div>
  <div class="an-m"><div class="l">Visits</div><div class="v">{{ number_format($n['visits']) }}</div><div class="d {{ $tone($d2) }}">{{ $d2 ?? 'over ' . $n['classes'] . ' classes' }}</div></div>
  <div class="an-m"><div class="l">No-shows</div><div class="v">{{ $pct($n['noshow']) }}</div><div class="d {{ $tone($d3, false) }}">{{ $d3 ?? 'of bookings' }}</div></div>
  <div class="an-m"><div class="l">Late cancels</div><div class="v">{{ $pct($n['late']) }}</div><div class="d {{ $tone($d4, false) }}">{{ $d4 ?? 'of bookings' }}</div></div>
  <div class="an-m"><div class="l">Class revenue</div><div class="v">{{ format_money($h['revenue']) }}</div><div class="d {{ $tone($d5) }}">{{ $d5 ? $d5 . ' · ' : '' }}from sales</div></div>
  <div class="an-m"><div class="l">Membership MRR</div><div class="v">{{ format_money($h['mrr_cents']) }}</div><div class="d">{{ $h['members'] }} paying member{{ $h['members'] === 1 ? '' : 's' }}{{ $h['comps'] ? ' · ' . $h['comps'] . ' free' : '' }}</div></div>
  <div class="an-m"><div class="l">Intro to member</div><div class="v">{{ $h['intro_bought'] ? round($h['intro_converted'] / $h['intro_bought'] * 100) . '%' : '–' }}</div><div class="d">{{ $h['intro_converted'] }} of {{ $h['intro_bought'] }} intro offers</div></div>
  <div class="an-m"><div class="l">Classes run</div><div class="v">{{ number_format($n['classes']) }}</div><div class="d">{{ $t['classes'] }} the {{ $an['days'] }} days before</div></div>
</div>

<div class="an-h"><b>How full each slot runs</b><span>Average fill by day and start time. Where to add or move a class.</span></div>
@if(empty($an['slots']['times']))
  <p style="font-size:13px;color:var(--ia-text-muted);margin-bottom:28px">No classes in this window yet.</p>
@else
  <div class="an-heat">
    <div></div>@foreach($days as $d)<div class="dh">{{ $d }}</div>@endforeach
    @foreach($an['slots']['times'] as $time)
      <div class="th">{{ \Carbon\Carbon::createFromFormat('H:i', $time)->format('g:ia') }}</div>
      @for($dow = 1; $dow <= 7; $dow++)
        @php $c = $an['slots']['cells'][$time][$dow] ?? null; @endphp
        @if(! $c)
          <div class="an-cell e"></div>
        @else
          <div class="an-cell {{ $c['full'] ? 'r' : ($c['fill'] >= 0.75 ? 'a' : 'g') }}" title="{{ $c['n'] }} class{{ $c['n'] === 1 ? '' : 'es' }}">{{ round($c['fill'] * 100) }}%@if($c['full'])<small>waitlist</small>@endif</div>
        @endif
      @endfor
    @endforeach
  </div>
  <div class="an-key"><span><i style="background:rgba(34,197,94,.5)"></i>Under 75%</span><span><i style="background:rgba(245,158,11,.6)"></i>75–100%</span><span><i style="background:rgba(239,68,68,.6)"></i>Full, with a waitlist</span></div>
@endif

<div class="an-two">
  <div>
    <div class="an-h"><b>By class type</b><span>Revenue = drop-ins plus pack credits used</span></div>
    <table class="an-tbl">
      <tr><th>Class</th><th>Classes</th><th>Fill</th><th>No-show</th><th>Revenue</th></tr>
      @forelse($an['types'] as $r)
        <tr><td>{{ $r['name'] }}</td><td>{{ $r['classes'] }}</td><td>{{ $pct($r['fill']) }}</td><td>{{ $pct($r['noshow']) }}</td><td>{{ format_money($r['revenue']) }}</td></tr>
      @empty
        <tr><td colspan="5" style="color:var(--ia-text-muted)">No classes in this window.</td></tr>
      @endforelse
    </table>
  </div>
  <div>
    <div class="an-h"><b>By instructor</b><span>Visits, fill, and regulars (3+ visits with them)</span></div>
    <table class="an-tbl">
      <tr><th>Instructor</th><th>Classes</th><th>Visits</th><th>Fill</th><th>Regulars</th></tr>
      @forelse($an['instructors'] as $r)
        <tr><td>{{ $r['name'] }}</td><td>{{ $r['classes'] }}</td><td>{{ $r['visits'] }}</td><td>{{ $pct($r['fill']) }}</td><td>{{ $r['regulars'] }}</td></tr>
      @empty
        <tr><td colspan="5" style="color:var(--ia-text-muted)">No classes in this window.</td></tr>
      @endforelse
    </table>
  </div>
</div>

@php $np = $an['new']; $of = fn ($x) => $np['first'] ? round($x / $np['first'] * 100) : 0; @endphp
<div class="an-h"><b>New people</b><span>First visits in this window, and what they did next</span></div>
<div class="an-np">
  <div><b>{{ $np['first'] }}</b><span>First visit</span><i style="width:{{ $np['first'] ? 100 : 0 }}%"></i><span>{{ $np['first'] ? '100% of first visits' : '' }}</span></div>
  <div><b>{{ $np['back'] }}</b><span>Came back within 14 days</span><i style="width:{{ $of($np['back']) }}%"></i><span>{{ $of($np['back']) }}% of first visits</span></div>
  <div><b>{{ $np['bought'] }}</b><span>Bought a pass or membership</span><i style="width:{{ $of($np['bought']) }}%"></i><span>{{ $of($np['bought']) }}% of first visits</span></div>
  <div><b>{{ $np['three'] }}</b><span>3 or more visits so far</span><i style="width:{{ $of($np['three']) }}%"></i><span>{{ $of($np['three']) }}% of first visits</span></div>
</div>
<div class="an-legend">A class counts once it has started, unless it was cancelled. Booked means registered, checked in or no-show. A visit means checked in, or still booked after the class for studios that don't check people in. Class revenue is money taken on sales for passes, memberships and drop-ins, less refunds. Revenue by class type values drop-ins at the class price and pack visits at the pack's price per class. Membership visits aren't priced per class.</div>
