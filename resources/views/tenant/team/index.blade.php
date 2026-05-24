{{-- MARKER-PATCH-129 — team list (replaces old team/index.blade.php) --}}
@extends('layouts.tenant.app')
@php
  $pageTitle = 'Team';
  $me        = Auth::guard('tenant')->user();
  $pinModeOn = $currentTenant->pin_tier_active;
@endphp

@push('styles')
<style>
.tm-invite { background:var(--ia-surface); border:0.5px solid var(--ia-border); border-radius:var(--ia-r-lg); padding:20px 24px; margin-bottom:24px; display:none; }
.tm-invite.open { display:block; }
.tm-name { font-weight:500; font-size:13px; }
.tm-name-sub { font-size:11px; color:var(--ia-text-dim); margin-top:1px; }
.tm-me-tag { display:inline-block; margin-left:6px; font-size:9.5px; font-weight:500; padding:1px 6px; border-radius:8px; background:var(--ia-accent); color:var(--ia-accent-text); }
.tm-avatar { width:30px; height:30px; border-radius:50%; background:var(--ia-accent); color:var(--ia-accent-text); display:inline-flex; align-items:center; justify-content:center; font-size:11px; font-weight:600; flex-shrink:0; }
.tm-row-clickable { cursor:pointer; }
.tm-row-clickable:hover td { background:rgba(255,255,255,.025); }
.tm-row-me td { background:var(--ia-accent-soft); }
</style>
@endpush

@section('content')
<div class="ia-page-head">
  <div class="ia-page-head-left">
    <h1 class="ia-page-title">Team</h1>
    <p class="ia-page-subtitle">{{ $members->count() }} {{ Str::plural('member', $members->count()) }}
      @if($pinModeOn) · PIN mode on @endif
    </p>
  </div>
  <div class="ia-page-actions">
    @if($me->isOwner())
      <a href="{{ route('tenant.team.devices') }}" class="ia-btn ia-btn--ghost">All devices</a>
      <a href="{{ route('tenant.team.policy') }}" class="ia-btn ia-btn--ghost">Sign-in policy</a>
    @endif
    @if($me->isManager())
      <button type="button" class="ia-btn ia-btn--primary" id="invite-toggle">+ Invite member</button>
    @endif
  </div>
</div>

@if($me->isManager())
<div class="tm-invite" id="invite-card">
  <div style="font-size:13px;font-weight:500;margin-bottom:16px">Invite a team member</div>
  <form method="POST" action="{{ route('tenant.team.store') }}">
    @csrf
    <div class="ia-input-grid-3">
      <div class="ia-form-group">
        <label class="ia-form-label">Name <span class="ia-required">*</span></label>
        <input type="text" name="name" class="ia-input" value="{{ old('name') }}" required>
      </div>
      <div class="ia-form-group">
        <label class="ia-form-label">Email <span class="ia-required">*</span></label>
        <input type="email" name="email" class="ia-input" value="{{ old('email') }}" required>
      </div>
      <div class="ia-form-group">
        <label class="ia-form-label">Role <span class="ia-required">*</span></label>
        <select name="role" class="ia-input">
          <option value="staff"   @selected(old('role') === 'staff')>Staff</option>
          <option value="manager" @selected(old('role') === 'manager')>Manager</option>
        </select>
      </div>
    </div>
    <div style="display:flex;gap:8px;margin-top:4px">
      <button type="submit" class="ia-btn ia-btn--primary ia-btn--sm">Send invite</button>
      <button type="button" class="ia-btn ia-btn--ghost ia-btn--sm" id="invite-cancel">Cancel</button>
    </div>
  </form>
</div>
@endif

<div class="ia-card ia-card--tight" style="margin-bottom:20px;font-size:12px;color:var(--ia-text-dim)">
  <strong style="color:var(--ia-text)">Owner</strong> — full access including billing &nbsp;·&nbsp;
  <strong style="color:var(--ia-text)">Manager</strong> — full access except billing &nbsp;·&nbsp;
  <strong style="color:var(--ia-text)">Staff</strong> — appointments and customers only
</div>

<div class="ia-table-wrap">
  <table class="ia-table">
    <thead>
      <tr>
        <th>Name</th>
        <th>Role</th>
        <th>Status</th>
        @if($pinModeOn)<th>PIN</th>@endif
        {{-- MARKER-PATCH-130 — devices + last-seen columns removed --}}
        <th></th>
      </tr>
    </thead>
    <tbody>
      @foreach($members as $member)
      @php $isMe = $member->id === $me->id; @endphp
      <tr class="tm-row-clickable @if($isMe) tm-row-me @endif"
          onclick="window.location.href='{{ $isMe ? route('tenant.account.index') : route('tenant.team.show', $member->id) }}'">
        <td>
          <div style="display:flex;align-items:center;gap:10px">
            <div class="tm-avatar">{{ strtoupper(substr($member->name, 0, 2)) }}</div>
            <div>
              <div class="tm-name">{{ $member->name }}@if($isMe)<span class="tm-me-tag">you</span>@endif</div>
              <div class="tm-name-sub">{{ $member->email }}</div>
            </div>
          </div>
        </td>
        <td><span class="ia-badge">{{ ucfirst($member->role) }}</span></td>
        <td>
          @if($member->is_active)
            <span class="ia-badge ia-badge--completed">Active</span>
          @else
            <span class="ia-badge ia-badge--cancelled">Inactive</span>
          @endif
        </td>
        @if($pinModeOn)
        <td>
          @php
            $pinLocked = $member->pin_locked_until && $member->pin_locked_until->isFuture();
            $hasPin    = (bool) $member->pin_hash;
          @endphp
          @if($pinLocked)
            <span class="ia-badge ia-badge--cancelled">Locked</span>
          @elseif($hasPin)
            <span class="ia-badge ia-badge--completed">Set</span>
          @else
            <span class="ia-badge ia-badge--pending">Not set</span>
          @endif
        </td>
        @endif
        {{-- MARKER-PATCH-130 --}}
        <td style="text-align:right;color:var(--ia-text-dim);font-family:var(--ia-font-mono);font-size:14px">›</td>
      </tr>
      @endforeach
    </tbody>
  </table>
</div>
@endsection

@push('scripts')
<script>
  var t = document.getElementById('invite-toggle');
  var c = document.getElementById('invite-card');
  var x = document.getElementById('invite-cancel');
  if (t) t.addEventListener('click', function(){ c.classList.add('open'); t.style.display='none'; });
  if (x) x.addEventListener('click', function(){ c.classList.remove('open'); t.style.display=''; });
  @if(session('success') && str_contains(session('success'), 'password'))
    c && c.classList.add('open');
  @endif
</script>
@endpush
