@extends('layouts.tenant.app')
@php $pageTitle = 'Rentals launch checklist'; @endphp
{{-- MARKER-LAUNCH-CHECKLIST: the rentals checklist, in the rental pages' own frame. --}}

@include('layouts.tenant._rental-styles')

@section('content')
<div class="sd rf" id="sd" style="max-width:1000px">
  <header class="sd-head">
    <div class="sd-head-l">
      <h1 class="sd-hi">Rentals</h1>
      <p class="sd-sub">Your fleet, rates and booking page.</p>
    </div>
    <div><a class="ia-btn ia-btn--secondary ia-btn--sm" href="/rentals" target="_blank">View your booking page ↗</a></div>
  </header>

  @include('layouts.tenant._rental-nav', ['active' => 'launch'])

  @if(session('success'))<div class="ia-flash ia-flash--success" style="margin-bottom:16px">{{ session('success') }}</div>@endif
  @if(session('error'))<div class="ia-flash ia-flash--error" style="margin-bottom:16px">{{ session('error') }}</div>@endif

  @include('tenant.launch._checklist', ['ev' => $ev])
</div>
@endsection

@push('scripts')
@include('layouts.tenant._rental-boot')
@endpush
