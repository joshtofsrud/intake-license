@extends('layouts.tenant.app')
{{-- MARKER-DASH-BRIEF: the third dashboard view, in beta. Built from the same
     data as Overview (DashboardController); Overview stays the default. --}}
@section('title', 'Dashboard')
@section('mobile-fab', 'walk-in')
@push('styles')
  <link rel="stylesheet" href="{{ asset('css/tenant/dashboard-simple.css') }}?v={{ filemtime(public_path('css/tenant/dashboard-simple.css')) }}">
  <link rel="stylesheet" href="{{ asset('css/tenant/dashboard-brief.css') }}?v={{ filemtime(public_path('css/tenant/dashboard-brief.css')) }}">
  <style>
    .mb-head .vs-ic { display: none; }
    @media (max-width: 1023px) {
      .mb-head .ia-viewseg button { padding: 5px 8px !important; line-height: 0; }
      .mb-head .ia-viewseg .vs-lb { display: none; }
      .mb-head .ia-viewseg .vs-ic { display: inline-block; }
      .mb-head.has-fab .mb-acts > a.ia-btn { display: none !important; }
    }
  </style>
@endpush

@section('content')
{{-- MARKER-DASH-TEMPLATES: Brief is now a template. This page is its frame
     (header, switch, two-column body); the parts inside are blocks, drawn in
     the order the template's live version lists them. Single-column rows at
     the top sit in the header; rows with columns become the two-column body. --}}
@php
  $briefHead = []; $briefBody = [];
  foreach ($dashRows as $r) {
      if (! $briefBody && count($r['split']) === 1) { $briefHead[] = $r; } else { $briefBody[] = $r; }
  }
@endphp

<div class="mb">

  <section class="mb-head {{ tenant()->retail_enabled ? 'has-fab' : '' }}">
    <div style="display:flex;align-items:center;gap:12px">
      <div class="mb-eyebrow">{{ $greeting['date_long'] }}</div>
      @include('tenant.dashboard._switch', ['segStyle' => 'margin-left:auto'])
    </div>

    @include('tenant.dashboard._rows', ['dashRows' => $briefHead])
  </section>

  @foreach ($briefBody as $bodyRow)
    @if (count($bodyRow['split']) > 1)
      <div class="mb-cols"@if ($bodyRow['split'] !== [7, 5]) style="grid-template-columns:{{ collect($bodyRow['split'])->map(fn ($n) => 'minmax(0, ' . $n . 'fr)')->implode(' ') }}"@endif>
        @foreach ($bodyRow['cols'] as $bodyCol)
          <section>
            @include('tenant.dashboard._rows', ['dashRows' => [['split' => [12], 'cols' => [$bodyCol]]]])
          </section>
        @endforeach
      </div>
    @else
      @include('tenant.dashboard._rows', ['dashRows' => [$bodyRow]])
    @endif
  @endforeach
</div>
@endsection
