@extends('tenant.onboarding._layout')

@section('screen')
  <div class="screen-header">
    <div class="screen-eyebrow">Step {{ $currentStep }} of {{ $totalSteps }}</div>
    <h2 class="screen-title">hours</h2>
    <p class="screen-sub">This screen is under construction. The route is wired and the layout works — content lands in the next pass.</p>
  </div>
  <div class="actions">
    <a href="{{ route('tenant.onboarding.wizard.industry', ['subdomain' => $tenant->subdomain]) }}" class="btn btn-ghost">← Back to Industry</a>
  </div>
@endsection
