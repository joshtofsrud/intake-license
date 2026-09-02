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
<section style="padding:26px 0 0"><div class="wrap">
  <div class="ok" style="display:flex;align-items:center;gap:14px;flex-wrap:wrap">
    <div style="width:34px;height:34px;border-radius:50%;background:var(--panel2);display:grid;place-items:center;font-weight:700;color:var(--body);flex:none">J</div>
    <p style="margin:0;font-size:14px"><b>Questions? Talk to Josh.</b><br>{{ $talkBarType->length_min }} minutes, one on one — often quicker than email.</p>
    <div style="margin:0 0 0 auto;display:flex;gap:10px;align-items:center;flex-wrap:wrap">
      @if($investDemoOn)
        {{-- MARKER-INVEST-DEMO — seeing it beats reading about it --}}
        <a href="{{ url('/demo') }}" style="color:var(--lime);font-size:14px;text-decoration:underline">See the demo</a>
      @endif
      <a class="btn" href="{{ $talkBarType->publicUrl() }}" style="margin:0;padding:10px 18px">Book a call</a>
    </div>
  </div>
</div></section>
@endif
