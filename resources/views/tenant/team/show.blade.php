{{-- MARKER-PATCH-129 — person detail --}}
@extends('layouts.tenant.app')
@php
  $pageTitle = $member->name;
  $me        = Auth::guard('tenant')->user();
  $pinModeOn = $currentTenant->pin_tier_active;
  $pinLocked = $member->pin_locked_until && $member->pin_locked_until->isFuture();
@endphp

@push('styles')
<style>
.pd-head { display:flex; gap:16px; align-items:center; padding-bottom:18px; border-bottom:0.5px solid var(--ia-border); margin-bottom:20px; }
.pd-avatar { width:56px; height:56px; border-radius:50%; background:var(--ia-accent); color:var(--ia-accent-text); display:inline-flex; align-items:center; justify-content:center; font-size:18px; font-weight:600; flex-shrink:0; }
.pd-h2 { font-size:20px; font-weight:600; margin:0; }
.pd-sub { font-size:12px; color:var(--ia-text-dim); margin-top:3px; }
.pd-actions { margin-left:auto; display:flex; gap:6px; }
.pd-field { display:grid; grid-template-columns:180px 1fr; gap:16px; padding:12px 0; align-items:center; border-top:0.5px solid var(--ia-border); }
.pd-field:first-of-type { border-top:none; padding-top:2px; }
.pd-field-label { font-size:12px; color:var(--ia-text-dim); }
.pd-field-value { font-size:13px; display:flex; align-items:center; gap:10px; flex-wrap:wrap; }
.pd-field-hint { font-size:11px; color:var(--ia-text-dim); }
.pd-device { display:grid; grid-template-columns:1fr auto; gap:14px; padding:12px 0; border-top:0.5px solid var(--ia-border); align-items:center; }
.pd-device:first-of-type { border-top:none; padding-top:2px; }
.pd-device-label { font-size:13px; font-weight:500; }
.pd-device-meta { font-size:11px; color:var(--ia-text-dim); margin-top:3px; }
.pd-loc-grid { display:grid; grid-template-columns:repeat(auto-fit,minmax(220px,1fr)); gap:8px; }
.pd-loc { display:flex; align-items:center; gap:8px; font-size:13px; padding:8px 10px; border:0.5px solid var(--ia-border); border-radius:var(--ia-r-md); }
.pd-empty { padding:24px; text-align:center; border:0.5px dashed var(--ia-border-strong); border-radius:var(--ia-r-md); font-size:12px; color:var(--ia-text-dim); }
.pd-back { font-size:12px; color:var(--ia-text-dim); display:inline-flex; align-items:center; gap:4px; margin-bottom:12px; text-decoration:none; }
.pd-back:hover { color:var(--ia-text-muted); }
</style>
@endpush

@section('content')
<a href="{{ route('tenant.team.index') }}" class="pd-back">← All team members</a>

