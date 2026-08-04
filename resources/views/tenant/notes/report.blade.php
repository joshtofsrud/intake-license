@extends('layouts.tenant.app')
@php $pageTitle = 'Notes'; @endphp

@section('content')

{{-- MARKER-OLD-SCHOOL-REPORT --}}
<div class="ia-page-head">
  <div class="ia-page-head-left">
    <h1 class="ia-page-title">The pad — how it's going</h1>
    <p class="ia-page-subtitle">
      Whether the pad is being cleared, not how much gets written. A pad that fills faster than it
      empties stops being read, and then the note that mattered gets missed with the rest.
    </p>
  </div>
</div>

<div class="np-tabs">
  <a href="{{ route('tenant.notes.index') }}" class="np-tab">Open {{ $openCount }}</a>
  <a href="{{ route('tenant.notes.index', ['tab' => 'done']) }}" class="np-tab">Crossed off {{ $doneCount }}</a>
  <a href="{{ route('tenant.notes.index', ['tab' => 'report']) }}" class="np-tab on">How it's going</a>
</div>

<div class="rp-strip">
  <div class="rp-stat">
    <div class="n">{{ $openCount }}</div><div class="l">Open right now</div>
  </div>
  <div class="rp-stat {{ $oldest && $oldest->ageInDays() >= 7 ? 'warn' : '' }}">
    <div class="n">{{ $oldest ? $oldest->ageInDays() . 'd' : '—' }}</div>
    <div class="l">Oldest still open</div>
  </div>
  <div class="rp-stat {{ $clearedWeek >= $writtenWeek ? 'good' : '' }}">
    <div class="n">{{ $clearedWeek }}</div><div class="l">Crossed off this week</div>
  </div>
  <div class="rp-stat">
    <div class="n">{{ $writtenWeek }}</div><div class="l">Written this week</div>
  </div>
</div>

<div class="rp-sec">
  <div class="rp-h">How long things have been sitting
    <span>weight on the right is the warning</span></div>
  <div class="rp-b">
    @php $bmax = max(1, max($buckets)); @endphp
    @foreach([
      '0-2'  => ['Today &amp; yesterday', '#4ade80'],
      '3-7'  => ['3–7 days',              '#a3b18a'],
      '8-30' => ['8–30 days',             '#d9a441'],
      '30+'  => ['Over 30 days',          '#f87171'],
    ] as $k => $meta)
      <div class="rp-arow">
        <span class="lbl">{!! $meta[0] !!}</span>
        <span class="track">
          <span class="fill" style="width:{{ round($buckets[$k] / $bmax * 100) }}%;background:{{ $meta[1] }}"></span>
        </span>
        <span class="ct">{{ $buckets[$k] }}</span>
      </div>
    @endforeach
  </div>
</div>

<div class="rp-sec">
  <div class="rp-h">Written against crossed off
    <span>eight weeks · the bars drifting apart is what to catch early</span></div>
  <div class="rp-b">
    <div class="rp-trend">
      @foreach($weeks as $w)
        <div class="wk">
          <div class="bars">
            <i class="w" style="height:{{ max(2, round($w['written'] / $peak * 100)) }}%"
               title="{{ $w['written'] }} written"></i>
            <i class="c" style="height:{{ max(2, round($w['cleared'] / $peak * 100)) }}%"
               title="{{ $w['cleared'] }} crossed off"></i>
          </div>
          <div class="lb">{{ $w['label'] }}</div>
        </div>
      @endforeach
    </div>
    <div class="rp-legend">
      <span><i style="background:#3f3f49"></i>written</span>
      <span><i style="background:#4ade80"></i>crossed off</span>
    </div>
  </div>
</div>

@if(count($people))
  <div class="rp-sec">
    <div class="rp-h">People
      <span>who writes, who clears — and whose notes are still sitting</span></div>
    <div class="rp-b" style="padding:0 15px">
      <table class="rp-ppl">
        <thead>
          <tr>
            <th>Staff</th>
            <th class="num">Written</th>
            <th class="num">Crossed off</th>
            <th class="num">Theirs still open</th>
            <th class="num">Oldest of those</th>
          </tr>
        </thead>
        <tbody>
          @foreach($people as $p)
            <tr>
              <td>{{ $p['name'] }}</td>
              <td class="num">{{ $p['written'] }}</td>
              <td class="num">{{ $p['cleared'] }}</td>
              <td class="num">{{ $p['still_open'] }}</td>
              <td class="num {{ $p['oldest'] >= 14 ? 'lag' : '' }}">
                {{ $p['oldest'] ? $p['oldest'] . 'd' : '—' }}
              </td>
            </tr>
          @endforeach
        </tbody>
      </table>
      <p class="rp-caveat">
        Written is shown and never sorted on. Someone writing a lot is not a finding; someone writing a
        few and clearing none, with something three weeks old, is worth a conversation.
      </p>
    </div>
  </div>
@endif

<div class="rp-sec">
  <div class="rp-h">Nobody has touched these
    <span>over a week old · the actual point of the page</span></div>
  <div class="rp-b">
    @forelse($stuck as $n)
      <div class="rp-note">
        <form method="POST" action="{{ route('tenant.notes.toggle', $n->id) }}">
          @csrf
          <input type="hidden" name="back" value="{{ request()->getRequestUri() }}">
          <button type="submit" class="rp-box" aria-label="Cross off"></button>
        </form>
        <div class="rp-nb">
          <div class="rp-nt">{{ $n->body }}</div>
          <div class="rp-nm">
            @if($n->customer)
              <span class="rp-who">{{ $n->customer->first_name }} {{ $n->customer->last_name }}</span>
            @endif
            <span>{{ $n->author?->name ?? 'someone' }}</span>
            <span class="rp-old">{{ $n->ageInDays() }} days</span>
          </div>
        </div>
      </div>
    @empty
      <div class="rp-clear">Nothing has been sitting more than a week. The pad is being cleared.</div>
    @endforelse
  </div>
