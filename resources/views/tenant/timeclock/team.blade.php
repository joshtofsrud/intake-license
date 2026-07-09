@extends('layouts.tenant.app')

{{-- MARKER-PATCH-614 — Team timesheet (manager). Week grid + edits + audit. --}}

@section('title', 'Time clock · Team')

@push('styles')
<style>
.tt-sub { display:flex; gap:20px; border-bottom:.5px solid var(--ia-border); margin-bottom:20px; }
.tt-sub a { padding:11px 2px; font-size:13px; color:var(--ia-text-muted); border-bottom:2px solid transparent; margin-bottom:-.5px; text-decoration:none; }
.tt-sub a.on { color:var(--ia-text); border-bottom-color:var(--ia-accent); font-weight:600; }
.tt-bar { display:flex; align-items:center; gap:10px; margin-bottom:14px; }
.tt-grid { border:.5px solid var(--ia-border); border-radius:12px; overflow:hidden; background:var(--ia-surface); }
.tt-row { display:grid; grid-template-columns:1.5fr repeat(7,1fr) 90px; border-bottom:.5px solid var(--ia-border); }
.tt-row:last-child { border-bottom:none; }
.tt-row.hd { background:var(--ia-surface-2,#1a1a1a); }
.tt-c { padding:11px 12px; font-size:12.5px; border-right:.5px dashed var(--ia-border); font-variant-numeric:tabular-nums; }
.tt-c:last-child { border-right:none; }
.tt-c.hd { font-size:10px; text-transform:uppercase; letter-spacing:.05em; color:var(--ia-text-muted); font-weight:600; }
.tt-c.name { font-weight:600; }
.tt-c.name .r { font-size:10px; color:var(--ia-text-muted); font-weight:400; margin-left:5px; }
.tt-c.total { font-weight:700; }
.tt-flag { font-size:9px; text-transform:uppercase; letter-spacing:.04em; padding:1px 5px; border-radius:4px; margin-left:4px; }
.tt-flag.open { background:rgba(190,242,100,.14); color:var(--ia-accent); }
.tt-flag.auto { background:rgba(245,158,11,.14); color:#F59E0B; }
.tt-c .z { color:var(--ia-text-muted); opacity:.4; }
.tt-audit { border:.5px solid var(--ia-border); border-radius:12px; background:var(--ia-surface); margin-top:16px; }
.tt-audit .h { padding:12px 15px; border-bottom:.5px solid var(--ia-border); font-weight:700; font-size:13px; }
.tt-a { padding:9px 15px; border-bottom:.5px dashed var(--ia-border); font-size:11.5px; color:var(--ia-text-muted); }
.tt-a:last-child { border:none; }
.tt-a b { color:var(--ia-text); font-weight:600; }
.tt-btn { padding:7px 13px; border-radius:7px; font-size:12px; font-weight:600; cursor:pointer; border:.5px solid var(--ia-border-2,rgba(255,255,255,.2)); background:transparent; color:var(--ia-text); }
.tt-btn.p { background:var(--ia-accent); color:var(--ia-accent-text); border:none; }
/* modal */
.tt-mov { position:fixed; inset:0; background:rgba(0,0,0,.6); display:none; align-items:center; justify-content:center; z-index:50; }
.tt-mov.on { display:flex; }
.tt-modal { width:420px; background:var(--ia-bg,#0c0c0c); border:1px solid var(--ia-border-2,rgba(255,255,255,.2)); border-radius:14px; }
.tt-mh { padding:15px 18px; border-bottom:.5px solid var(--ia-border); font-weight:700; display:flex; justify-content:space-between; }
.tt-mb { padding:18px; }
.tt-mb label { display:block; font-size:10px; text-transform:uppercase; letter-spacing:.05em; color:var(--ia-text-muted); margin:0 0 5px; font-weight:600; }
.tt-mb input, .tt-mb select { width:100%; padding:9px 11px; margin-bottom:13px; background:var(--ia-surface-2,#1a1a1a); border:1px solid var(--ia-border); border-radius:7px; color:var(--ia-text); font-size:13px; }
.tt-mf { padding:13px 18px; border-top:.5px solid var(--ia-border); display:flex; justify-content:flex-end; gap:9px; }
</style>
@endpush

@section('content')
<div style="max-width:1000px">
  <h1 style="font-size:19px;font-weight:700;margin-bottom:4px">Time clock</h1>

  <div class="tt-sub">
    <a href="{{ route('tenant.timeclock.index') }}">My time</a>
    <a href="{{ route('tenant.timeclock.team') }}" class="on">Team</a>
  </div>

  <div class="tt-bar">
    <a class="tt-btn" href="{{ route('tenant.timeclock.team', ['week' => $weekStart->copy()->subWeek()->toDateString()]) }}">◀</a>
    <span style="font-weight:600;font-size:13px">Week of {{ $weekStart->format('M j') }} – {{ $weekStart->copy()->endOfWeek()->format('M j, Y') }}</span>
    <a class="tt-btn" href="{{ route('tenant.timeclock.team', ['week' => $weekStart->copy()->addWeek()->toDateString()]) }}">▶</a>
    @if($canEdit)
      <span style="margin-left:auto"></span>
      <button class="tt-btn p" type="button" onclick="document.getElementById('tt-add').classList.add('on')">Add punch</button>
    @endif
  </div>

  <div class="tt-grid">
    <div class="tt-row hd">
      <div class="tt-c hd">Staff</div>
      @foreach($days as $d)<div class="tt-c hd">{{ $d->format('D') }} <span style="opacity:.6">{{ $d->format('j') }}</span></div>@endforeach
      <div class="tt-c hd">Total</div>
    </div>
    @foreach($byUser as $uid => $u)
      <div class="tt-row">
        <div class="tt-c name">{{ $u['name'] }}<span class="r">{{ $u['role'] }}</span></div>
        @for($i = 0; $i < 7; $i++)
          @php $m = $u['days'][$i]; $flag = $u['flags'][$i]; @endphp
          <div class="tt-c">
            @if($m > 0)
              {{ intdiv($m,60) }}h {{ $m % 60 }}m
              @if($flag === 'open')<span class="tt-flag open">on</span>@elseif($flag === 'auto')<span class="tt-flag auto">auto</span>@endif
            @else
              <span class="z">—</span>
            @endif
          </div>
        @endfor
        <div class="tt-c total">{{ intdiv($u['total'],60) }}h {{ $u['total'] % 60 }}m</div>
      </div>
    @endforeach
  </div>

  {{-- audit trail --}}
  <div class="tt-audit">
    <div class="h">Audit trail</div>
    @forelse($audits as $a)
      <div class="tt-a">{{ tlocal_datetime($a->created_at) }} — <b>{{ $a->actor->name ?? 'system' }}</b> {{ $a->detail }}</div>
    @empty
      <div class="tt-a">No changes recorded yet.</div>
    @endforelse
  </div>
</div>

@if($canEdit)
{{-- add-punch modal --}}
<div class="tt-mov" id="tt-add">
  <div class="tt-modal">
    <form method="POST" action="{{ route('tenant.timeclock.punch.create') }}">@csrf
      <div class="tt-mh"><span>Add punch</span><span style="cursor:pointer" onclick="document.getElementById('tt-add').classList.remove('on')">×</span></div>
      <div class="tt-mb">
        <label>Staff member</label>
        <select name="tenant_user_id" required>
          @foreach($byUser as $uid => $u)<option value="{{ $uid }}">{{ $u['name'] }}</option>@endforeach
        </select>
        <label>Clock in</label><input type="datetime-local" name="clock_in_at" required>
        <label>Clock out (optional)</label><input type="datetime-local" name="clock_out_at">
        <label>Reason (required · audit)</label><input type="text" name="reason" required placeholder="e.g. forgot to clock in on route">
      </div>
      <div class="tt-mf">
        <button type="button" class="tt-btn" onclick="document.getElementById('tt-add').classList.remove('on')">Cancel</button>
        <button type="submit" class="tt-btn p">Add punch</button>
      </div>
    </form>
  </div>
</div>
@endif
@endsection

