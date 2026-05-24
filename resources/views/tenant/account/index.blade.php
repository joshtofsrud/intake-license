{{-- MARKER-PATCH-129 — your own account --}}
@extends('layouts.tenant.app')
@php
  $pageTitle = 'Your account';
  $pinModeOn = $currentTenant->pin_tier_active;
@endphp

@push('styles')
<style>
.ac-head { display:flex; gap:16px; align-items:center; padding-bottom:18px; border-bottom:0.5px solid var(--ia-border); margin-bottom:20px; }
.ac-avatar { width:56px; height:56px; border-radius:50%; background:var(--ia-accent); color:var(--ia-accent-text); display:inline-flex; align-items:center; justify-content:center; font-size:18px; font-weight:600; }
.ac-h2 { font-size:20px; font-weight:600; margin:0; }
.ac-sub { font-size:12px; color:var(--ia-text-dim); margin-top:3px; }
.ac-actions { margin-left:auto; }
.ac-field { display:grid; grid-template-columns:180px 1fr; gap:16px; padding:12px 0; align-items:center; border-top:0.5px solid var(--ia-border); }
.ac-field:first-of-type { border-top:none; padding-top:2px; }
.ac-field-label { font-size:12px; color:var(--ia-text-dim); }
.ac-field-value { font-size:13px; display:flex; align-items:center; gap:10px; flex-wrap:wrap; }
.ac-field-hint { font-size:11px; color:var(--ia-text-dim); }
.ac-device { display:grid; grid-template-columns:1fr auto; gap:14px; padding:12px 0; border-top:0.5px solid var(--ia-border); align-items:center; }
.ac-device:first-of-type { border-top:none; padding-top:2px; }
.ac-device-label { font-size:13px; font-weight:500; }
.ac-device-meta { font-size:11px; color:var(--ia-text-dim); margin-top:3px; }
.ac-pin-pad { display:flex; gap:6px; }
.ac-pin-pad input { width:38px; height:44px; text-align:center; background:var(--ia-input-bg); border:0.5px solid var(--ia-border); color:var(--ia-text); font-family:var(--ia-font-mono); font-size:18px; border-radius:var(--ia-r-md); }
.ac-empty { padding:24px; text-align:center; border:0.5px dashed var(--ia-border-strong); border-radius:var(--ia-r-md); font-size:12px; color:var(--ia-text-dim); }
</style>
@endpush

@section('content')

<div class="ac-head">
  <div class="ac-avatar">{{ strtoupper(substr($me->name, 0, 2)) }}</div>
  <div>
    <h2 class="ac-h2">Your account</h2>
    <div class="ac-sub">{{ $me->name }} · {{ ucfirst($me->role) }} · signed in {{ $me->last_login_at?->diffForHumans() ?? 'just now' }}</div>
  </div>
  <div class="ac-actions">
    <form method="POST" action="{{ route('tenant.account.sign-out-everywhere') }}">
      @csrf
      <button class="ia-btn ia-btn--ghost ia-btn--sm" style="color:#F87171"
              data-confirm="Sign you out of every browser including this one?">Sign out everywhere</button>
    </form>
  </div>
</div>

{{-- Account --}}
<div class="ia-card" style="margin-bottom:14px">
  <div class="ia-card-head"><span class="ia-card-title">Account</span></div>
  <p style="font-size:12px;color:var(--ia-text-dim);margin:0 0 14px">Update your name. Email and role are managed by an owner — ask them if you need a change.</p>

  <form method="POST" action="{{ route('tenant.account.name') }}">
    @csrf @method('PATCH')
    <div class="ac-field">
      <div class="ac-field-label">Name</div>
      <div class="ac-field-value"><input class="ia-input" name="name" value="{{ $me->name }}" style="min-width:280px" required></div>
    </div>
    <div class="ac-field">
      <div class="ac-field-label">Email</div>
      <div class="ac-field-value">
        <span style="font-family:var(--ia-font-mono);font-size:12.5px">{{ $me->email }}</span>
        <span class="ac-field-hint">Ask an owner to change this.</span>
      </div>
    </div>
    <div class="ac-field">
      <div class="ac-field-label">Role</div>
      <div class="ac-field-value"><span class="ia-badge">{{ ucfirst($me->role) }}</span></div>
    </div>
    <div style="display:flex;justify-content:flex-end;margin-top:8px">
      <button class="ia-btn ia-btn--primary ia-btn--sm">Save</button>
    </div>
  </form>
</div>

