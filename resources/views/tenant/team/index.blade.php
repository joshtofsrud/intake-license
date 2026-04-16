@extends('layouts.tenant.app')
@php
  $pageTitle = 'Team';
  $me        = Auth::guard('tenant')->user();
  $roleBadge = ['owner' => 'ia-badge--completed', 'manager' => 'ia-badge--confirmed', 'staff' => 'ia-badge--pending'];
@endphp

@push('styles')
<style>
.team-invite{background:var(--ia-surface);border:0.5px solid var(--ia-border);border-radius:var(--ia-r-lg);padding:20px 24px;margin-bottom:24px;display:none}
.team-invite.open{display:block}
</style>
@endpush

@section('content')

<div class="ia-page-head">
  <div class="ia-page-head-left">
    <h1 class="ia-page-title">Team</h1>
    <p class="ia-page-subtitle">{{ $members->count() }} {{ Str::plural('member', $members->count()) }}</p>
  </div>
  <div class="ia-page-actions">
    @if($me->isManager())
      <button type="button" class="ia-btn ia-btn--primary" id="invite-toggle">
        + Invite member
      </button>
    @endif
  </div>
</div>

{{-- Invite form --}}
@if($me->isManager())
<div class="team-invite" id="invite-card">
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

{{-- Role descriptions --}}
<div class="ia-card ia-card--tight" style="margin-bottom:20px;font-size:12px;opacity:.6">
  <strong>Owner</strong> — full access including billing and account deletion &nbsp;·&nbsp;
  <strong>Manager</strong> — full access except billing &nbsp;·&nbsp;
  <strong>Staff</strong> — appointments and customers only
</div>

{{-- Team table --}}
<div class="ia-table-wrap">
  <table class="ia-table">
    <thead>
      <tr>
        <th>Name</th>
        <th>Email</th>
        <th>Role</th>
        <th>Status</th>
        @if($me->isManager()) <th></th> @endif
      </tr>
    </thead>
    <tbody>
      @foreach($members as $member)
      <tr>
        <td>
          <div style="font-weight:500;display:flex;align-items:center;gap:8px">
            <div style="width:28px;height:28px;border-radius:50%;background:var(--ia-accent);display:flex;align-items:center;justify-content:center;font-size:11px;font-weight:600;color:var(--ia-accent-text);flex-shrink:0">
              {{ strtoupper(substr($member->name, 0, 2)) }}
            </div>
            {{ $member->name }}
            @if($member->id === $me->id)
              <span style="font-size:11px;opacity:.4">(you)</span>
            @endif
          </div>
        </td>
        <td class="ia-muted-cell">{{ $member->email }}</td>
        <td>
          <span class="ia-badge {{ $roleBadge[$member->role] ?? '' }}">{{ ucfirst($member->role) }}</span>
        </td>
        <td>
          @if($member->is_active)
            <span class="ia-badge ia-badge--completed">Active</span>
          @else
            <span class="ia-badge ia-badge--cancelled">Inactive</span>
          @endif
        </td>

        @if($me->isManager())
        <td>
          @if($member->id !== $me->id)
            <div style="display:flex;gap:6px;justify-content:flex-end">

              {{-- Change role --}}
              <form method="POST" action="{{ route('tenant.team.update', $member->id) }}" style="display:flex;gap:4px;align-items:center">
                @csrf @method('PATCH')
                <input type="hidden" name="op" value="change_role">
                <select name="role" class="ia-input" style="width:auto;padding:4px 8px;font-size:12px" onchange="this.form.submit()">
                  @foreach(['owner','manager','staff'] as $r)
                    <option value="{{ $r }}" @selected($member->role === $r)>{{ ucfirst($r) }}</option>
                  @endforeach
                </select>
              </form>

              {{-- Reset password --}}
              <form method="POST" action="{{ route('tenant.team.update', $member->id) }}">
                @csrf @method('PATCH')
                <input type="hidden" name="op" value="reset_password">
                <button type="submit" class="ia-btn ia-btn--ghost ia-btn--sm"
                  data-confirm="Reset password for {{ $member->name }}?">
                  Reset pw
                </button>
              </form>

              {{-- Toggle active --}}
              <form method="POST" action="{{ route('tenant.team.update', $member->id) }}">
                @csrf @method('PATCH')
                <input type="hidden" name="op" value="toggle_active">
                <button type="submit" class="ia-btn ia-btn--ghost ia-btn--sm"
                  data-confirm="{{ $member->is_active ? 'Deactivate' : 'Reactivate' }} {{ $member->name }}?">
                  {{ $member->is_active ? 'Deactivate' : 'Reactivate' }}
                </button>
              </form>

              {{-- Remove --}}
              <form method="POST" action="{{ route('tenant.team.destroy', $member->id) }}">
                @csrf @method('DELETE')
                <button type="submit" class="ia-btn ia-btn--danger ia-btn--sm"
                  data-confirm="Remove {{ $member->name }} from the team?">
                  Remove
                </button>
              </form>

            </div>
          @endif
        </td>
        @endif
      </tr>
      @endforeach
    </tbody>
  </table>
</div>

@endsection

@push('scripts')
<script>
var toggle  = document.getElementById('invite-toggle');
var card    = document.getElementById('invite-card');
var cancel  = document.getElementById('invite-cancel');
if (toggle) toggle.addEventListener('click', function() { card.classList.add('open'); toggle.style.display='none'; });
if (cancel) cancel.addEventListener('click', function() { card.classList.remove('open'); toggle.style.display=''; });
@if(session('success') && str_contains(session('success'), 'password'))
card && card.classList.add('open');
@endif
</script>
@endpush
