@extends('layouts.tenant.app')
@push('styles')
  <link rel="stylesheet" href="{{ asset('css/tenant/dashboard.css') }}">
@endpush
@section('content')

@php
  $greetingWord = "Good {$greeting['time_of_day']}";
  $greetingLine = $greeting['name'] ? "{$greetingWord}, {$greeting['name']}." : "{$greetingWord}.";
@endphp

<div class="ia-page-head">
  <div class="ia-page-head-left">
    <h1 class="ia-page-title">{{ $greetingLine }}</h1>
    <p class="ia-page-subtitle">{{ $greeting['date_long'] }}</p>
  </div>
  <div class="ia-page-actions">
    <a href="{{ route('tenant.appointments.index') }}" class="ia-btn ia-btn--primary">
      + New appointment
    </a>
  </div>
</div>

@if(!empty($workOrderBanner))
<div class="wof-dashboard-banner" id="wof-banner" style="background:var(--ia-accent-soft);border-left:2px solid var(--ia-accent);border-radius:var(--ia-r-md);padding:16px 20px;margin-bottom:20px;display:flex;justify-content:space-between;align-items:flex-start;gap:16px">
  <div style="flex:1">
    <div style="font-size:14px;font-weight:500;margin-bottom:4px">{{ $workOrderBanner['title'] }}</div>
    <div style="font-size:13px;opacity:.75;line-height:1.5">{{ $workOrderBanner['body'] }}</div>
  </div>
  <div style="display:flex;gap:8px;flex-shrink:0">
    <a href="{{ $workOrderBanner['cta_url'] }}" class="ia-btn ia-btn--primary ia-btn--sm">{{ $workOrderBanner['cta_label'] }}</a>
    <button type="button" class="ia-btn ia-btn--ghost ia-btn--sm" id="wof-banner-dismiss">Dismiss</button>
  </div>
</div>

@push('scripts')
<script>
(function(){
  var btn = document.getElementById('wof-banner-dismiss');
  var banner = document.getElementById('wof-banner');
  if (!btn || !banner) return;
  btn.addEventListener('click', function(){
    var fd = new FormData();
    fd.append('_token', window.IntakeAdmin.csrfToken);
    fetch('{{ route("tenant.dashboard.wof-banner.dismiss") }}', {
      method: 'POST',
      body: fd,
      headers: { 'X-Requested-With': 'XMLHttpRequest' }
    }).then(function(){ banner.style.display = 'none'; });
  });
})();
</script>
@endpush
@endif

@include('tenant.dashboard._zone_today')
@include('tenant.dashboard._zone_attention')
@include('tenant.dashboard._zone_growth')

@endsection
