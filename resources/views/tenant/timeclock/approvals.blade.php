@extends('layouts.tenant.app')

{{-- MARKER-PATCH-616 — Approvals + pay periods + settings. --}}

@section('title', 'Time clock · Approvals')

@push('styles')
<style>
.ta-sub { display:flex; gap:20px; border-bottom:.5px solid var(--ia-border); margin-bottom:20px; }
.ta-sub a { padding:11px 2px; font-size:13px; color:var(--ia-text-muted); border-bottom:2px solid transparent; margin-bottom:-.5px; text-decoration:none; }
.ta-sub a.on { color:var(--ia-text); border-bottom-color:var(--ia-accent); font-weight:600; }
.ta-bar { display:flex; align-items:center; gap:10px; margin-bottom:16px; flex-wrap:wrap; }
.ta-sel { background:var(--ia-surface-2,#1a1a1a); border:1px solid var(--ia-border); color:var(--ia-text); border-radius:7px; padding:8px 11px; font-size:12.5px; }
.ta-card { border:.5px solid var(--ia-border); border-radius:12px; overflow:hidden; background:var(--ia-surface); margin-bottom:16px; }
.ta-h { padding:13px 15px; border-bottom:.5px solid var(--ia-border); display:flex; justify-content:space-between; align-items:center; }
.ta-h .t { font-weight:700; font-size:13px; }
.ta-lock { font-size:10px; text-transform:uppercase; letter-spacing:.05em; padding:3px 9px; border-radius:999px; font-weight:700; }
.ta-lock.open { background:rgba(190,242,100,.14); color:var(--ia-accent); }
.ta-lock.locked { background:rgba(248,113,113,.14); color:#f87171; }
.ta-row { display:grid; grid-template-columns:1.6fr 1fr 1fr 1.2fr; padding:12px 15px; border-bottom:.5px dashed var(--ia-border); font-size:12.5px; align-items:center; }
.ta-row:last-child { border-bottom:none; }
.ta-row.hd { background:var(--ia-surface-2,#1a1a1a); font-size:10px; text-transform:uppercase; letter-spacing:.05em; color:var(--ia-text-muted); font-weight:600; }
.ta-flag { font-size:10px; padding:1px 7px; border-radius:999px; font-weight:700; }
.ta-flag.ok { background:rgba(190,242,100,.14); color:var(--ia-accent); }
.ta-flag.warn { background:rgba(245,158,11,.14); color:#F59E0B; }
.ta-flag.appr { background:rgba(190,242,100,.14); color:var(--ia-accent); }
.ta-btn { padding:6px 13px; border-radius:7px; font-size:12px; font-weight:600; cursor:pointer; border:.5px solid var(--ia-border-2,rgba(255,255,255,.2)); background:transparent; color:var(--ia-text); }
.ta-btn.p { background:var(--ia-accent); color:var(--ia-accent-text); border:none; }
.ta-foot { padding:14px 15px; display:flex; justify-content:flex-end; gap:10px; }
.ta-set { display:grid; grid-template-columns:1fr 1fr; gap:14px; padding:16px; }
.ta-f label { display:block; font-size:10px; text-transform:uppercase; letter-spacing:.05em; color:var(--ia-text-muted); margin-bottom:5px; font-weight:600; }
.ta-f input, .ta-f select { width:100%; padding:9px 11px; background:var(--ia-surface-2,#1a1a1a); border:1px solid var(--ia-border); border-radius:7px; color:var(--ia-text); font-size:13px; }
.ta-warn { font-size:11px; color:var(--ia-text-muted); padding:0 15px 14px; line-height:1.5; }
</style>
@endpush

@section('content')
<div style="max-width:900px">
  <h1 style="font-size:19px;font-weight:700;margin-bottom:4px">Time clock</h1>
  <div class="ta-sub">
    <a href="{{ route('tenant.timeclock.index') }}">My time</a>
    <a href="{{ route('tenant.timeclock.team') }}">Team</a>
    <a href="{{ route('tenant.timeclock.reports') }}">Reports</a>
    <a href="{{ route('tenant.timeclock.approvals') }}" class="on">Approvals</a>
  </div>

  {{-- period picker --}}
  <form method="GET" action="{{ route('tenant.timeclock.approvals') }}" class="ta-bar">
    <span style="font-size:12px;color:var(--ia-text-muted)">Pay period</span>
    <select name="period" class="ta-sel" onchange="this.form.submit()">
      @foreach($periods as $p)
        <option value="{{ $p->id }}" @selected($p->id === $period->id)>
          {{ tlocal_date($p->starts_at) }} – {{ tlocal_date($p->ends_at) }}{{ $p->status === 'locked' ? ' (locked)' : '' }}
        </option>
      @endforeach
    </select>
  </form>

  {{-- sign-off --}}
  <div class="ta-card">
    <div class="ta-h">
      <span class="t">{{ tlocal_date($period->starts_at) }} – {{ tlocal_date($period->ends_at) }}</span>
      <span class="ta-lock {{ $period->status }}">{{ $period->status }}</span>
    </div>

    <div class="ta-row hd"><span>Staff</span><span>Hours</span><span>Status</span><span></span></div>
    @forelse($people as $uid => $pp)
      <div class="ta-row">
        <span>{{ $pp['name'] }}</span>
        <span>{{ intdiv($pp['minutes'],60) }}h {{ $pp['minutes'] % 60 }}m</span>
        <span>
          @if($pp['approved'])
            <span class="ta-flag appr">approved</span>
          @elseif($pp['flags'] > 0)
            <span class="ta-flag warn">{{ $pp['flags'] }} flag{{ $pp['flags'] > 1 ? 's' : '' }}</span>
          @else
            <span class="ta-flag ok">ready</span>
          @endif
        </span>
        <span style="text-align:right">
          @if(!$pp['approved'] && $period->status === 'open')
            <form method="POST" action="{{ route('tenant.timeclock.approve') }}" style="display:inline">
              @csrf
              <input type="hidden" name="pay_period_id" value="{{ $period->id }}">
              <input type="hidden" name="tenant_user_id" value="{{ $uid }}">
              <button class="ta-btn" type="submit">Approve</button>
            </form>
          @elseif($pp['approved'])
            <span style="font-size:11px;color:var(--ia-text-muted)">{{ $pp['approver'] ? tlocal_date($pp['approver']) : '' }}</span>
          @endif
        </span>
      </div>
    @empty
      <div class="ta-row"><span style="grid-column:1/-1;color:var(--ia-text-muted);text-align:center;padding:20px">No punches in this period.</span></div>
    @endforelse

    <div class="ta-foot">
      @if($period->status === 'open')
        <form method="POST" action="{{ route('tenant.timeclock.period.lock') }}"
              onsubmit="return confirm('Lock this period as the payroll record? Edits after lock require reopening with a reason.')">
          @csrf<input type="hidden" name="pay_period_id" value="{{ $period->id }}">
          <button class="ta-btn p" type="submit">Lock period &amp; finalize →</button>
        </form>
      @else
        <form method="POST" action="{{ route('tenant.timeclock.period.reopen') }}" style="display:flex;gap:8px;align-items:center">
          @csrf<input type="hidden" name="pay_period_id" value="{{ $period->id }}">
          <input type="text" name="reason" required placeholder="reason to reopen…" class="ta-sel" style="min-width:220px">
          <button class="ta-btn" type="submit">Reopen</button>
        </form>
      @endif
    </div>
  </div>
  <p class="ta-warn" style="padding-left:0">A locked period is the payroll source of truth. When the accounting build lands, locked periods produce the payroll journal line for your books.</p>

  {{-- settings --}}
  <div class="ta-card">
    <div class="ta-h"><span class="t">Time clock policy</span></div>
    <form method="POST" action="{{ route('tenant.timeclock.settings') }}">
      @csrf
      @php $s = tenant()->settings ?? []; @endphp
      <div class="ta-set">
        <div class="ta-f">
          <label>Pay period cycle</label>
          <select name="timeclock_pay_cycle">
            @foreach(['weekly'=>'Weekly','biweekly'=>'Bi-weekly (14 days)','semi_monthly'=>'Semi-monthly (1st & 16th)','monthly'=>'Monthly'] as $v=>$l)
              <option value="{{ $v }}" @selected(($s['timeclock_pay_cycle'] ?? 'semi_monthly')===$v)>{{ $l }}</option>
            @endforeach
          </select>
        </div>
        <div class="ta-f">
          <label>Overtime threshold (hours / week)</label>
          <input type="number" name="timeclock_ot_threshold_hours" min="0" max="168" value="{{ $s['timeclock_ot_threshold_hours'] ?? 40 }}">
        </div>
        <div class="ta-f">
          <label>Auto-close open punches after (hours)</label>
          <input type="number" name="timeclock_autoclose_hours" min="1" max="48" value="{{ $s['timeclock_autoclose_hours'] ?? 10 }}">
        </div>
        <div class="ta-f" style="display:flex;align-items:flex-end">
          <button class="ta-btn p" type="submit" style="width:100%">Save policy</button>
        </div>
      </div>
    </form>
  </div>
</div>
@endsection

