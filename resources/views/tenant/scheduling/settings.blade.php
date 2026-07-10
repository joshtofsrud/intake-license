@extends('layouts.tenant.app')

{{-- MARKER-PATCH-624 — Scheduling settings: feature toggles + policy. --}}

@section('title', 'Scheduling · Settings')

@push('styles')
<style>
.ss-sub { display:flex; gap:20px; border-bottom:.5px solid var(--ia-border); margin-bottom:18px; }
.ss-sub a { padding:11px 2px; font-size:13px; color:var(--ia-text-muted); border-bottom:2px solid transparent; margin-bottom:-.5px; text-decoration:none; }
.ss-sub a.on { color:var(--ia-text); border-bottom-color:var(--ia-accent); font-weight:600; }
.ss-card { border:.5px solid var(--ia-border); border-radius:12px; background:var(--ia-surface); max-width:620px; }
.ss-h { padding:12px 15px; border-bottom:.5px solid var(--ia-border); font-weight:700; font-size:13px; }
.ss-row { display:flex; justify-content:space-between; align-items:center; gap:14px; padding:13px 15px; border-bottom:.5px dashed var(--ia-border); font-size:12.5px; }
.ss-row:last-child { border:none; }
.ss-row .d { font-size:11px; color:var(--ia-text-muted); margin-top:2px; line-height:1.45; }
.ss-tog { position:relative; width:34px; height:19px; flex:none; }
.ss-tog input { position:absolute; opacity:0; width:100%; height:100%; margin:0; cursor:pointer; z-index:2; }
.ss-tog i { position:absolute; inset:0; border-radius:10px; background:var(--ia-surface-2,#1a1a1a); border:1px solid var(--ia-border-2,rgba(255,255,255,.2)); transition:background .15s; }
.ss-tog i::after { content:''; position:absolute; top:1.5px; left:2px; width:14px; height:14px; border-radius:50%; background:#888; transition:left .15s, background .15s; }
.ss-tog input:checked + i { background:var(--ia-accent); border-color:var(--ia-accent); }
.ss-tog input:checked + i::after { left:16px; background:var(--ia-accent-text,#0a0a0a); }
.ss-num { width:76px; padding:7px 10px; background:var(--ia-surface-2,#1a1a1a); border:1px solid var(--ia-border); border-radius:7px; color:var(--ia-text); font-size:12.5px; }
.ss-save { padding:9px 18px; border-radius:7px; font-size:12.5px; font-weight:600; cursor:pointer; border:none; background:var(--ia-accent); color:var(--ia-accent-text); }
</style>
@endpush

@section('content')
<div style="max-width:760px">
  <h1 style="font-size:19px;font-weight:700;margin-bottom:4px">Scheduling</h1>
  <div class="ss-sub">
    <a href="{{ route('tenant.scheduling.index') }}">Schedule builder</a>
    @if(auth('tenant')->user()?->can('scheduling.timeoff'))
      <a href="{{ route('tenant.scheduling.timeoff') }}">Time off</a>
    @endif
    @if($set['availability'])
      <a href="{{ route('tenant.scheduling.availability') }}">Availability</a>
    @endif
    <a href="{{ route('tenant.scheduling.mine') }}">My schedule</a>
    <a href="{{ route('tenant.scheduling.settings') }}" class="on">Settings</a>
  </div>

  <form method="POST" action="{{ route('tenant.scheduling.settings.save') }}">
    @csrf
    <div class="ss-card">
      <div class="ss-h">Features</div>
      <div class="ss-row">
        <span>Booking demand overlay<div class="d">Show booking density from your calendar above the builder grid, so you staff against real load.</div></span>
        <label class="ss-tog"><input type="checkbox" name="scheduling_demand_overlay" value="1" @checked($set['demand_overlay'])><i></i></label>
      </div>
      <div class="ss-row">
        <span>Staff availability<div class="d">Staff set recurring day/time availability; the builder flags conflicts (never blocks).</div></span>
        <label class="ss-tog"><input type="checkbox" name="scheduling_availability" value="1" @checked($set['availability'])><i></i></label>
      </div>
      <div class="ss-row">
        <span>Notify on publish<div class="d">Email each person their shifts when a week is published, over your branded email.</div></span>
        <label class="ss-tog"><input type="checkbox" name="scheduling_notify_publish" value="1" @checked($set['notify_publish'])><i></i></label>
      </div>
      <div class="ss-row">
        <span>Time-off minimum notice<div class="d">Requests must start at least this many days out. 0 = no minimum.</div></span>
        <input type="number" name="scheduling_timeoff_notice_days" class="ss-num" min="0" max="60" value="{{ $set['timeoff_notice_days'] }}">
      </div>
      <div class="ss-row" style="justify-content:flex-end">
        <button class="ss-save" type="submit">Save settings</button>
      </div>
    </div>
  </form>
</div>
@endsection

