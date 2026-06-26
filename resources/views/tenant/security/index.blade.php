@extends('layouts.tenant.app')
@php
  $pageTitle = 'Security';
@endphp

@push('styles')
<style>
.sec-tabs { display:flex; gap:8px; border-bottom:0.5px solid var(--ia-border); margin-bottom:20px; overflow-x:auto }
.sec-tabs::-webkit-scrollbar { display:none }
.sec-tab { padding:10px 16px; background:transparent; border:none; border-bottom:2px solid transparent; color:var(--ia-muted, rgba(255,255,255,.55)); font-size:13px; font-weight:500; cursor:pointer; white-space:nowrap; font-family:inherit }
.sec-tab:hover { color:var(--ia-text) }
.sec-tab.active { color:var(--ia-text); border-bottom-color:var(--ia-accent) }
.sec-pane { display:none }
.sec-pane.active { display:block }

.sec-device-row { display:grid; grid-template-columns: 1fr auto; gap:14px; padding:14px 16px; border-bottom:0.5px solid var(--ia-border); align-items:center }
.sec-device-row:last-child { border-bottom:none }
.sec-device-label { font-weight:500; font-size:14px }
.sec-device-meta { font-size:12px; opacity:.55; margin-top:4px }

.sec-form-row { display:grid; grid-template-columns: 1fr 200px; gap:20px; padding:16px 0; border-bottom:0.5px solid var(--ia-border); align-items:start }
.sec-form-row:last-child { border-bottom:none }
.sec-form-row label { font-size:13px; font-weight:500; display:block; margin-bottom:4px }
.sec-form-row .hint { font-size:11.5px; opacity:.55; line-height:1.45 }
.sec-form-row input[type=number] { width:100%; padding:8px 12px; background:rgba(255,255,255,.04); border:0.5px solid var(--ia-border); border-radius:6px; color:var(--ia-text); font-family:inherit; font-size:13px }
.sec-form-row input[type=number]:focus { outline:none; border-color:var(--ia-accent) }
.sec-suffix { display:inline-block; font-size:12px; opacity:.5; margin-left:8px }
</style>
@endpush

@section('content')

<div class="ia-page-head">
  <div class="ia-page-head-left">
    <h1 class="ia-page-title">Security</h1>
    <p class="ia-page-subtitle">Trusted devices and sign-in policy</p>
  </div>
</div>

<div class="sec-tabs">
  <button type="button" class="sec-tab active" data-sec-tab="devices">Trusted devices</button>
  <button type="button" class="sec-tab" data-sec-tab="policy">Sign-in policy</button>
</div>

{{-- ==== Trusted devices pane ==== --}}
<div class="sec-pane active" id="sec-pane-devices">

  @if($devices->isEmpty())
    <div class="ia-empty-state" style="padding:40px 20px;text-align:center;border:0.5px dashed var(--ia-border);border-radius:8px">
      <div style="font-size:14px;font-weight:500;margin-bottom:6px">No trusted devices</div>
      <div style="font-size:12px;opacity:.5">When staff check "Trust this device" at sign-in, the browser appears here.</div>
    </div>
  @else
    <div style="margin-bottom:12px;display:flex;justify-content:space-between;align-items:center">
      <div style="font-size:13px;opacity:.65">{{ $devices->count() }} active {{ Str::plural('device', $devices->count()) }}</div>
      <form method="POST" action="{{ route('tenant.security.devices.revoke-all') }}">
        @csrf
        <button type="submit" class="ia-btn ia-btn--ghost ia-btn--sm" data-confirm="Revoke ALL trusted devices? Every browser will require email + password on next visit.">Revoke all</button>
      </form>
    </div>

    <div class="ia-table-wrap">
      @foreach($devices as $d)
      <div class="sec-device-row">
        <div>
          <div class="sec-device-label">{{ $d->label ?: 'Unnamed device' }}</div>
          <div class="sec-device-meta">
            Last used {{ $d->last_used_at?->diffForHumans() ?? '—' }}
            · IP {{ $d->ip_last_seen ?? '—' }}
            @if($d->expires_at)
              · Expires {{ $d->expires_at->diffForHumans() }}
            @endif
          </div>
          @if($d->user_agent_seen)
            <div class="sec-device-meta" style="margin-top:2px;font-family:monospace;font-size:10.5px;opacity:.4">{{ Str::limit($d->user_agent_seen, 90) }}</div>
          @endif
        </div>
        <form method="POST" action="{{ route('tenant.security.device.revoke', $d->id) }}">
          @csrf
          <button type="submit" class="ia-btn ia-btn--danger ia-btn--sm" data-confirm="Revoke this device?">Revoke</button>
        </form>
      </div>
      @endforeach
    </div>
  @endif
</div>

{{-- ==== Sign-in policy pane ==== --}}
<div class="sec-pane" id="sec-pane-policy">
  <form method="POST" action="{{ route('tenant.security.settings.update') }}">
    @csrf @method('PATCH')

    <div class="sec-form-row">
      <div>
        <label>Idle lock threshold</label>
        <div class="hint">After this much inactivity, signed-in staff see the PIN unlock overlay. The page they're on stays intact underneath. Range: 30–3600 seconds.</div>
      </div>
      <div>
        <input type="number" name="pin_idle_threshold_sec" min="30" max="3600" step="10" value="{{ old('pin_idle_threshold_sec', $policy['pin_idle_threshold_sec']) }}" required>
        <span class="sec-suffix">seconds</span>
      </div>
    </div>

    <div class="sec-form-row">
      <div>
        <label>Device trust duration</label>
        <div class="hint">How long a "Trust this device" cookie stays valid before requiring email + password again. Each use slides the window forward. Range: 1–365 days.</div>
      </div>
      <div>
        <input type="number" name="device_trust_expiry_days" min="1" max="365" step="1" value="{{ old('device_trust_expiry_days', $policy['device_trust_expiry_days']) }}" required>
        <span class="sec-suffix">days</span>
      </div>
    </div>

    <div class="sec-form-row">
      <div>
        <label>Switch-location PIN sticky window</label>
        <div class="hint">After a successful PIN re-prompt for location switch, how long until the next switch also re-prompts. Set to 0 to always prompt. Range: 0–3600 seconds.</div>
      </div>
      <div>
        <input type="number" name="switch_location_sticky_sec" min="0" max="3600" step="10" value="{{ old('switch_location_sticky_sec', $policy['switch_location_sticky_sec']) }}" required>
        <span class="sec-suffix">seconds</span>
      </div>
    </div>

    <div style="margin-top:20px;display:flex;justify-content:flex-end">
      <button type="submit" class="ia-btn ia-btn--primary">Save policy</button>
    </div>

  </form>
</div>

@endsection

@push('scripts')
<script>
document.querySelectorAll('.sec-tab').forEach(tab => {
  tab.addEventListener('click', () => {
    document.querySelectorAll('.sec-tab').forEach(t => t.classList.remove('active'));
    document.querySelectorAll('.sec-pane').forEach(p => p.classList.remove('active'));
    tab.classList.add('active');
    document.getElementById('sec-pane-' + tab.dataset.secTab).classList.add('active');
  });
});
</script>
@endpush
