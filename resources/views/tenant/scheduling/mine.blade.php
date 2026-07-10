@extends('layouts.tenant.app')

{{-- MARKER-PATCH-623 — Scheduling: my schedule (all staff). --}}

@section('title', 'My schedule')

@push('styles')
<style>
.ms-sub { display:flex; gap:20px; border-bottom:.5px solid var(--ia-border); margin-bottom:18px; }
.ms-sub a { padding:11px 2px; font-size:13px; color:var(--ia-text-muted); border-bottom:2px solid transparent; margin-bottom:-.5px; text-decoration:none; }
.ms-sub a.on { color:var(--ia-text); border-bottom-color:var(--ia-accent); font-weight:600; }
.ms-cols { display:grid; grid-template-columns:1.2fr 1fr; gap:16px; }
@media (max-width:760px){ .ms-cols { grid-template-columns:1fr; } }
.ms-card { border:.5px solid var(--ia-border); border-radius:12px; background:var(--ia-surface); }
.ms-h { padding:12px 15px; border-bottom:.5px solid var(--ia-border); font-weight:700; font-size:13px; display:flex; justify-content:space-between; align-items:center; }
.ms-h .m { font-size:11px; color:var(--ia-text-muted); font-weight:500; }
.ms-row { display:flex; justify-content:space-between; align-items:center; padding:11px 15px; border-bottom:.5px dashed var(--ia-border); font-size:12.5px; }
.ms-row:last-child { border:none; }
.ms-pill { font-size:10px; text-transform:uppercase; letter-spacing:.04em; border-radius:999px; padding:2px 9px; font-weight:700; }
.ms-pill.g { background:rgba(190,242,100,.12); color:var(--ia-accent); }
.ms-pill.a { background:rgba(245,158,11,.12); color:#F59E0B; }
.ms-pill.r { background:rgba(248,113,113,.12); color:#f87171; }
.ms-btn { padding:7px 13px; border-radius:7px; font-size:12px; font-weight:600; cursor:pointer; border:.5px solid var(--ia-border-2,rgba(255,255,255,.2)); background:transparent; color:var(--ia-text); }
.ms-btn.p { background:var(--ia-accent); color:var(--ia-accent-text); border:none; }
.ms-f label { display:block; font-size:10px; text-transform:uppercase; letter-spacing:.05em; color:var(--ia-text-muted); margin:0 0 5px; font-weight:600; }
.ms-f input, .ms-f select { width:100%; padding:9px 11px; margin-bottom:12px; background:var(--ia-surface-2,#1a1a1a); border:1px solid var(--ia-border); border-radius:7px; color:var(--ia-text); font-size:13px; }
.ms-empty { padding:22px 15px; text-align:center; color:var(--ia-text-muted); font-size:12px; }
</style>
@endpush

@section('content')
<div style="max-width:860px">
  <h1 style="font-size:19px;font-weight:700;margin-bottom:4px">Scheduling</h1>
  <div class="ms-sub">
    @if(auth('tenant')->user()?->can('scheduling.build'))
      <a href="{{ route('tenant.scheduling.index') }}">Schedule builder</a>
    @endif
    @if(auth('tenant')->user()?->can('scheduling.timeoff'))
      <a href="{{ route('tenant.scheduling.timeoff') }}">Time off</a>
    @endif
    <a href="{{ route('tenant.scheduling.mine') }}" class="on">My schedule</a>
  </div>

  <div style="display:flex;align-items:center;gap:10px;margin-bottom:14px">
    <a class="ms-btn" href="{{ route('tenant.scheduling.mine', ['week' => $weekStart->copy()->subWeek()->toDateString()]) }}">◀</a>
    <span style="font-weight:600;font-size:13px">Week of {{ $weekStart->format('M j') }} – {{ $weekStart->copy()->endOfWeek()->format('M j, Y') }}</span>
    <a class="ms-btn" href="{{ route('tenant.scheduling.mine', ['week' => $weekStart->copy()->addWeek()->toDateString()]) }}">▶</a>
  </div>

  <div class="ms-cols">
    <div class="ms-card">
      <div class="ms-h">This week <span class="m">{{ intdiv($weekMinutes, 60) }}h {{ $weekMinutes % 60 }}m scheduled</span></div>
      @forelse($shifts as $sh)
        <div class="ms-row">
          <span style="color:var(--ia-text-muted);width:92px">{{ tlocal_date($sh->starts_at, 'D M j') }}</span>
          <b>{{ tlocal($sh->starts_at) }} – {{ tlocal($sh->ends_at) }}</b>
          <span style="flex:1"></span>
          @if($sh->label)<span class="ms-pill g">{{ $sh->label }}</span>@endif
        </div>
      @empty
        <div class="ms-empty">No published shifts this week.</div>
      @endforelse
    </div>

    <div class="ms-card">
      <div class="ms-h">Time off <button class="ms-btn p" type="button" onclick="document.getElementById('ms-req').style.display='block'">Request time off</button></div>

      <form id="ms-req" method="POST" action="{{ route('tenant.scheduling.timeoff.store') }}" style="display:none;padding:14px 15px;border-bottom:.5px solid var(--ia-border)" class="ms-f">
        @csrf
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px">
          <div><label>From</label><input type="date" name="starts_on" required></div>
          <div><label>To</label><input type="date" name="ends_on" required></div>
        </div>
        <label>Type</label>
        <select name="type"><option value="vacation">Vacation</option><option value="personal">Personal</option><option value="sick">Sick</option><option value="unavailable">Unavailable</option></select>
        <label>Reason (optional)</label>
        <input type="text" name="reason" maxlength="500" placeholder="e.g. family trip">
        <button class="ms-btn p" type="submit">Submit request</button>
      </form>

      @forelse($requests as $r)
        <div class="ms-row">
          <span><b>{{ tlocal_date($r->starts_at) }}</b>@if(tlocal_date($r->starts_at) !== tlocal_date($r->ends_at)) – {{ tlocal_date($r->ends_at) }}@endif
            <span style="color:var(--ia-text-muted)"> · {{ $r->type }}</span></span>
          <span class="ms-pill {{ ['pending' => 'a', 'approved' => 'g', 'denied' => 'r'][$r->status] ?? 'a' }}">{{ $r->status }}</span>
        </div>
      @empty
        <div class="ms-empty">No time-off requests yet.</div>
      @endforelse
    </div>
  </div>
</div>
@endsection

