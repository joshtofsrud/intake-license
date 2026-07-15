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
.tc-stats { display: grid; grid-template-columns: repeat(3, 1fr); gap: 12px; margin-bottom: 20px; }
.tc-stat { border: 0.5px solid var(--ia-border); border-radius: 10px; background: var(--ia-surface); padding: 14px 16px; }
.tc-stat .l { font-size: 10px; letter-spacing: .07em; text-transform: uppercase; color: var(--ia-text-muted); margin-bottom: 6px; }
.tc-stat .v { font-size: 19px; font-weight: 700; font-variant-numeric: tabular-nums; }
.tc-mini { padding: 5px 11px; font-size: 11.5px; border: 0.5px solid var(--ia-border-2, rgba(255,255,255,.2)); background: transparent; color: var(--ia-text); border-radius: 6px; cursor: pointer; text-decoration: none; }
.tc-mini:hover { border-color: var(--ia-accent); }
.tc-inp { padding: 6px 10px; border-radius: 6px; border: 0.5px solid var(--ia-border); background: var(--ia-input-bg, #0a0a0a); color: var(--ia-text); font-size: 12.5px; }
@media (max-width: 760px) { .tc-stats { grid-template-columns: 1fr 1fr; } }
</style>
@endpush

@section('content')
<div class="tc-wrap">

  <h1 style="font-size:19px;font-weight:700;margin-bottom:4px">Time clock</h1>

  {{-- MARKER-PATCH-614 — subnav; Team only for managers --}}
  @if(auth('tenant')->user()?->can('timeclock.manage'))
    <div style="display:flex;gap:20px;border-bottom:.5px solid var(--ia-border);margin-bottom:20px">
      <a href="{{ route('tenant.timeclock.index') }}" style="padding:11px 2px;font-size:13px;color:var(--ia-text);border-bottom:2px solid var(--ia-accent);margin-bottom:-.5px;text-decoration:none;font-weight:600">My time</a>
      <a href="{{ route('tenant.timeclock.team') }}" style="padding:11px 2px;font-size:13px;color:var(--ia-text-muted);border-bottom:2px solid transparent;margin-bottom:-.5px;text-decoration:none">Team</a>
      <a href="{{ route('tenant.timeclock.reports') }}" style="padding:11px 2px;font-size:13px;color:var(--ia-text-muted);border-bottom:2px solid transparent;margin-bottom:-.5px;text-decoration:none">Reports</a>
      <a href="{{ route('tenant.timeclock.approvals') }}" style="padding:11px 2px;font-size:13px;color:var(--ia-text-muted);border-bottom:2px solid transparent;margin-bottom:-.5px;text-decoration:none">Approvals</a>
    </div>
  @else
    <p style="font-size:12.5px;color:var(--ia-text-muted);margin-bottom:20px">Clock in when your shift starts — the lock screen offers it too.</p>
  @endif

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
        <form method="POST" action="{{ route('tenant.timeclock.out') }}" data-os-punch="out">@csrf
          <button class="tc-btn tc-btn--out" type="submit">Clock out</button>
        </form>
      @else
        <form method="POST" action="{{ route('tenant.timeclock.in') }}" data-os-punch="in">@csrf
          <button class="tc-btn tc-btn--in" type="submit">Clock in</button>
        </form>
      @endif
      <div id="osPunchNote" style="display:none;margin-top:10px;font-size:12.5px;color:#F5C56B"></div>
    </div>
  </div>

  {{-- MARKER-PATCH-613 — rolling totals (pay-period-aware totals arrive with settings) --}}
  <div class="tc-stats">
    <div class="tc-stat"><div class="l">This week</div><div class="v">{{ intdiv($weekMinutes, 60) }}h {{ $weekMinutes % 60 }}m</div></div>
    <div class="tc-stat"><div class="l">This month</div><div class="v">{{ intdiv($monthMinutes, 60) }}h {{ $monthMinutes % 60 }}m</div></div>
    <div class="tc-stat"><div class="l">Today</div><div class="v">{{ intdiv($todayMinutes, 60) }}h {{ $todayMinutes % 60 }}m</div></div>
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

  {{-- MARKER-PATCH-613 — shift history + email/print timesheet --}}
  <div class="tc-card" style="margin-top:16px">
    <div class="tc-card-h">Shift history
      <span style="display:flex;gap:8px">
        <a class="tc-mini" href="{{ route('tenant.timeclock.timesheet') }}" target="_blank" rel="noopener">Print timesheet</a>
        <button class="tc-mini" type="button" onclick="document.getElementById('tc-email').style.display='flex'">Email timesheet</button>
      </span>
    </div>
    <form id="tc-email" method="POST" action="{{ route('tenant.timeclock.timesheet.email') }}" style="display:none;gap:8px;padding:12px 16px;border-bottom:.5px solid var(--ia-border);align-items:center;flex-wrap:wrap">
      @csrf
      <input type="email" name="to" required placeholder="send to…" class="tc-inp" value="{{ $authUser->email ?? '' }}">
      <span style="font-size:11px;color:var(--ia-text-muted)">This month · {{ tlocal_date(tnow()->startOfMonth()) }}–{{ tlocal_date(tnow()->endOfMonth()) }}</span>
      <button class="tc-mini" type="submit">Send</button>
    </form>
    @forelse($history as $p)
      @php $mins = $p->minutes(); @endphp
      <div class="tc-row" style="grid-template-columns:120px 1fr 90px">
        <span class="t">{{ tlocal_date($p->clock_in_at) }}</span>
        <span class="num">{{ tlocal($p->clock_in_at) }} → {{ $p->clock_out_at ? tlocal($p->clock_out_at) : 'now' }}
          @if($p->auto_closed)<span style="color:var(--ia-amber,#F59E0B);font-size:10px;text-transform:uppercase;margin-left:6px">auto-closed</span>@endif
          @if($p->edited_at)<span style="color:var(--ia-text-muted);font-size:10px;margin-left:6px">edited</span>@endif
        </span>
        <span class="dur">{{ intdiv($mins,60) }}h {{ $mins % 60 }}m</span>
      </div>
    @empty
      <div class="tc-empty">No punches recorded yet.</div>
    @endforelse
  </div>
</div>
@endsection

@push('scripts')
{{-- MARKER-OFFLINE-SYNC stage 2 — punches queue on-device when offline and
     replay with their ORIGINAL timestamps. Gated by the offline_sync add-on. --}}
@if ($offlineSyncEnabled ?? false)
<script>
(function () {
  const SYNC_URL = @json(route('tenant.timeclock.sync'));
  const CSRF = document.querySelector('meta[name=csrf-token]').content;
  const uuid = () => (crypto.randomUUID) ? crypto.randomUUID() :
    'xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx'.replace(/[xy]/g, c => {
      const r = Math.random()*16|0; return (c==='x'?r:(r&0x3|0x8)).toString(16);
    });

  function db() {
    return new Promise((res, rej) => {
      const rq = indexedDB.open('intake-offline-punches', 1);
      rq.onupgradeneeded = () => rq.result.createObjectStore('punches', { keyPath: 'client_uuid' });
      rq.onsuccess = () => res(rq.result);
      rq.onerror = () => rej(rq.error);
    });
  }
  async function queuePunch(direction) {
    const d = await db();
    const rec = { client_uuid: uuid(), direction, punched_at: new Date().toISOString() };
    await new Promise(res => {
      const tx = d.transaction('punches', 'readwrite');
      tx.objectStore('punches').put(rec);
      tx.oncomplete = res; tx.onerror = res;
    });
    const note = document.getElementById('osPunchNote');
    note.style.display = 'block';
    note.textContent = 'You\'re offline — punch saved at ' +
      new Date().toLocaleTimeString([], { hour: 'numeric', minute: '2-digit' }) +
      ' and will sync with its original time.';
  }
  async function replay() {
    if (!navigator.onLine) return;
    const d = await db();
    const all = await new Promise(res => {
      const rq = d.transaction('punches').objectStore('punches').getAll();
      rq.onsuccess = () => res(rq.result || []); rq.onerror = () => res([]);
    });
    if (!all.length) return;
    let synced = 0;
    for (const rec of all.sort((a, b) => a.punched_at.localeCompare(b.punched_at))) {
      try {
        const r = await fetch(SYNC_URL, {
          method: 'POST',
          headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': CSRF },
          body: JSON.stringify(rec),
        });
        const j = await r.json();
        if (j.ok || r.status === 422) {
          await new Promise(res => {
            const tx = d.transaction('punches', 'readwrite');
            tx.objectStore('punches').delete(rec.client_uuid);
            tx.oncomplete = res; tx.onerror = res;
          });
          if (j.ok) synced++;
        }
      } catch (e) { return; } // still offline
    }
    if (synced) location.reload(); // reflect the punch state
  }
  document.querySelectorAll('form[data-os-punch]').forEach(f => {
    f.addEventListener('submit', (e) => {
      if (navigator.onLine) return; // normal form post
      e.preventDefault();
      queuePunch(f.dataset.osPunch);
    });
  });
  window.addEventListener('online', replay);
  replay();
})();
</script>
@endif
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

