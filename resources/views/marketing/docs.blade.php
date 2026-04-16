@extends('marketing.layout')
@section('title', 'Docs — Intake')

@section('content')
<section class="mk-section" style="min-height:60vh;display:flex;align-items:center;border-bottom:none">
  <div class="mk-container" style="text-align:center">
    <div class="mk-eyebrow">Documentation</div>
    <h1 class="mk-section-title" style="font-size:clamp(28px,4vw,44px);margin-bottom:10px">Coming soon</h1>
    <p class="mk-section-sub" style="margin:0 auto 28px">Full documentation is on its way. In the meantime, reach out and we'll help you directly.</p>
    <a href="{{ route('marketing.contact') }}" class="mk-btn mk-btn--primary">Contact us</a>
  </div>
</section>
@endsection
