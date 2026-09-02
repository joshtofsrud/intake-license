{{-- MARKER-SCHED-TALK-ALL — "talk to Josh" bar for invest pages that don't carry
     the on-page calendar; links to the public booking page instead. Renders
     only while the investor booking type is on. --}}
@php $talkBarType = \App\Models\PlatformBookingType::where('slug', 'investor')->first(); @endphp
@php
    // MARKER-INVEST-DEMO — one lookup, shared shape with the marketing section.
    $investDemo = \App\Models\Tenant::where('subdomain', 'demo')->where('is_demo', true)->first();
    $investDemoOn = $investDemo && \App\Models\DemoSetting::get('offline:demo') !== '1';
@endphp
@if($talkBarType && $talkBarType->isBookable())
{{-- MARKER-INVEST-SHARE — was flush against the first accordion --}}
<section style="padding:18px 0 34px"><div class="wrap">
  <div class="ok talkbar">
    <div class="talkbar-av">J</div>
    <p class="talkbar-copy"><b>Questions? Talk to Josh.</b><br>{{ $talkBarType->length_min }} minutes, one on one — often quicker than email.</p>
    {{-- MARKER-INVEST-BAR-ALIGN — two actions, one line, same height --}}
    <div class="talkbar-actions">
      @if($investDemoOn)
        <a class="talkbar-btn talkbar-btn--ghost" href="{{ url('/demo') }}">See the demo</a>
      @endif
      <a class="talkbar-btn" href="{{ $talkBarType->publicUrl() }}">Book a call</a>
    </div>
  </div>
</div></section>
@endif
