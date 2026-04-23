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

@include('tenant.dashboard._zone_today')
@include('tenant.dashboard._zone_attention')
@include('tenant.dashboard._zone_growth')

@endsection