<div class="pd-head">
  <div class="pd-avatar">{{ strtoupper(substr($member->name, 0, 2)) }}</div>
  <div>
    <h2 class="pd-h2">{{ $member->name }}</h2>
    <div class="pd-sub">
      {{ ucfirst($member->role) }} ·
      @if($member->is_active) Active @else Inactive @endif ·
      last seen {{ $member->last_login_at?->diffForHumans() ?? 'never' }}
    </div>
  </div>
  {{-- MARKER-TEAM-CONTACT — same tiles as the customer page, so there is one
       contact affordance in the product rather than two that drift. A tile
       with nothing behind it is disabled, not merely dead. --}}
  @php
    $tmPhoneDigits = $member->phone ? preg_replace('/[^0-9+]/', '', $member->phone) : '';
  @endphp
  <div class="pd-contact-tiles">
    <a href="{{ $tmPhoneDigits ? 'tel:' . $tmPhoneDigits : '#' }}"
       class="pd-tile {{ $tmPhoneDigits ? '' : 'is-disabled' }}"
       @if(!$tmPhoneDigits) aria-disabled="true" tabindex="-1" @endif
       title="{{ $tmPhoneDigits ? 'Call ' . $member->name : 'No phone number on file' }}">
      <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
        <path d="M22 16.92v3a2 2 0 0 1-2.18 2 19.79 19.79 0 0 1-8.63-3.07 19.5 19.5 0 0 1-6-6 19.79 19.79 0 0 1-3.07-8.67A2 2 0 0 1 4.11 2h3a2 2 0 0 1 2 1.72c.13.96.36 1.9.7 2.81a2 2 0 0 1-.45 2.11L8.09 9.91a16 16 0 0 0 6 6l1.27-1.27a2 2 0 0 1 2.11-.45c.9.34 1.85.57 2.81.7A2 2 0 0 1 22 16.92z"/>
      </svg>
      <span class="pd-tile-label">Call</span>
    </a>
    <a href="{{ $tmPhoneDigits ? 'sms:' . $tmPhoneDigits : '#' }}"
       class="pd-tile {{ $tmPhoneDigits ? '' : 'is-disabled' }}"
       @if(!$tmPhoneDigits) aria-disabled="true" tabindex="-1" @endif
       title="{{ $tmPhoneDigits ? 'Text ' . $member->name : 'No phone number on file' }}">
      <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
        <path d="M21 11.5a8.38 8.38 0 0 1-.9 3.8 8.5 8.5 0 0 1-7.6 4.7 8.38 8.38 0 0 1-3.8-.9L3 21l1.9-5.7a8.38 8.38 0 0 1-.9-3.8 8.5 8.5 0 0 1 4.7-7.6 8.38 8.38 0 0 1 3.8-.9h.5a8.48 8.48 0 0 1 8 8v.5z"/>
      </svg>
      <span class="pd-tile-label">Text</span>
    </a>
    <a href="{{ $member->email ? 'mailto:' . $member->email : '#' }}"
       class="pd-tile {{ $member->email ? '' : 'is-disabled' }}"
       @if(!$member->email) aria-disabled="true" tabindex="-1" @endif
       title="{{ $member->email ? 'Email ' . $member->name : 'No email on file' }}">
      <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
        <path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"/>
        <polyline points="22,6 12,13 2,6"/>
      </svg>
      <span class="pd-tile-label">Email</span>
    </a>
  </div>

  <div class="pd-actions">
    <form method="POST" action="{{ route('tenant.team.update', $member->id) }}" style="display:inline">
      @csrf @method('PATCH')
      <input type="hidden" name="op" value="toggle_active">
      <button class="ia-btn ia-btn--ghost ia-btn--sm"
              data-confirm="{{ $member->is_active ? 'Deactivate' : 'Reactivate' }} {{ $member->name }}?">
        {{ $member->is_active ? 'Deactivate' : 'Reactivate' }}
      </button>
    </form>
    <form method="POST" action="{{ route('tenant.team.destroy', $member->id) }}" style="display:inline">
      @csrf @method('DELETE')
      <button class="ia-btn ia-btn--ghost ia-btn--sm" style="color:#F87171"
              data-confirm="Remove {{ $member->name }} from the team?">Remove</button>
    </form>
  </div>
</div>

{{-- Account --}}
<div class="ia-card" style="margin-bottom:14px">
  <div class="ia-card-head"><span class="ia-card-title">Account</span></div>
  <p style="font-size:12px;color:var(--ia-text-dim);margin:0 0 14px">Basic identity. The user cannot change their own email or role.</p>

  <form method="POST" action="{{ route('tenant.team.update', $member->id) }}">
    @csrf @method('PATCH')
    <input type="hidden" name="op" value="update_account">
    <div class="pd-field">
      <div class="pd-field-label">Name</div>
      <div class="pd-field-value"><input class="ia-input" name="name" value="{{ $member->name }}" style="min-width:280px"></div>
    </div>
    <div class="pd-field">
      <div class="pd-field-label">Email</div>
      <div class="pd-field-value"><input class="ia-input" type="email" name="email" value="{{ $member->email }}" style="min-width:320px"></div>
    </div>
    <div style="display:flex;justify-content:flex-end;margin-top:8px">
      <button class="ia-btn ia-btn--primary ia-btn--sm">Save</button>
    </div>
  </form>

  <form method="POST" action="{{ route('tenant.team.update', $member->id) }}">
    @csrf @method('PATCH')
    <input type="hidden" name="op" value="change_role">
    <div class="pd-field">
      <div class="pd-field-label">Role</div>
      <div class="pd-field-value">
        {{-- MARKER-PATCH-494 — named roles --}}
        <select name="role_id" class="ia-input" style="width:auto">
          @foreach($allRoles as $r)
            <option value="{{ $r->id }}" @selected($member->role_id === $r->id)>{{ $r->name }}</option>
          @endforeach
        </select>
        <button class="ia-btn ia-btn--ghost ia-btn--sm">Change role</button>
      </div>
    </div>
  </form>

  {{-- MARKER-TIMECLOCK-EXEMPT — owners/salaried staff opt-out of the clock-in nudge --}}
  <form method="POST" action="{{ route('tenant.team.update', $member->id) }}" style="margin-top:12px">
    @csrf @method('PATCH')
    <input type="hidden" name="op" value="toggle_timeclock_exempt">
    <div class="pd-field">
      <div class="pd-field-label">Time clock</div>
      <div class="pd-field-value" style="display:flex;align-items:center;gap:10px">
        <span style="font-size:12.5px;color:var(--ia-text-dim)">
          {{ $member->exempt_from_timeclock ? 'Never clocks in — no clock-in prompts.' : 'Clocks in — sees the clock-in prompt when off the clock.' }}
        </span>
        <button class="ia-btn ia-btn--ghost ia-btn--sm">
          {{ $member->exempt_from_timeclock ? 'Require clock-in' : 'Mark as never clocks in' }}
        </button>
      </div>
    </div>
  </form>
