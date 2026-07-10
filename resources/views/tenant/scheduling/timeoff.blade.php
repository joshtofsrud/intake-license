@extends('layouts.tenant.app')

{{-- MARKER-PATCH-623 — Scheduling: time-off inbox (approver). --}}

@section('title', 'Scheduling · Time off')

@push('styles')
<style>
.to-sub { display:flex; gap:20px; border-bottom:.5px solid var(--ia-border); margin-bottom:18px; }
.to-sub a { padding:11px 2px; font-size:13px; color:var(--ia-text-muted); border-bottom:2px solid transparent; margin-bottom:-.5px; text-decoration:none; }
.to-sub a.on { color:var(--ia-text); border-bottom-color:var(--ia-accent); font-weight:600; }
.to-card { border:.5px solid var(--ia-border); border-radius:12px; background:var(--ia-surface); margin-bottom:16px; }
.to-h { padding:12px 15px; border-bottom:.5px solid var(--ia-border); font-weight:700; font-size:13px; display:flex; justify-content:space-between; }
.to-h .m { font-size:11px; color:var(--ia-text-muted); font-weight:500; }
.to-row { display:flex; gap:12px; align-items:center; padding:12px 15px; border-bottom:.5px dashed var(--ia-border); font-size:12.5px; }
.to-row:last-child { border:none; }
.to-av { width:30px; height:30px; border-radius:50%; background:var(--ia-surface-2,#1a1a1a); display:flex; align-items:center; justify-content:center; font-size:11px; font-weight:700; flex:none; }
.to-btn { padding:6px 13px; border-radius:7px; font-size:12px; font-weight:600; cursor:pointer; border:.5px solid var(--ia-border-2,rgba(255,255,255,.2)); background:transparent; color:var(--ia-text); }
.to-btn.p { background:var(--ia-accent); color:var(--ia-accent-text); border:none; }
.to-pill { font-size:10px; text-transform:uppercase; letter-spacing:.04em; border-radius:999px; padding:2px 9px; font-weight:700; }
.to-pill.g { background:rgba(190,242,100,.12); color:var(--ia-accent); }
.to-empty { padding:24px 15px; text-align:center; color:var(--ia-text-muted); font-size:12px; }
</style>
@endpush

@section('content')
<div style="max-width:760px">
  <h1 style="font-size:19px;font-weight:700;margin-bottom:4px">Scheduling</h1>
  <div class="to-sub">
    @if(auth('tenant')->user()?->can('scheduling.build'))
      <a href="{{ route('tenant.scheduling.index') }}">Schedule builder</a>
    @endif
    <a href="{{ route('tenant.scheduling.timeoff') }}" class="on">Time off</a>
    <a href="{{ route('tenant.scheduling.mine') }}">My schedule</a>
  </div>

  <div class="to-card">
    <div class="to-h">Pending requests <span class="m">{{ $pending->count() }}</span></div>
    @forelse($pending as $r)
      <div class="to-row">
        <div class="to-av">{{ mb_substr($r->user->name ?? '?', 0, 2) }}</div>
        <div style="flex:1">
          <b>{{ $r->user->name ?? 'Staff' }}</b> ·
          {{ tlocal_date($r->starts_at) }}@if(tlocal_date($r->starts_at) !== tlocal_date($r->ends_at)) – {{ tlocal_date($r->ends_at) }}@endif
          <span style="color:var(--ia-text-muted)">· {{ $r->type }}@if($r->reason) · “{{ $r->reason }}”@endif</span>
        </div>
        <form method="POST" action="{{ route('tenant.scheduling.timeoff.review', $r->id) }}" style="display:flex;gap:8px">
          @csrf
          <button class="to-btn" name="decision" value="denied" type="submit">Deny</button>
          <button class="to-btn p" name="decision" value="approved" type="submit">Approve</button>
        </form>
      </div>
    @empty
      <div class="to-empty">No pending requests.</div>
    @endforelse
  </div>

  <div class="to-card">
    <div class="to-h">Upcoming approved</div>
    @forelse($upcoming as $r)
      <div class="to-row">
        <div class="to-av">{{ mb_substr($r->user->name ?? '?', 0, 2) }}</div>
        <div style="flex:1"><b>{{ $r->user->name ?? 'Staff' }}</b> ·
          {{ tlocal_date($r->starts_at) }}@if(tlocal_date($r->starts_at) !== tlocal_date($r->ends_at)) – {{ tlocal_date($r->ends_at) }}@endif
          <span style="color:var(--ia-text-muted)">· {{ $r->type }}</span>
        </div>
        <span class="to-pill g">approved</span>
      </div>
    @empty
      <div class="to-empty">Nothing approved upcoming.</div>
    @endforelse
  </div>

  <p style="font-size:11px;color:var(--ia-text-muted)">Approved time off blocks those days in the schedule builder — you can't schedule over it.</p>
</div>
@endsection