{{-- Password --}}
<div class="ia-card" style="margin-bottom:14px">
  <div class="ia-card-head"><span class="ia-card-title">Password</span></div>
  <p style="font-size:12px;color:var(--ia-text-dim);margin:0 0 14px">Used for signing in and confirming sensitive changes.</p>

  <form method="POST" action="{{ route('tenant.account.password') }}">
    @csrf @method('PATCH')
    <div class="ac-field">
      <div class="ac-field-label">Current password</div>
      <div class="ac-field-value"><input class="ia-input" type="password" name="current_password" placeholder="••••••••" style="min-width:280px" required></div>
    </div>
    <div class="ac-field">
      <div class="ac-field-label">New password</div>
      <div class="ac-field-value"><input class="ia-input" type="password" name="new_password" placeholder="At least 10 characters" style="min-width:280px" required minlength="10"></div>
    </div>
    <div class="ac-field">
      <div class="ac-field-label">Confirm new password</div>
      <div class="ac-field-value"><input class="ia-input" type="password" name="new_password_confirmation" placeholder="Match above" style="min-width:280px" required></div>
    </div>
    <div style="display:flex;justify-content:flex-end;margin-top:8px">
      <button class="ia-btn ia-btn--primary ia-btn--sm">Update password</button>
    </div>
  </form>
</div>

{{-- PIN --}}
@if($pinModeOn)
<div class="ia-card" style="margin-bottom:14px">
  <div class="ia-card-head"><span class="ia-card-title">PIN</span></div>
  <p style="font-size:12px;color:var(--ia-text-dim);margin:0 0 14px">A 4-digit PIN unlocks the screen after idle timeout. Required while this shop has PIN mode on.</p>

  @if(! $me->pin_hash)
    <div class="ia-notice ia-notice--warning" style="margin-bottom:12px">
      <strong>You haven't set a PIN yet.</strong> You'll be prompted to set one the next time you're idle. You can also set it here now.
    </div>
  @endif

  <form method="POST" action="{{ route('tenant.account.pin') }}">
    @csrf @method('PATCH')
    <div class="ac-field">
      <div class="ac-field-label">{{ $me->pin_hash ? 'New PIN' : 'Set a PIN' }}</div>
      <div class="ac-field-value"><input class="ia-input" name="pin" maxlength="4" pattern="\d{4}" placeholder="4 digits" style="width:120px;font-family:var(--ia-font-mono);font-size:18px;letter-spacing:6px;text-align:center" required></div>
    </div>
    <div class="ac-field">
      <div class="ac-field-label">Confirm PIN</div>
      <div class="ac-field-value"><input class="ia-input" name="pin_confirm" maxlength="4" pattern="\d{4}" placeholder="4 digits" style="width:120px;font-family:var(--ia-font-mono);font-size:18px;letter-spacing:6px;text-align:center" required></div>
    </div>
    <div class="ac-field">
      <div class="ac-field-label">Your password</div>
      <div class="ac-field-value">
        <input class="ia-input" type="password" name="current_password" placeholder="••••••••" style="min-width:280px" required>
        <span class="ac-field-hint">Re-enter to confirm.</span>
      </div>
    </div>
    <div style="display:flex;justify-content:flex-end;margin-top:8px;gap:8px">
      @if($me->pin_hash)
        <button type="submit" formaction="{{ route('tenant.account.pin.clear') }}"
                class="ia-btn ia-btn--ghost ia-btn--sm"
                data-confirm="Clear your PIN? You'll be asked to set a new one.">Clear PIN</button>
      @endif
      <button class="ia-btn ia-btn--primary ia-btn--sm">Save PIN</button>
    </div>
  </form>
</div>
@endif

{{-- Your devices --}}
<div class="ia-card" style="margin-bottom:14px">
  <div class="ia-card-head"><span class="ia-card-title">Your devices</span></div>
  <p style="font-size:12px;color:var(--ia-text-dim);margin:0 0 14px">Browsers you've trusted. Revoke any you don't recognise.</p>

  @if($devices->isEmpty())
    <div class="ac-empty">No trusted devices.</div>
  @else
    @foreach($devices as $d)
      <div class="ac-device">
        <div>
          <div class="ac-device-label">{{ $d->label ?: 'Unnamed device' }}</div>
          <div class="ac-device-meta">
            Last used {{ $d->last_used_at?->diffForHumans() ?? '—' }}
            · IP {{ $d->ip_last_seen ?? '—' }}
            @if($d->expires_at) · Expires {{ $d->expires_at->diffForHumans() }} @endif
          </div>
        </div>
        <form method="POST" action="{{ route('tenant.account.device.revoke', $d->id) }}">
          @csrf
          <button class="ia-btn ia-btn--ghost ia-btn--sm" style="color:#F87171"
                  data-confirm="Revoke this device?">Revoke</button>
        </form>
      </div>
    @endforeach
  @endif
</div>

@endsection
