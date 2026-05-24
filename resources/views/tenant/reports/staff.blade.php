@extends('layouts.tenant.app')
@section('title', 'Reports · Staff & Resources')

@push('styles')
@include('tenant.reports._tab_styles')
@endpush

@section('content')
<div style="padding: 32px 40px;">
  <h1 class="rep-h1">Reports</h1>
  <div class="rep-sub">{{ $today_label }}</div>

  @include('tenant.reports._tab_subnav', ['active' => 'staff'])

  <div class="rep-rangebar">
    <div><span class="rep-rangebar-label">Range</span><span class="rep-rangebar-current">{{ $range_label }}</span></div>
    <div class="rep-rangebar-controls">
      <nav class="rep-toggle">
        <a href="{{ route('tenant.reports.staff', ['range' => 'today']) }}"  class="{{ $range === 'today' ? 'active' : '' }}">Today</a>
        <a href="{{ route('tenant.reports.staff', ['range' => 'week']) }}"   class="{{ $range === 'week'  ? 'active' : '' }}">Week</a>
        <a href="{{ route('tenant.reports.staff', ['range' => 'month']) }}"  class="{{ $range === 'month' ? 'active' : '' }}">Month</a>
        <a href="{{ route('tenant.reports.staff', ['range' => 'last_30']) }}" class="{{ $range === 'last_30' ? 'active' : '' }}">Last 30</a>
      </nav>
    </div>
  </div>

  {{-- Booking density --}}
  <div class="rep-zone">
    <div class="rep-zone-head"><div><div class="rep-zone-title">📅 Booking density</div><div class="rep-zone-sub">Appointments per resource across the range.</div></div></div>
    <div class="rep-stat-strip">
      <div class="rep-stat-cell feat"><div class="lbl">Assigned appts</div><div class="val">{{ number_format($bookingDensity['total_appts']) }}</div><div class="meta">across {{ $bookingDensity['range_days'] }} {{ \Illuminate\Support\Str::plural('day', $bookingDensity['range_days']) }}</div></div>
    </div>
    @if($is_locked)
      @include('tenant.reports._locked_list', ['kind' => 'booking_density'])
    @elseif(empty($bookingDensity['list']))
      <div class="rep-empty">No appointments with assigned resources in range.</div>
    @else
      <table class="rep-tbl"><thead><tr><th>Resource</th><th>Type</th><th class="right">Appts</th><th class="right">Per day</th></tr></thead><tbody>
        @foreach($bookingDensity['list'] as $r)
          <tr><td><span class="rep-cell-name">{{ $r['resource_name'] }}</span></td><td>{{ ucfirst($r['resource_type']) }}</td><td class="right">{{ number_format($r['appt_count']) }}</td><td class="right">{{ $r['appts_per_day'] }}</td></tr>
        @endforeach
      </tbody></table>
    @endif
  </div>

  {{-- Revenue by staff --}}
  <div class="rep-zone">
    <div class="rep-zone-head"><div><div class="rep-zone-title">💎 Revenue by staff</div><div class="rep-zone-sub">Paid appointments per resource → linked staff user.</div></div></div>
    @if($is_locked)
      @include('tenant.reports._locked_list', ['kind' => 'revenue_by_staff'])
    @elseif(empty($revenueByStaff['list']))
      <div class="rep-empty">No paid appointments with resources in range.</div>
    @else
      <table class="rep-tbl"><thead><tr><th>Staff / Resource</th><th class="right">Paid appts</th><th class="right">Revenue</th></tr></thead><tbody>
        @foreach($revenueByStaff['list'] as $u)
          <tr><td><span class="rep-cell-name">{{ $u['name'] }}</span></td><td class="right">{{ number_format($u['appt_count']) }}</td><td class="right" style="color:#BEF264;">${{ number_format($u['revenue_cents']/100, 2) }}</td></tr>
        @endforeach
      </tbody></table>
    @endif
  </div>

  {{-- Utilization stub --}}
  <div class="rep-zone">
    <div class="rep-zone-head"><div><div class="rep-zone-title">⏱️ Resource utilization</div><div class="rep-zone-sub">Booked hours ÷ available hours, per resource.</div></div></div>
    <div class="rep-stub"><strong>Coming soon.</strong> {{ $utilization['reason'] }}</div>
  </div>

  {{-- Services by staff stub --}}
  <div class="rep-zone">
    <div class="rep-zone-head"><div><div class="rep-zone-title">🛠️ Services by staff</div><div class="rep-zone-sub">Per-staff appointment breakdown by service type.</div></div></div>
    <div class="rep-stub"><strong>Coming soon.</strong> {{ $servicesByStaff['reason'] }}</div>
  </div>

  {{-- Tips stub --}}
  <div class="rep-zone">
    <div class="rep-zone-head"><div><div class="rep-zone-title">💸 Tips by staff</div><div class="rep-zone-sub">Tip totals and distribution per staff member.</div></div></div>
    <div class="rep-stub"><strong>Coming soon.</strong> {{ $tipsByStaff['reason'] }}</div>
  </div>

</div>

@if($is_locked)
@include('tenant.reports._upsell_modal', ['title' => 'Staff & Resources Reports', 'pitch' => 'See booking density per resource and revenue by staff. More panels arriving as staff scheduling lands.'])
@endif
@endsection
