@extends('layouts.tenant.app')

{{-- MARKER-PATCH-615 — Time clock reports. Hours summary + OT + exports. --}}

@section('title', 'Time clock · Reports')

@push('styles')
<style>
.tr-sub { display:flex; gap:20px; border-bottom:.5px solid var(--ia-border); margin-bottom:20px; }
.tr-sub a { padding:11px 2px; font-size:13px; color:var(--ia-text-muted); border-bottom:2px solid transparent; margin-bottom:-.5px; text-decoration:none; }
.tr-sub a.on { color:var(--ia-text); border-bottom-color:var(--ia-accent); font-weight:600; }
.tr-bar { display:flex; align-items:center; gap:8px; margin-bottom:16px; flex-wrap:wrap; }
.tr-sel { background:var(--ia-surface-2,#1a1a1a); border:1px solid var(--ia-border); color:var(--ia-text); border-radius:7px; padding:8px 11px; font-size:12.5px; }
.tr-btn { padding:7px 13px; border-radius:7px; font-size:12px; font-weight:600; cursor:pointer; border:.5px solid var(--ia-border-2,rgba(255,255,255,.2)); background:transparent; color:var(--ia-text); text-decoration:none; }
.tr-btn.p { background:var(--ia-accent); color:var(--ia-accent-text); border:none; }
.tr-card { border:.5px solid var(--ia-border); border-radius:12px; overflow:hidden; background:var(--ia-surface); }
.tr-h { padding:12px 15px; border-bottom:.5px solid var(--ia-border); display:flex; justify-content:space-between; align-items:center; }
.tr-h .t { font-weight:700; font-size:13px; }
.tr-row { display:grid; grid-template-columns:1.5fr 1fr 1fr 1fr 1fr .8fr 1fr; padding:11px 15px; border-bottom:.5px dashed var(--ia-border); font-size:12.5px; font-variant-numeric:tabular-nums; }
.tr-row:last-child { border-bottom:none; }
.tr-row.hd { background:var(--ia-surface-2,#1a1a1a); font-size:10px; text-transform:uppercase; letter-spacing:.05em; color:var(--ia-text-muted); font-weight:600; }
.tr-row.tot { background:var(--ia-surface-2,#1a1a1a); font-weight:700; }
.tr-row .ot { color:#F59E0B; }
.tr-row .z { color:var(--ia-text-muted); opacity:.4; }
.tr-form { display:none; gap:8px; padding:12px 15px; border-bottom:.5px solid var(--ia-border); align-items:center; flex-wrap:wrap; }
.tr-inp { padding:7px 10px; border-radius:6px; border:.5px solid var(--ia-border); background:var(--ia-input-bg,#0a0a0a); color:var(--ia-text); font-size:12.5px; }
</style>
@endpush

@php
  if (!function_exists('tr_hm')) { function tr_hm($m){ return intdiv($m,60).'h '.($m%60).'m'; } }
@endphp

@section('content')
<div style="max-width:1000px">
  <h1 style="font-size:19px;font-weight:700;margin-bottom:4px">Time clock</h1>
  <div class="tr-sub">
    <a href="{{ route('tenant.timeclock.index') }}">My time</a>
    <a href="{{ route('tenant.timeclock.team') }}">Team</a>
    <a href="{{ route('tenant.timeclock.reports') }}" class="on">Reports</a>
    <a href="{{ route('tenant.timeclock.approvals') }}">Approvals</a>
  </div>

  {{-- range picker --}}
  <form method="GET" action="{{ route('tenant.timeclock.reports') }}" class="tr-bar" id="tr-rangeform">
    <select name="preset" class="tr-sel" onchange="document.getElementById('tr-custom').style.display=this.value==='custom'?'flex':'none';if(this.value!=='custom')this.form.submit()">
      @foreach(['this_week'=>'This week','last_week'=>'Last week','this_month'=>'This month','last_month'=>'Last month','custom'=>'Custom range…'] as $val=>$lbl)
        <option value="{{ $val }}" @selected($preset===$val)>{{ $lbl }}</option>
      @endforeach
    </select>
    <span id="tr-custom" style="display:{{ $preset==='custom'?'flex':'none' }};gap:6px;align-items:center">
      <input type="date" name="from" class="tr-inp" value="{{ request('from') }}">
      <span style="color:var(--ia-text-muted);font-size:12px">to</span>
      <input type="date" name="to_date" class="tr-inp" value="{{ request('to_date') }}">
      <button type="submit" class="tr-btn">Apply</button>
    </span>
    <span style="margin-left:auto;display:flex;gap:8px">
      <a class="tr-btn" href="{{ route('tenant.timeclock.reports.csv', request()->query()) }}">CSV</a>
      <a class="tr-btn" href="{{ route('tenant.timeclock.reports.print', request()->query()) }}" target="_blank" rel="noopener">Print</a>
      <button type="button" class="tr-btn p" onclick="document.getElementById('tr-email').style.display='flex'">Email</button>
    </span>
  </form>

  <div class="tr-card">
    <div class="tr-h"><span class="t">Hours summary · {{ $label }}</span></div>

    <form id="tr-email" method="POST" action="{{ route('tenant.timeclock.reports.email', request()->query()) }}" class="tr-form">
      @csrf
      <input type="email" name="to" required placeholder="send report to…" class="tr-inp" value="{{ auth('tenant')->user()->email ?? '' }}">
      <button type="submit" class="tr-btn">Send</button>
    </form>

    <div class="tr-row hd">
      <span>Staff</span><span>Regular</span><span>OT (1.5×)</span><span>DT (2×)</span><span>Total</span><span>Shifts</span><span>Avg shift</span>
    </div>
    @forelse($rows as $r)
      @php $dt = $r['dt'] ?? 0; $total = $r['regular'] + $r['ot'] + $dt; @endphp
      <div class="tr-row">
        <span>{{ $r['name'] }}</span>
        <span>{{ tr_hm($r['regular']) }}</span>
        <span class="{{ $r['ot'] ? 'ot' : 'z' }}">{{ $r['ot'] ? tr_hm($r['ot']) : '—' }}</span>
        <span class="{{ $dt ? 'ot' : 'z' }}">{{ $dt ? tr_hm($dt) : '—' }}</span>
        <span>{{ tr_hm($total) }}</span>
        <span>{{ $r['shifts'] }}</span>
        <span>{{ $r['shifts'] ? tr_hm(intdiv($total, $r['shifts'])) : '—' }}</span>
      </div>
    @empty
      <div class="tr-row"><span style="grid-column:1/-1;color:var(--ia-text-muted);text-align:center;padding:20px">No punches in this range.</span></div>
    @endforelse
    @if(!empty($rows))
      @php $gt = $totals['regular'] + $totals['ot'] + ($totals['dt'] ?? 0); @endphp
      <div class="tr-row tot">
        <span>Total</span>
        <span>{{ tr_hm($totals['regular']) }}</span>
        <span class="{{ $totals['ot'] ? 'ot' : '' }}">{{ tr_hm($totals['ot']) }}</span>
        <span class="{{ ($totals['dt'] ?? 0) ? 'ot' : '' }}">{{ tr_hm($totals['dt'] ?? 0) }}</span>
        <span>{{ tr_hm($gt) }}</span>
        <span>{{ $totals['shifts'] }}</span>
        <span></span>
      </div>
    @endif
  </div>

  <p style="font-size:11px;color:var(--ia-text-muted);margin-top:12px">Overtime follows your policy (daily and/or weekly, with the greater applied) set under Approvals → Time clock policy.</p>
</div>
@endsection

