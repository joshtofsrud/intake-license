@extends('layouts.tenant.app')
{{-- MARKER-DASH-TEMPLATES: the frame for templates built in master admin.
     A date line and the dashboard switch, then the template's rows. Every
     block's stylesheet is loaded, since a template can mix blocks from
     Overview, Tiles and Brief. --}}
@section('title', 'Dashboard')
@section('mobile-fab', 'walk-in')
@push('styles')
  <link rel="stylesheet" href="{{ asset('css/tenant/dashboard.css') }}?v={{ filemtime(public_path('css/tenant/dashboard.css')) }}">
  <link rel="stylesheet" href="{{ asset('css/tenant/dashboard-tiles.css') }}?v={{ filemtime(public_path('css/tenant/dashboard-tiles.css')) }}">
  <link rel="stylesheet" href="{{ asset('css/tenant/dashboard-simple.css') }}?v={{ filemtime(public_path('css/tenant/dashboard-simple.css')) }}">
  <link rel="stylesheet" href="{{ asset('css/tenant/dashboard-brief.css') }}?v={{ filemtime(public_path('css/tenant/dashboard-brief.css')) }}">
  <style>
    .mb-head .vs-ic { display: none; }
    .dash-std-rows > * + * { margin-top: 32px; }
    @media (max-width: 1023px) {
      .mb-head .ia-viewseg button { padding: 5px 8px !important; line-height: 0; }
      .mb-head .ia-viewseg .vs-lb { display: none; }
      .mb-head .ia-viewseg .vs-ic { display: inline-block; }
    }
  </style>
@endpush

@section('content')
<div class="mb">
  <section class="mb-head">
    <div style="display:flex;align-items:center;gap:12px;margin-bottom:22px">
      <div class="mb-eyebrow">{{ $greeting['date_long'] }}</div>
      @include('tenant.dashboard._switch', ['segStyle' => 'margin-left:auto'])
      @include('tenant.dashboard._customize_button')
    </div>
  </section>
  <div class="dash-std-rows">
    @include('tenant.dashboard._rows', ['dashRows' => $dashRows])
  </div>
</div>
@include('tenant.dashboard._customize_panel')
@endsection
