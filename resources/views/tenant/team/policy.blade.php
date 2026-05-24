{{-- MARKER-PATCH-129 — sign-in policy (was tab on /admin/security) --}}
@extends('layouts.tenant.app')
@php $pageTitle = 'Sign-in policy'; @endphp

@push('styles')
<style>
.tp-row { display:grid; grid-template-columns:1fr 200px; gap:20px; padding:16px 0; border-top:0.5px solid var(--ia-border); align-items:start; }
.tp-row:first-of-type { border-top:none; padding-top:2px; }
.tp-row label { font-size:13px; font-weight:500; display:block; margin-bottom:4px; }
.tp-row .hint { font-size:11.5px; color:var(--ia-text-dim); line-height:1.55; }
.tp-row input[type=number] { width:100%; padding:7px 10px; background:var(--ia-input-bg); border:0.5px solid var(--ia-border); border-radius:var(--ia-r-md); color:var(--ia-text); font-family:inherit; font-size:13px; }
.tp-suffix { font-size:11px; color:var(--ia-text-dim); margin-left:6px; }
.tp-back { font-size:12px; color:var(--ia-text-dim); display:inline-flex; align-items:center; gap:4px; margin-bottom:12px; text-decoration:none; }
.tp-back:hover { color:var(--ia-text-muted); }
</style>
@endpush

@section('content')
<a href="{{ route('tenant.team.index') }}" class="tp-back">← Team</a>

<div class="ia-page-head">
  <div class="ia-page-head-left">
    <h1 class="ia-page-title">Sign-in policy</h1>
    <p class="ia-page-subtitle">How strict sign-in is for everyone at {{ $currentTenant->name }}. Owner only.</p>
  </div>
</div>

<div class="ia-card">
  <form method="POST" action="{{ route('tenant.team.policy.update') }}">
    @csrf @method('PATCH')

    <div class="tp-row">
      <div>
        <label>Idle lock threshold</label>
        <div class="hint">After this much inactivity, signed-in staff see a PIN unlock overlay. Their work stays intact underneath.</div>
      </div>
      <div>
        <input type="number" name="pin_idle_threshold_sec" min="30" max="3600" step="10" value="{{ old('pin_idle_threshold_sec', $policy['pin_idle_threshold_sec']) }}" required>
        <span class="tp-suffix">seconds</span>
      </div>
    </div>

    <div class="tp-row">
      <div>
        <label>Device trust duration</label>
        <div class="hint">How long a "Trust this device" cookie stays valid before requiring email + password again. Each use slides the window forward.</div>
      </div>
      <div>
        <input type="number" name="device_trust_expiry_days" min="1" max="365" step="1" value="{{ old('device_trust_expiry_days', $policy['device_trust_expiry_days']) }}" required>
        <span class="tp-suffix">days</span>
      </div>
    </div>

    <div class="tp-row">
      <div>
        <label>Switch-location PIN sticky window</label>
        <div class="hint">After a successful PIN re-prompt for location switch, how long before the next switch also re-prompts. Set 0 to always prompt.</div>
      </div>
      <div>
        <input type="number" name="switch_location_sticky_sec" min="0" max="3600" step="10" value="{{ old('switch_location_sticky_sec', $policy['switch_location_sticky_sec']) }}" required>
        <span class="tp-suffix">seconds</span>
      </div>
    </div>

    <div style="margin-top:18px;display:flex;justify-content:flex-end">
      <button class="ia-btn ia-btn--primary">Save policy</button>
    </div>
  </form>
</div>
@endsection
