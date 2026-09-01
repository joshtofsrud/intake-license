@extends('marketing.layout')
{{-- MARKER-SCHED-PUBLIC --}}
@section('title', ($booking ?? null) ? 'Pick a new time — Intake' : $type->name . ' — Intake')
@section('meta_description', $type->description ?: 'Book a time to talk with Intake.')

@section('content')
<section class="mk-section" style="border-bottom:none">
  <div class="mk-container" style="max-width:960px">
    @if($closed)
      <div class="mk-eyebrow">Book a call</div>
      <h1 class="mk-section-title">Not taking bookings right now</h1>
      <p class="mk-section-sub">This link is paused for the moment. Drop us a line instead and we'll find a time.</p>
      <a href="{{ route('marketing.contact') }}" class="mk-btn mk-btn--primary">Contact us</a>
    @else
      <div class="mk-eyebrow">{{ ($booking ?? null) ? 'Reschedule' : 'Book a call' }}</div>
      @include('marketing._book-widget', ['type' => $type, 'booking' => $booking ?? null])
    @endif
  </div>
</section>
@endsection
