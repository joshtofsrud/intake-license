@extends('public.account._shell')
@php $pageTitle = 'My Account'; @endphp

@push('styles')
<style>
.ac-tabs{display:flex;gap:2px;border-bottom:1px solid var(--p-border);margin-bottom:24px}
.ac-tab{padding:9px 16px;font-size:13px;color:var(--p-text);opacity:.45;border-bottom:2px solid transparent;margin-bottom:-1px;cursor:pointer;background:none;border-left:none;border-right:none;border-top:none;transition:all .15s}
.ac-tab:hover{opacity:.7}
.ac-tab.active{opacity:1;border-bottom-color:var(--p-accent);font-weight:500}
.ac-tab-panel{display:none}
.ac-tab-panel.active{display:block}
.ac-section-title{font-size:11px;font-weight:600;text-transform:uppercase;letter-spacing:.07em;opacity:.4;margin-bottom:10px}
.ac-portal-grid{display:grid;grid-template-columns:1fr 1fr;gap:10px;margin-bottom:24px}
.ac-stat{background:var(--p-surface);border-radius:var(--p-r);padding:13px 15px}
.ac-stat-label{font-size:12px;opacity:.5;margin-bottom:3px}
.ac-stat-value{font-size:20px;font-weight:600}
.ac-list{border:1px solid var(--p-border);border-radius:var(--p-r-lg);overflow:hidden;margin-bottom:20px}
.ac-list-head{padding:10px 15px;background:var(--p-surface);font-size:11px;font-weight:600;text-transform:uppercase;letter-spacing:.07em;opacity:.4;border-bottom:1px solid var(--p-border)}
.ac-list-row{padding:12px 15px;border-bottom:1px solid var(--p-border);display:flex;align-items:center;justify-content:space-between;font-size:14px}
.ac-list-row:last-child{border-bottom:none}
.ac-list-name{font-weight:500}
.ac-list-meta{font-size:12px;opacity:.5;margin-top:2px}
.ac-list-right{text-align:right;flex-shrink:0;margin-left:12px}
.ac-pill{display:inline-flex;padding:2px 8px;border-radius:20px;font-size:11px;font-weight:500}
.ac-pill--registered{background:#EAF3DE;color:#3B6D11}
.ac-pill--waitlisted{background:#FAEEDA;color:#633806}
.ac-pill--checked_in{background:#EAF3DE;color:#3B6D11}
.ac-pill--no_show{background:var(--p-surface);color:rgba(0,0,0,.4)}
.ac-pill--cancelled{background:var(--p-surface);color:rgba(0,0,0,.4)}
.ac-pill--pending{background:#E6F1FB;color:#185FA5}
.ac-pill--confirmed{background:#EAF3DE;color:#3B6D11}
.ac-pill--completed{background:var(--p-surface);color:rgba(0,0,0,.4)}
.ac-pill--in_progress{background:#FAEEDA;color:#633806}
.ac-empty{padding:28px;text-align:center;font-size:14px;opacity:.35}
.ac-membership-card{background:var(--p-accent);color:var(--p-accent-text);border-radius:var(--p-r-lg);padding:18px;margin-bottom:20px}
.ac-membership-name{font-size:17px;font-weight:700;margin-bottom:2px}
.ac-membership-detail{font-size:13px;opacity:.75}
.ac-pack-row{display:flex;align-items:center;justify-content:space-between;padding:11px 15px;border-bottom:1px solid var(--p-border)}
.ac-pack-row:last-child{border-bottom:none}
.ac-credits-bar{height:4px;background:var(--p-border);border-radius:2px;overflow:hidden;margin-top:4px;width:100px}
.ac-credits-fill{height:100%;background:var(--p-accent);border-radius:2px}
</style>
@endpush

@section('content')

<div style="margin-bottom:20px;display:flex;align-items:center;justify-content:space-between">
  <div>
    <div style="font-size:22px;font-weight:700;font-family:var(--p-font-heading)">Hi, {{ $customer->first_name }}</div>
    <div style="font-size:14px;opacity:.45;margin-top:2px">{{ $customer->email }}</div>
  </div>
</div>

{{-- Membership --}}
@if($activeMembership)
  <div class="ac-membership-card">
    <div style="font-size:11px;opacity:.7;margin-bottom:3px;text-transform:uppercase;letter-spacing:.06em">Active membership</div>
    <div class="ac-membership-name">{{ $activeMembership->product->name }}</div>
    <div class="ac-membership-detail">
      @if($activeMembership->product->isUnlimited())
        Unlimited classes · renews {{ $activeMembership->current_period_end->format('M j') }}
      @else
        {{ $activeMembership->classes_used_this_period }} of {{ $activeMembership->product->monthly_limit }} classes used · renews {{ $activeMembership->current_period_end->format('M j') }}
      @endif
    </div>
  </div>
@endif

{{-- Packs --}}
@if($activePacks->isNotEmpty())
  <div class="ac-section-title" style="margin-bottom:8px">Class credits</div>
  <div class="ac-list" style="margin-bottom:20px">
    @foreach($activePacks as $pack)
      <div class="ac-pack-row">
        <div style="flex:1">
          <div style="font-weight:500;font-size:14px">{{ $pack->product->name }}</div>
          <div style="font-size:12px;opacity:.45;margin-top:1px">Expires {{ $pack->expires_at->format('M j, Y') }}</div>
          <div class="ac-credits-bar"><div class="ac-credits-fill" style="width:{{ round($pack->credits_remaining / $pack->credits_total * 100) }}%"></div></div>
        </div>
        <div style="text-align:right;margin-left:16px">
          <div style="font-size:18px;font-weight:600">{{ $pack->credits_remaining }}</div>
          <div style="font-size:12px;opacity:.4">of {{ $pack->credits_total }}</div>
        </div>
      </div>
    @endforeach
  </div>
@endif

{{-- Tabs --}}
<div class="ac-tabs">
  <button class="ac-tab active" onclick="showTab('upcoming', this)">Upcoming</button>
  <button class="ac-tab" onclick="showTab('history', this)">History</button>
</div>

{{-- Upcoming tab --}}
<div class="ac-tab-panel active" id="tab-upcoming">
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

  <div class="ac-section-title">Classes</div>
  <div class="ac-list">
    @forelse($upcomingClasses as $reg)
      <div class="ac-list-row">
        <div>
          <div class="ac-list-name">{{ $reg->session->template->name }}</div>
          <div class="ac-list-meta">{{ tlocal($reg->session->starts_at, 'D, M j · g:i A') }}</div>
        </div>
        <div class="ac-list-right">
          <span class="ac-pill ac-pill--{{ $reg->status }}">{{ ucfirst($reg->status) }}</span>
          @if($reg->status === 'waitlisted')
            <div style="font-size:11px;opacity:.4;margin-top:3px">#{{ $reg->waitlist_position }} in queue</div>
          @endif
        </div>
      </div>
    @empty
      <div class="ac-empty">No upcoming classes</div>
    @endforelse
  </div>

  <div class="ac-section-title">Appointments</div>
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

  <div style="display:flex;gap:10px">
    @if($currentTenant->classes_enabled)
      <a href="{{ route('tenant.customer.classes') }}" class="ac-btn ac-btn--primary" style="flex:1;display:block;text-align:center;padding:12px;border-radius:var(--p-r);font-weight:600;font-size:14px">Browse classes</a>
    @endif
    <a href="{{ route('tenant.booking') }}" class="ac-btn ac-btn--ghost" style="flex:1;display:block;text-align:center;padding:12px;border-radius:var(--p-r);font-weight:600;font-size:14px;border:1.5px solid var(--p-border)">Book appointment</a>
  </div>
</div>

{{-- History tab --}}
<div class="ac-tab-panel" id="tab-history">
  <div class="ac-section-title">Past classes</div>
  <div class="ac-list">
    @forelse($pastClasses as $reg)
      <div class="ac-list-row">
        <div>
          <div class="ac-list-name">{{ $reg->session->template->name }}</div>
          <div class="ac-list-meta">{{ $reg->session->starts_at->format('D, M j, Y') }}</div>
        </div>
        <div class="ac-list-right">
          <span class="ac-pill ac-pill--{{ $reg->status }}">{{ ucfirst(str_replace('_',' ',$reg->status)) }}</span>
        </div>
      </div>
    @empty
      <div class="ac-empty">No past classes</div>
    @endforelse
  </div>

  <div class="ac-section-title">Past appointments</div>
  <div class="ac-list">
    @forelse($pastAppointments as $appt)
      <div class="ac-list-row">
        <div>
          <div class="ac-list-name">{{ $appt->label ?? 'Appointment' }}</div>
          <div class="ac-list-meta">{{ \Carbon\Carbon::parse($appt->appointment_date)->format('D, M j, Y') }}</div>
        </div>
        <div class="ac-list-right">
          <span class="ac-pill ac-pill--{{ $appt->status }}">{{ ucfirst(str_replace('_',' ',$appt->status)) }}</span>
        </div>
      </div>
    @empty
      <div class="ac-empty">No past appointments</div>
    @endforelse
  </div>
</div>

@endsection

@push('scripts')
<script>
function showTab(name, btn) {
  document.querySelectorAll('.ac-tab').forEach(function(b){ b.classList.remove('active'); });
  document.querySelectorAll('.ac-tab-panel').forEach(function(p){ p.classList.remove('active'); });
  btn.classList.add('active');
  document.getElementById('tab-' + name).classList.add('active');
}
</script>
@endpush