</div>

<style>
  .np-tabs { display:flex; gap:6px; margin-bottom:16px; }
  .np-tab { padding:6px 13px; border-radius:999px; font-size:12.5px; text-decoration:none;
            border:.5px solid var(--ia-border); color:var(--ia-text-dim); }
  .np-tab.on { background:#B8860B; border-color:#B8860B; color:#fff; font-weight:650; }

  .rp-strip { display:grid; grid-template-columns:repeat(4,1fr); gap:11px; margin-bottom:16px; }
  @media (max-width:800px){ .rp-strip { grid-template-columns:repeat(2,1fr); } }
  .rp-stat { border:.5px solid var(--ia-border); border-radius:10px; padding:13px 15px; }
  .rp-stat .n { font-size:25px; font-weight:700; letter-spacing:-.02em; font-variant-numeric:tabular-nums; }
  .rp-stat .l { font-size:11.5px; color:var(--ia-text-dim); margin-top:3px; line-height:1.45; }
  .rp-stat.warn { border-color:rgba(217,164,65,.45); background:rgba(217,164,65,.08); }
  .rp-stat.warn .n { color:#d9a441; }
  .rp-stat.good .n { color:#4ade80; }

  .rp-sec { border:.5px solid var(--ia-border); border-radius:11px; margin-bottom:16px; overflow:hidden; }
  .rp-h { padding:11px 15px; border-bottom:.5px solid var(--ia-border); font-size:12.5px; font-weight:600;
          display:flex; align-items:center; gap:9px; flex-wrap:wrap; }
  .rp-h span { margin-left:auto; font-size:11px; color:var(--ia-text-dim); font-weight:400; }
  .rp-b { padding:15px; }

  .rp-arow { display:flex; align-items:center; gap:12px; font-size:12.5px; margin-bottom:9px; }
  .rp-arow:last-child { margin-bottom:0; }
  .rp-arow .lbl { width:130px; color:var(--ia-text-dim); flex:none; }
  .rp-arow .track { flex:1; height:20px; border-radius:5px; background:rgba(127,127,127,.12); overflow:hidden; }
  .rp-arow .fill { display:block; height:100%; border-radius:5px; }
  .rp-arow .ct { width:34px; text-align:right; font-variant-numeric:tabular-nums; flex:none; }

  .rp-trend { display:flex; align-items:flex-end; gap:10px; height:150px; }
  .rp-trend .wk { flex:1; display:flex; flex-direction:column; align-items:center; gap:5px; height:100%; }
  .rp-trend .bars { flex:1; display:flex; align-items:flex-end; gap:3px; justify-content:center; width:100%; }
  .rp-trend .bars i { width:13px; border-radius:3px 3px 0 0; display:block; }
  .rp-trend .bars .w { background:#3f3f49; }
  .rp-trend .bars .c { background:#4ade80; }
  .rp-trend .lb { font-size:10px; color:var(--ia-text-dim); }
  .rp-legend { display:flex; gap:14px; font-size:11.5px; color:var(--ia-text-dim); margin-top:10px; }
  .rp-legend i { display:inline-block; width:9px; height:9px; border-radius:2px; margin-right:5px; }

  .rp-ppl { width:100%; border-collapse:collapse; font-size:12.5px; }
  .rp-ppl th { text-align:left; padding:8px 10px; font-size:10px; letter-spacing:.06em;
               text-transform:uppercase; color:var(--ia-text-dim); border-bottom:.5px solid var(--ia-border); }
  .rp-ppl td { padding:10px; border-bottom:.5px solid var(--ia-border); }
  .rp-ppl tr:last-child td { border-bottom:0; }
  .rp-ppl .num { text-align:right; font-variant-numeric:tabular-nums; }
  .rp-ppl .lag { color:#d9a441; font-weight:600; }
  .rp-caveat { font-size:11.5px; color:var(--ia-text-dim); line-height:1.6; padding:12px 0 4px; }

  .rp-note { background:#F4ECD8; color:#2A2419; border-radius:9px; padding:11px 12px; margin-bottom:8px;
             display:flex; gap:11px; align-items:flex-start; }
  .rp-box { width:19px; height:19px; border:1.6px solid #8D8267; border-radius:4px; background:#FBF7EC;
            flex:none; margin-top:1px; cursor:pointer; padding:0; }
  .rp-box:hover { background:#8D8267; }
  .rp-nb { flex:1; min-width:0; }
  .rp-nt { font-size:13.5px; line-height:1.5; word-break:break-word; }
  .rp-nm { display:flex; gap:9px; flex-wrap:wrap; margin-top:5px; font-size:10.5px; color:#7A7159; }
  .rp-who { background:#DBE6D5; color:#33452C; border-radius:4px; padding:1px 7px; font-weight:600; }
  .rp-old { color:#A8622A; font-weight:600; }
  .rp-clear { padding:22px; text-align:center; font-size:13px; color:var(--ia-text-dim); }
</style>

@endsection
