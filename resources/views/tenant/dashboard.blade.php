@extends('layouts.tenant.app')
@section('content')

<div class="ia-page-head">
  <div class="ia-page-head-left">
    <h1 class="ia-page-title">Dashboard</h1>
    <p class="ia-page-subtitle">{{ $currentTenant->name }}</p>
  </div>
</div>

<div class="ia-card">
  <div style="padding: 40px 24px; text-align: center;">
    <div style="font-size: 17px; font-weight: 600; margin-bottom: 8px;">
      Welcome, {{ $authUser->name ?? 'there' }}.
    </div>
    <div style="font-size: 14px; opacity: .6;">
      Your dashboard is loading. Features are being rebuilt one at a time —
      use the sidebar to navigate to the sections that are ready.
    </div>
  </div>
</div>

@endsection
