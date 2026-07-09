@extends('layouts.tenant.app')

{{-- MARKER-PATCH-610 — Time clock. Clock in/out + today's shifts + who's on. --}}

@section('title', 'Time clock')

@push('styles')
<style>
.tc-wrap { max-width: 860px; }
.tc-hero { border: 0.5px solid var(--ia-border); border-radius: 14px; background: var(--ia-surface); padding: 30px; display: flex; align-items: center; justify-content: space-between; gap: 20px; margin-bottom: 20px; }
.tc-hero.on { border-color: var(--ia-accent); background: linear-gradient(135deg, color-mix(in srgb, var(--ia-accent) 6%, transparent), transparent 55%), var(--ia-surface); }
.tc-state { font-size: 12px; letter-spacing: .07em; text-transform: uppercase; color: var(--ia-text-muted); margin-bottom: 6px; font-weight: 600; }
.tc-hero.on .tc-state { color: var(--ia-accent); }
.tc-big { font-size: 30px; font-weight: 700; letter-spacing: -0.01em; font-variant-numeric: tabular-nums; }
.tc-sub { font-size: 12.5px; color: var(--ia-text-muted); margin-top: 5px; }
.tc-btn { padding: 14px 30px; border-radius: 10px; border: none; font-size: 15px; font-weight: 700; cursor: pointer; }
.tc-btn--in { background: var(--ia-accent); color: #0a0a0a; }
.tc-btn--out { background: transparent; border: 1.5px solid var(--ia-border-2, rgba(255,255,255,.2)); color: var(--ia-text); }
.tc-btn:hover { filter: brightness(1.06); }
.tc-cols { display: grid; grid-template-columns: 1fr 1fr; gap: 16px; }
@media (max-width: 760px) { .tc-cols { grid-template-columns: 1fr; } .tc-hero { flex-direction: column; text-align: center; } }
.tc-card { border: 0.5px solid var(--ia-border); border-radius: 12px; background: var(--ia-surface); }
.tc-card-h { padding: 13px 16px; border-bottom: 0.5px solid var(--ia-border); font-weight: 700; font-size: 13px; display: flex; justify-content: space-between; align-items: center; }
.tc-card-h .m { font-size: 11px; color: var(--ia-text-muted); font-weight: 500; }
.tc-row { display: flex; justify-content: space-between; align-items: center; padding: 11px 16px; border-bottom: 0.5px dashed var(--ia-border); font-size: 13px; }
.tc-row:last-child { border-bottom: none; }
.tc-row .t { color: var(--ia-text-muted); font-size: 12px; font-variant-numeric: tabular-nums; }
.tc-row .dur { font-weight: 600; font-variant-numeric: tabular-nums; }
.tc-dot { display: inline-block; width: 7px; height: 7px; border-radius: 50%; background: var(--ia-accent); margin-right: 8px; vertical-align: 1px; }
.tc-empty { padding: 26px 16px; text-align: center; color: var(--ia-text-muted); font-size: 12.5px; }
</style>
@endpush

@section('content')
<div class="tc-wrap">

  <h1 style="font-size:19px;font-weight:700;margin-bottom:4px">Time clock</h1>
  <p style="font-size:12.5px;color:var(--ia-text-muted);margin-bottom:20px">Clock in when your shift starts — the lock screen offers it too.</p>

  {{-- status hero --}}
  <div class="tc-hero {{ $open ? 'on' : '' }}">
    <div>
      @if($open)
        <div class="tc-state">On the clock</div>
        <div class="tc-big" id="tc-timer" data-in="{{ $open->clock_in_at->timestamp }}">--:--</div>
        <div class="tc-sub">since {{ tlocal($open->clock_in_at) }}</div>
      @else
        <div class="tc-state">Off the clock</div>
        <div class="tc-big">{{ intdiv($todayMinutes, 60) }}h {{ $todayMinutes % 60 }}m</div>
        <div class="tc-sub">worked today</div>
      @endif
    </div>
    <div>
      @if($open)
        <form method="POST" action="{{ route('tenant.timeclock.out') }}">@csrf
          <button class="tc-btn tc-btn--out" type="submit">Clock out</button>
        </form>
      @else
        <form method="POST" action="{{ route('tenant.timeclock.in') }}">@csrf
          <button class="tc-btn tc-btn--in" type="submit">Clock in</button>
        </form>
      @endif
    </div>
  </div>

  <div class="tc-cols">
    {{-- today's shifts --}}
    <div class="tc-card">
      <div class="tc-card-h">Your shifts today <span class="m">{{ tlocal_date(tnow()) }}</span></div>
      @forelse($mine as $p)
        <div class="tc-row">
          <span class="t">{{ tlocal($p->clock_in_at) }} → {{ $p->clock_out_at ? tlocal($p->clock_out_at) : 'now' }}</span>
          <span class="dur">{{ intdiv($p->minutes(), 60) }}h {{ $p->minutes() % 60 }}m</span>
        </div>
      @empty
        <div class="tc-empty">No punches yet today.</div>
      @endforelse
    </div>

    {{-- who's on --}}
    <div class="tc-card">
      <div class="tc-card-h">On the clock now <span class="m">{{ $onClock->count() }}</span></div>
      @forelse($onClock as $p)
        <div class="tc-row">
          <span><span class="tc-dot"></span>{{ $p->user->name ?? 'Staff' }}</span>
          <span class="t">since {{ tlocal($p->clock_in_at) }}</span>
        </div>
      @empty
        <div class="tc-empty">Nobody clocked in.</div>
      @endforelse
    </div>
  </div>

</div>
@endsection

@push('scripts')
<script>
// Live elapsed timer while on the clock.
(function () {
  var el = document.getElementById('tc-timer');
  if (!el) return;
  var start = parseInt(el.dataset.in, 10) * 1000;
  function tick() {
    var mins = Math.max(0, Math.floor((Date.now() - start) / 60000));
    el.textContent = Math.floor(mins / 60) + 'h ' + (mins % 60) + 'm';
  }
  tick(); setInterval(tick, 30000);
})();
</script>
@endpush