</div>

{{-- Credentials --}}
<div class="ia-card" style="margin-bottom:14px">
  <div class="ia-card-head"><span class="ia-card-title">Credentials</span></div>
  <p style="font-size:12px;color:var(--ia-text-dim);margin:0 0 14px">Reset their password or PIN. The user will be required to set new credentials on next sign-in.</p>

  @if($pinLocked)
    <div class="ia-notice ia-notice--error" style="margin-bottom:12px">
      <strong>PIN is locked.</strong> Too many wrong attempts. Cooldown ends
      {{ $member->pin_locked_until->diffForHumans() }}.
    </div>
  @endif

  <form method="POST" action="{{ route('tenant.team.update', $member->id) }}">
    @csrf @method('PATCH')
    <input type="hidden" name="op" value="reset_password">
    <div class="pd-field">
      <div class="pd-field-label">Password</div>
      <div class="pd-field-value">
        <button class="ia-btn ia-btn--ghost ia-btn--sm"
                data-confirm="Reset password for {{ $member->name }}?">Reset password</button>
        <span class="pd-field-hint">Generates a temporary password. They'll change it on next sign-in.</span>
      </div>
    </div>
  </form>

  @if($pinModeOn)
  <div class="pd-field">
    <div class="pd-field-label">PIN</div>
    <div class="pd-field-value">
      @if($pinLocked)
        <span class="ia-badge ia-badge--cancelled">Locked</span>
        <form method="POST" action="{{ route('tenant.team.update', $member->id) }}" style="display:inline">
          @csrf @method('PATCH')
          <input type="hidden" name="op" value="pin_unlock">
          <button class="ia-btn ia-btn--ghost ia-btn--sm"
                  data-confirm="Unlock {{ $member->name }}'s PIN?">Unlock</button>
        </form>
      @elseif($member->pin_hash)
        <span class="ia-badge ia-badge--completed">Set</span>
      @else
        <span class="ia-badge ia-badge--pending">Not set</span>
      @endif
      @if($member->pin_hash)
        <form method="POST" action="{{ route('tenant.team.update', $member->id) }}" style="display:inline">
          @csrf @method('PATCH')
          <input type="hidden" name="op" value="pin_force_reset">
          <button class="ia-btn ia-btn--ghost ia-btn--sm"
                  data-confirm="Force {{ $member->name }} to set a new PIN?">Force reset</button>
        </form>
      @endif
    </div>
  </div>
  @endif

  {{-- MARKER-PATCH-130 — per-user sign-out-everywhere removed (devices are tenant-scoped) --}}
</div>

{{-- Locations --}}
<div class="ia-card" style="margin-bottom:14px">
  <div class="ia-card-head"><span class="ia-card-title">Locations</span></div>
  <p style="font-size:12px;color:var(--ia-text-dim);margin:0 0 14px">Which locations this person has access to.</p>

  <form method="POST" action="{{ route('tenant.team.update', $member->id) }}">
    @csrf @method('PATCH')
    <input type="hidden" name="op" value="update_locations">
    <div class="pd-loc-grid">
      @foreach($allLocations as $loc)
        <label class="pd-loc">
          <input type="checkbox" name="location_ids[]" value="{{ $loc->id }}"
                 @checked(in_array($loc->id, $memberLocationIds))>
          <span>{{ $loc->name }}@if($loc->is_default) <span style="font-size:11px;color:var(--ia-text-dim)">(default)</span>@endif</span>
        </label>
      @endforeach
    </div>
    <div style="display:flex;justify-content:flex-end;margin-top:14px">
      <button class="ia-btn ia-btn--primary ia-btn--sm">Save locations</button>
    </div>
  </form>
</div>

{{-- MARKER-PATCH-130 — per-user devices card removed (devices are tenant-scoped; see /admin/team/devices for full list) --}}
@endsection
