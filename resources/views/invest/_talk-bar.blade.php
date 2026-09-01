{{-- MARKER-SCHED-TALK-ALL — "talk to Josh" bar for invest pages that don't carry
     the on-page calendar; links to the public booking page instead. Renders
     only while the investor booking type is on. --}}
@php $talkBarType = \App\Models\PlatformBookingType::where('slug', 'investor')->first(); @endphp
@if($talkBarType && $talkBarType->isBookable())
<section style="padding:26px 0 0"><div class="wrap">
  <div class="ok" style="display:flex;align-items:center;gap:14px;flex-wrap:wrap">
    <div style="width:34px;height:34px;border-radius:50%;background:var(--panel2);display:grid;place-items:center;font-weight:700;color:var(--body);flex:none">J</div>
    <p style="margin:0;font-size:14px"><b>Questions? Talk to Josh.</b><br>{{ $talkBarType->length_min }} minutes, one on one — often quicker than email.</p>
    <a class="btn" href="{{ $talkBarType->publicUrl() }}" style="margin:0 0 0 auto;padding:10px 18px">Book a call</a>
  </div>
</div></section>
@endif
