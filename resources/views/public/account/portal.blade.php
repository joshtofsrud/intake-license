@extends('public.account._shell')
@php $pageTitle = 'My Account'; @endphp

@push('styles')
<style>
.ac-section-title{font-size:13px;font-weight:600;text-transform:uppercase;letter-spacing:.07em;opacity:.5;margin-bottom:12px}
.ac-portal-grid{display:grid;grid-template-columns:1fr 1fr;gap:12px;margin-bottom:28px}
.ac-stat{background:var(--p-surface);border-radius:var(--p-r);padding:14px 16px}
.ac-stat-label{font-size:12px;opacity:.55;margin-bottom:3px}
.ac-stat-value{font-size:20px;font-weight:600}
.ac-stat-sub{font-size:12px;opacity:.45;margin-top:2px}
.ac-list{border:1px solid var(--p-border);border-radius:var(--p-r-lg);overflow:hidden;margin-bottom:28px}
.ac-list-head{padding:11px 16px;background:var(--p-surface);font-size:11px;font-weight:600;text-transform:uppercase;letter-spacing:.07em;opacity:.5;border-bottom:1px solid var(--p-border)}
.ac-list-row{padding:13px 16px;border-bottom:1px solid var(--p-border);display:flex;align-items:center;justify-content:space-between;font-size:14px}
.ac-list-row:last-child{border-bottom:none}
.ac-list-name{font-weight:500}
.ac-list-meta{font-size:12px;opacity:.55;margin-top:2px}
.ac-list-right{text-align:right}
.ac-pill{display:inline-flex;padding:2px 8px;border-radius:20px;font-size:11px;font-weight:500}
.ac-pill--registered{background:#EAF3DE;color:#3B6D11}
.ac-pill--waitlisted{background:#FAEEDA;color:#633806}
.ac-pill--pending{background:#E6F1FB;color:#185FA5}
.ac-pill--confirmed{background:#EAF3DE;color:#3B6D11}
.ac-pill--in_progress{background:#FAEEDA;color:#633806}
.ac-empty{padding:32px;text-align:center;font-size:14px;opacity:.45}
.ac-membership-card{background:var(--p-accent);color:var(--p-accent-text);border-radius:var(--p-r-lg);padding:20px;margin-bottom:28px}
.ac-membership-label{font-size:12px;opacity:.7;margin-bottom:4px}
.ac-membership-name{font-size:18px;font-weight:700;margin-bottom:2px}
.ac-membership-detail{font-size:13px;opacity:.75}
.ac-pack-row{display:flex;align-items:center;justify-content:space-between;padding:12px 16px;border-bottom:1px solid var(--p-border)}
.ac-pack-row:last-child{border-bottom:none}
.ac-credits-bar{height:4px;background:var(--p-border);border-radius:2px;overflow:hidden;margin-top:4px;width:120px}
.ac-credits-fill{height:100%;background:var(--p-accent);border-radius:2px}
</style>
@endpush

@section('content')

<div style="margin-bottom:24px">
  <div class="ac-title">Hi, {{ $customer->first_name }}</div>
  <div class="ac-subtitle">{{ $customer->email }}</div>
</div>

{{-- Membership --}}
@if($activeMembership)
  <div class="ac-membership-card">
    <div class="ac-membership-label">Active membership</div>
    <div class="ac-membership-name">{{ $activeMembership->product->name }}</div>
    <div class="ac-membership-detail">
      @if($activeMembership->product->isUnlimited())
        Unlimited classes · renews {{ $activeMembership->current_period_end->format('M j') }}
      @else
        {{ $activeMembership->classes_used_this_period }} of {{ $activeMembership->product->monthly_limit }} classes used this month
      @endif
    </div>
  </div>
@endif

{{-- Class packs --}}
@if($activePacks->isNotEmpty())
  <div class="ac-section-title">Class credits</div>
  <div class="ac-list" style="margin-bottom:28px">
    @foreach($activePacks as $pack)
      <div class="ac-pack-row">
        <div>
          <div style="font-weight:500;font-size:14px">{{ $pack->product->name }}</div>
          <div style="font-size:12px;opacity:.5;margin-top:1px">Expires {{ $pack->expires_at->format('M j, Y') }}</div>
          <div class="ac-credits-bar">
            <div class="ac-credits-fill" style="width:{{ round($pack->credits_remaining / $pack->credits_total * 100) }}%"></div>
          </div>
        </div>
        <div style="text-align:right">
          <div style="font-size:18px;font-weight:600">{{ $pack->credits_remaining }}</div>
          <div style="font-size:12px;opacity:.5">of {{ $pack->credits_total }}</div>
        </div>
      </div>
    @endforeach
  </div>
@endif

{{-- Quick stats --}}
<div class="ac-portal-grid">
  <div class="ac-stat">
    <div class="ac-stat-label">Upcoming classes</div>
    <div class="ac-stat-value">{{ $upcomingClasses->count() }}</div>
  </div>
  <div class="ac-stat">
    <div class="ac-stat-label">Upcoming appointments</div>
    <div class="ac-stat-value">{{ $upcomingAppointments->count() }}</div>
  </div>
</div>

{{-- Upcoming classes --}}
<div class="ac-section-title">Upcoming classes</div>
<div class="ac-list">
  @forelse($upcomingClasses as $reg)
    <div class="ac-list-row">
      <div>
        <div class="ac-list-name">{{ $reg->session->template->name }}</div>
        <div class="ac-list-meta">
          {{ $reg->session->starts_at->format('D, M j · g:i A') }}
          @if($reg->session->instructorResource)
            · {{ $reg->session->instructorResource->name }}
          @endif
        </div>
      </div>
      <div class="ac-list-right">
        <span class="ac-pill ac-pill--{{ $reg->status }}">{{ ucfirst($reg->status) }}</span>
        @if($reg->status === 'waitlisted')
          <div style="font-size:11px;opacity:.5;margin-top:3px">#{{ $reg->waitlist_position }} in queue</div>
        @endif
      </div>
    </div>
  @empty
    <div class="ac-empty">No upcoming classes</div>
  @endforelse
</div>

{{-- Upcoming appointments --}}
<div class="ac-section-title">Upcoming appointments</div>
<div class="ac-list">
  @forelse($upcomingAppointments as $appt)
    <div class="ac-list-row">
      <div>
        <div class="ac-list-name">{{ $appt->label ?? 'Appointment' }}</div>
        <div class="ac-list-meta">{{ \Carbon\Carbon::parse($appt->appointment_date)->format('D, M j · g:i A') }}</div>
      </div>
      <div class="ac-list-right">
        <span class="ac-pill ac-pill--{{ $appt->status }}">{{ ucfirst(str_replace('_',' ',$appt->status)) }}</span>
      </div>
    </div>
  @empty
    <div class="ac-empty">No upcoming appointments</div>
  @endforelse
</div>

{{-- Actions --}}
<div style="display:flex;gap:10px;margin-top:8px">
  @if($currentTenant->classes_enabled)
    <a href="{{ route('tenant.customer.classes') }}" class="ac-btn ac-btn--primary" style="flex:1;display:block;text-align:center;padding:12px;border-radius:var(--p-r);font-weight:600;font-size:14px">Browse classes</a>
  @endif
  <a href="{{ route('tenant.booking') }}" class="ac-btn ac-btn--ghost" style="flex:1;display:block;text-align:center;padding:12px;border-radius:var(--p-r);font-weight:600;font-size:14px;border:1.5px solid var(--p-border)">Book appointment</a>
</div>

@endsection
