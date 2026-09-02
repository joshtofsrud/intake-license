{{-- MARKER-INVEST-V2 — the public door. Leads with the problem and what a
     shop actually pays, because describing the company is not advertising the
     offering — and only the second is restricted. Terms, progress and the
     documents stay behind the code. --}}
<!DOCTYPE html>
<html lang="en"><head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Intake</title>
<meta name="robots" content="noindex, nofollow">
<link rel="icon" href="{{ asset('favicon.svg') }}" type="image/svg+xml">
<link rel="icon" href="{{ asset('favicon-32.png') }}" sizes="32x32">
<link rel="apple-touch-icon" href="{{ asset('apple-touch-icon.png') }}">
{{-- MARKER-INVEST-RETURNS — no og:image here on purpose: a personal link
     pasted into a thread would unfurl the round to everyone in it. --}}
<link rel="stylesheet" href="{{ asset('css/fonts.css') }}">
@include('invest._styles')
<style>
h1.big{font-size:clamp(34px,5.4vw,60px);letter-spacing:-2.3px;line-height:1.04}
h1.big .l{color:var(--lime)}
/* MARKER-INVEST-RULES */
section.hero{padding:84px 0 60px}
.lede.wide{font-size:clamp(16px,1.9vw,19px);margin-top:20px}
.grid3{display:grid;gap:14px;grid-template-columns:repeat(3,1fr);margin-top:22px}
.grid2{display:grid;gap:14px;grid-template-columns:1fr 1fr;margin-top:26px}
@media(max-width:860px){.grid3{grid-template-columns:1fr}}
@media(max-width:760px){.grid2{grid-template-columns:1fr}}
.card{background:var(--panel);border:1px solid var(--line);border-radius:12px;padding:22px}
.card.hi{border-color:var(--lime-line)}
.card p{font-size:14px;margin-top:9px}
.card .n{font-size:30px;font-weight:800;color:var(--lime);letter-spacing:-1.3px;line-height:1}
.card .k{font-size:10.5px;font-weight:600;letter-spacing:1.6px;text-transform:uppercase;color:var(--dim);margin-top:9px}
ul.tick{list-style:none;margin-top:18px}
ul.tick li{font-size:15px;color:var(--body);padding-left:24px;position:relative;margin-bottom:11px;line-height:1.55}
ul.tick li::before{content:"\2192";position:absolute;left:0;color:var(--lime);font-weight:600}
ul.tick li b{color:var(--text)}
textarea{width:100%;max-width:100%;background:var(--bg);border:1px solid var(--line2);border-radius:8px;
  color:var(--text);font-family:inherit;font-size:15px;padding:11px 13px;outline:none;min-height:78px;resize:vertical}
textarea:focus{border-color:var(--lime-line)}
.card input{max-width:100%}
.code{display:flex;gap:10px;margin-top:20px;flex-wrap:wrap}
.code input{flex:1;min-width:190px;font-family:ui-monospace,monospace;max-width:none}
.code .btn{margin-top:0}
.invite{margin-left:auto;font-size:10px;font-weight:600;letter-spacing:1.6px;text-transform:uppercase;
  color:var(--lime);border:1px solid var(--lime-line);background:var(--lime-soft);border-radius:5px;padding:4px 9px}
.hp{position:absolute;left:-9999px;width:1px;height:1px;overflow:hidden}
/* MARKER-CONTRIBUTIONS · MARKER-CONTRIB-UNIFORM — an aside, not a third way to
   take part in the round, so it stays quieter than the two cards above it.

   EVERY control below is 46px tall with an 8px radius and the page's own
   border token. Changing one of those three values means changing it for all
   of them, or the rows stop lining up — which is exactly how this section
   drifted before. */
.support{border:1px dashed var(--line2);border-radius:12px;padding:24px;margin-top:34px}
.support h3{font-size:17px;font-weight:700;letter-spacing:-.4px}
.support p{font-size:14px;margin-top:9px;max-width:64ch}

.support .amts{display:grid;grid-template-columns:repeat(4,1fr);gap:10px;margin-top:20px;align-items:stretch}
.support .fields{display:grid;grid-template-columns:repeat(3,1fr);gap:10px;margin-top:10px}
@media(max-width:760px){
  .support .amts{grid-template-columns:1fr 1fr}
  .support .fields{grid-template-columns:1fr}
}

/* the shared control: a preset chip and a text field are the same object */
.support .amt-btn,
.support input{height:46px;border-radius:8px;border:1px solid var(--line2);
  font-family:inherit;font-size:15px;width:100%;outline:none;
  transition:border-color .12s,background .12s,color .12s}

.support .amt-btn{background:var(--panel);color:var(--text);font-weight:650;cursor:pointer;padding:0}
.support .amt-btn:hover{border-color:var(--line2);background:var(--panel2)}
.support .amt-btn.on{background:var(--lime);border-color:var(--lime);color:#0a0a0a}

.support input{background:var(--bg);color:var(--text);padding:0 14px}
.support input:focus{border-color:var(--lime-line)}
.support input::placeholder{color:var(--dim)}

/* the $ sits inside the amount field and hides while the placeholder shows,
   so the two can never overlap */
/* MARKER-CONTRIB-NOWRAP — the amount field is a grid child like the buttons,
   with no wrapper of its own. The $ lives in the value, not in an overlay,
   so there is no positioned parent left to knock it out of line. */
.support #c-amt{text-align:center;font-weight:650}

.support .btn{height:46px;padding:0 24px;border-radius:8px;font-size:15px;margin-top:14px}
.support .fine{margin-top:18px;padding-top:14px;border-top:1px solid var(--line)}

/* MARKER-INVEST-MOBILE — the proof cards as a snap rail below 640. Stacked,
   they are three screens of scroll for what is one glance. The rail only
   works if it is obviously a rail, hence the peek, chevrons, dots and count. */
.railwrap{position:relative}
.fade,.chev,.railfoot{display:none}
@media(max-width:640px){
  .grid3{display:flex;gap:11px;overflow-x:auto;scroll-snap-type:x mandatory;
    margin:18px -20px 0;padding:0 20px 6px}
  .grid3::-webkit-scrollbar{height:0}
  /* 78% of the frame, so the next card is always visibly cut off */
  .grid3 .card{scroll-snap-align:start;flex:0 0 78%;padding:17px}
  .grid3 .card .n{font-size:26px}
  .grid3 .card p{font-size:13px}
  .fade{display:block;position:absolute;top:0;bottom:6px;width:34px;pointer-events:none}
  .fade.r{right:-20px;background:linear-gradient(270deg,var(--bg) 15%,transparent)}
  .fade.l{left:-20px;background:linear-gradient(90deg,var(--bg) 15%,transparent);opacity:0}
  .chev{display:flex;position:absolute;top:50%;transform:translateY(-50%);width:30px;height:30px;
    border-radius:50%;background:rgba(20,20,20,.92);border:1px solid var(--line2);color:var(--text);
    align-items:center;justify-content:center;font-size:15px;line-height:1;cursor:pointer;z-index:2;
    transition:opacity .15s}
  .chev.r{right:-6px}
  .chev.l{left:-6px;opacity:0;pointer-events:none}
  .railfoot{display:flex;align-items:center;gap:9px;margin-top:11px}
  .dots{display:flex;gap:6px}
  .dots i{width:6px;height:6px;border-radius:50%;background:var(--line2);display:block;transition:all .15s}
  .dots i.on{background:var(--lime);width:18px;border-radius:3px}
  .railhint{font-size:11px;color:var(--dim);letter-spacing:.4px;margin:0}
  .grid2{margin-top:18px}
  .srow{grid-template-columns:1fr auto}
  .srow .note{grid-column:1;grid-row:2;margin-top:2px;font-size:12px}
  .srow .amt{grid-row:1}
  .srow b{grid-column:1;grid-row:1}
  h1.big{font-size:31px;letter-spacing:-1.3px;line-height:1.12}
  /* Room for the docked bar, or it covers the last line of the page. */
  footer{padding-bottom:84px}
}
.dock{display:none;position:sticky;bottom:0;z-index:20;gap:9px;
  padding:11px 20px calc(11px + env(safe-area-inset-bottom));
  background:rgba(12,12,12,.94);backdrop-filter:blur(12px);border-top:1px solid var(--line)}
.dock a{flex:1;text-align:center;text-decoration:none;font-size:14px;font-weight:700;padding:12px 10px;
  border-radius:10px}
.dock .a1{background:var(--lime);color:#0a0a0a}
.dock .a2{border:1px solid var(--line2);color:var(--text)}

/* MARKER-INVEST-DOCK-FIX — last, on purpose. This has the same specificity as
   the display:none above it, so it only wins by coming after. Moving it back
   up among the other narrow-width rules would silently hide the dock again. */
@media(max-width:640px){
  .dock{display:flex}
}
</style>
</head><body>

<nav><div class="wrap">
  <a class="brand" href="/"><img src="{{ asset('icon.svg') }}" alt="" width="26" height="26"> intake</a>
  <span class="invite">By introduction</span>
</div></nav>

{{-- MARKER-INVEST-RAIL --}}
@php
  // MARKER-INVEST-RAILMENU — only four sections here, so one flat menu.
  $rail = [
    ['#top', 'The problem', null],
    ['menu', 'The case', null],
    ['#ask', 'Ask for the proposal', null],
    ['#support', 'Back the project', null],
    ['#talk', 'Talk to Josh', null], // MARKER-SCHED-TALK-ENTRY — rendered lime in _rail
    [url('/demo'), 'See the demo', null], // MARKER-INVEST-DEMO
  ];
  $railMenu = [
    [null, [
      ['#s-keep',  'Retention and recovery', 's-keep'],
      ['#s-bike',  'Why bike first',         's-bike'],
      ['#s-cap',   'The platform',           's-cap'],
      ['#s-stack', 'What it replaces',       's-stack'],
    ]],
  ];
@endphp
@include('invest._rail')

{{-- MARKER-DEAD-LINK — someone arrived here from a link that no longer works.
     Say so plainly rather than letting them wonder why they are on a different
     page than the one they clicked. --}}
@if(session('invest_link_dead') || session('invest_access_ended'))
  <section style="padding-bottom:0"><div class="wrap">
    <div class="ok">
      @if(session('invest_access_ended'))
        <b>That link has been closed.</b> If you think that's a mistake, or you'd like to pick the
        conversation back up, get in touch and I'll sort it out.
      @else
        <b>That link has been replaced.</b> It was a shared one and has since been rotated — ask below
        and I'll send you a current link of your own.
      @endif
    </div>
  </div></section>
@endif

<section class="hero" id="top"><div class="wrap">
  <span class="eyebrow">Built by a shop owner</span>
  <h1 class="big">{!! $headline !!}</h1>
  <p class="lede wide">{{ $lede }}</p>
  {{-- MARKER-SCHED-TALK-ENTRY — a conversation is the lowest-friction ask on the
       page; offer it before the form. Renders only while the type is bookable. --}}
  @php
    $talkType = \App\Models\PlatformBookingType::where('slug', 'investor')->first();
    // MARKER-INVEST-DEMO
    $investDemo   = \App\Models\Tenant::where('subdomain', 'demo')->where('is_demo', true)->first();
    $investDemoOn = $investDemo && \App\Models\DemoSetting::get('offline:demo') !== '1';
  @endphp
  @if($talkType && $talkType->isBookable())
  <div class="ok" style="display:flex;align-items:center;gap:14px;flex-wrap:wrap;margin-top:26px">
    <div class="talkbar-av" style="width:34px;height:34px;border-radius:50%;background:var(--panel2);display:grid;place-items:center;font-weight:700;color:var(--body);flex:none">J</div>
    <p style="margin:0;font-size:14px"><b>Questions first? Talk to Josh.</b><br>{{ $talkType->length_min }} minutes, one on one — no proposal, no code, no commitment.</p>
    {{-- MARKER-INVEST-BAR-ALIGN — matches the shared bar on the other pages --}}
    <div class="talkbar-actions" style="margin:0 0 0 auto;display:flex;gap:10px;align-items:center;flex-wrap:wrap">
      @if($investDemoOn)
        <a href="{{ url('/demo') }}" style="display:inline-flex;align-items:center;justify-content:center;height:42px;padding:0 18px;border:1px solid var(--lime-line);border-radius:8px;color:var(--lime);font-weight:600;font-size:14px;text-decoration:none;white-space:nowrap">See the demo</a>
      @endif
      <a class="btn" href="#talk" style="margin:0;padding:0 22px;height:42px;display:inline-flex;align-items:center;white-space:nowrap">Book a call</a>
    </div>
  </div>
  @endif
</div></section>

{{-- MARKER-INVEST-RAIL — platform first: it is the strongest of the three,
     and the invoice stack reads better as evidence than as the opening. --}}
<section><div class="wrap">

  {{-- MARKER-INVEST-RETENTION — the retention argument leads; the invoice
       stack drops to last, where it is evidence rather than the opening. --}}
  <details class="sec" id="s-keep">
    <summary>Retention and recovery <span class="cap">&mdash; most shops don't market at all</span></summary>
    <div class="body">@include('invest._retention')</div>
  </details>

  <details class="sec" id="s-bike">
    <summary>Why bike first <span class="cap">&mdash; the hardest version of the problem</span></summary>
    <div class="body">
{{-- MARKER-INVEST-UNIFY --}}
@include('invest._bike')
{{-- MARKER-INVEST-CAPABILITY --}}</div>
  </details>

  <details class="sec" id="s-cap">
    <summary>The platform <span class="cap">&mdash; one core, and what sits on top</span></summary>
    <div class="body">@include('invest._capability')</div>
  </details>

  <details class="sec" id="s-stack">
    <summary>What it replaces <span class="cap">&mdash; one shop&rsquo;s own invoices</span></summary>
    <div class="body">
@include('invest._stack')
</div>
  </details>

</div></section>


<section><div class="wrap">
  {{-- MARKER-INVEST-NOCODE — one card now, not two. --}}
  <div class="onecard">

    <div class="card hi" id="ask">
      <h2>Ask for the proposal</h2>
      @if($isOpen)
        <p>Intake is raising. The terms, the model and the risks are in a proposal I send by hand rather
          than publish — tell me who you are and how we know each other and I'll send an access code.</p>
      @else
        <p>The round isn't open at the moment. You're welcome to leave your details and I'll be in touch
          if that changes.</p>
      @endif

      @if(session('invest_request_ok'))
        <div class="ok">Thanks — that's with me. I'll come back to you directly rather than automatically,
          so give it a day or so.</div>
      @else
        <form method="POST" action="{{ route('invest.request') }}">
          @csrf
          <div class="hp"><input type="text" name="company_website" tabindex="-1" autocomplete="off"></div>

          <label>Your name</label>
          <input type="text" name="name" value="{{ old('name') }}" required maxlength="120">
          @error('name') <span class="cerr">{{ $message }}</span> @enderror

          <label>Email</label>
          <input type="email" name="email" value="{{ old('email') }}" required maxlength="190">
          @error('email') <span class="cerr">{{ $message }}</span> @enderror

          <label>How do we know each other?</label>
          <textarea name="note" required maxlength="1000">{{ old('note') }}</textarea>
          @error('note') <span class="cerr">{{ $message }}</span> @enderror

          <button class="btn" type="submit">Request access</button>
        </form>

        <p class="fine">That last question isn't a formality. This round is raised under an exemption that
          depends on a pre-existing relationship, so your answer in your own words is part of the record.
          Requests from people I don't know get a polite no rather than a code.</p>
        {{-- MARKER-SCHED-SECTION — a conversation first, if that's easier --}}
        @php $investCall = \App\Models\PlatformBookingType::where('slug', 'investor')->first(); @endphp
        @if($investCall && $investCall->isBookable())
          <p class="fine" style="margin-top:10px">Prefer to talk first? <a href="#talk" style="color:var(--lime)">Book a {{ $investCall->length_min }}-minute call below</a> — no proposal, no code, just questions.</p>
        @endif

      {{-- MARKER-INVEST-NOCODE — kept from the card that used to sit beside this
           one; they are the only place saying what is being asked for. --}}
      <div style="margin-top:26px;padding-top:20px;border-top:1px solid var(--line)">
        <h3>What's behind it</h3>
        <p style="font-size:14px;margin-top:9px">The full proposal and one-page summary, the two-year
          model and every assumption under it, the risks page, and the unsigned SAFE.</p>
      </div>

      <div class="legend">
        <b>Why the numbers aren't on this page.</b> Terms and progress are the offering, and the
        exemption this round relies on doesn't allow advertising it. What Intake is, and what it costs a
        shop, is just a company describing itself — that's everything above.
      </div>
      @endif
    </div>


  </div>

  {{-- MARKER-CONTRIBUTIONS — after the two cards, never beside them. --}}
  <div class="support" id="support">
    <h3>Can't invest, but want to back it?</h3>
    <p>Some people want to put something behind the project without buying into the round. You can, and
      it's a separate thing entirely — a contribution to the work, not a purchase.</p>

    <form method="POST" action="{{ route('invest.contribute') }}">
      @csrf
      <div class="hp"><input type="text" name="company_website" tabindex="-1" autocomplete="off"></div>

      {{-- MARKER-CONTRIB-UI — amounts come from Raise setup. --}}
      <div class="amts">
        @foreach($presets as $preset)
          <button type="button" class="amt-btn" data-amt="{{ $preset }}">${{ number_format($preset) }}</button>
        @endforeach

        {{-- MARKER-CONTRIB-NOWRAP — fourth cell, same box as the buttons. --}}
        <input type="text" name="amount" id="c-amt" value="{{ old('amount') }}"
               inputmode="decimal" placeholder="Other" required
               autocomplete="off" aria-label="Amount in dollars">
      </div>
      @error('amount') <span class="cerr">{{ $message }}</span> @enderror

      <div class="fields">
        <input type="text" name="name" value="{{ old('name') }}" placeholder="Your name" required maxlength="120">
        <input type="email" name="email" value="{{ old('email') }}" placeholder="Email for the receipt" required maxlength="190">
        <input type="tel" name="phone" value="{{ old('phone') }}" placeholder="Phone (optional)" maxlength="40">
      </div>
      @error('name')  <span class="cerr">{{ $message }}</span> @enderror
      @error('email') <span class="cerr">{{ $message }}</span> @enderror
      @error('phone') <span class="cerr">{{ $message }}</span> @enderror

      <button class="btn" type="submit">Contribute</button>
    </form>

    <p class="fine"><b>This buys nothing, and that's the point.</b> No equity, no SAFE, no share of
      anything later, and no expectation of a return — it does not convert into the round and never
      becomes one. Intake Inc is a for-profit company, so this isn't a charitable donation and isn't tax
      deductible. If you'd rather invest, the round is above and the two are not connected. Card details
      are handled by Stripe and never touch this site.</p>
  </div>

  <p class="fine">{{ $fine }}</p>
</div></section>

{{-- MARKER-SCHED-INVEST — talk first: the scheduling calendar, investor type. The
     widget styles itself from --mk-* vars, mapped here onto the invest palette. --}}
@php $investCall = \App\Models\PlatformBookingType::where('slug', 'investor')->first(); @endphp
@if($investCall && $investCall->isBookable())
<section id="talk"><div class="wrap" style="--mk-accent:var(--lime);--mk-accent-text:#0a0a0a;--mk-bg2:var(--panel);--mk-bg3:var(--panel2);--mk-text:var(--text);--mk-muted:var(--body);--mk-dim:var(--dim);--mk-border:var(--line);--mk-border2:var(--line2);--mk-r:8px;--mk-r-lg:12px">
  <div class="eyebrow">Talk first</div>
  <h2 style="margin:8px 0 6px">A conversation before a proposal</h2>
  <p style="color:var(--body);max-width:64ch;margin:0 0 18px">{{ $investCall->length_min }} minutes, one on one, no code needed. Ask about the business, the numbers or the terms — booking a call isn't a commitment and nothing here is an offer to sell securities.</p>
  @include('marketing._book-widget', ['type' => $investCall, 'booking' => null, 'showHost' => true])
</div></section>
@endif

<script>
// MARKER-CONTRIBUTIONS — the preset buttons only fill the amount field; the
// field is what submits, so a typed amount always wins.
(function () {
  var field = document.getElementById('c-amt');
  var btns  = document.querySelectorAll('.amt-btn');
  if (!field || !btns.length) { return; }

  // MARKER-CONTRIB-AMOUNT — compare on the number, not the string, so "250",
  // "$250" and "250.00" all light the same button.
  function mark(val) {
    var n = parseFloat(String(val).replace(/[^0-9.]/g, ''));
    for (var i = 0; i < btns.length; i++) {
      btns[i].classList.toggle('on', parseFloat(btns[i].dataset.amt) === n);
    }
  }

  // MARKER-CONTRIB-NOWRAP — the $ is written into the value rather than
  // floated over the field, so the input needs no wrapper to position it.
  // The server strips $ and commas before validating.
  function withSymbol(v) {
    var digits = String(v).replace(/[^0-9.]/g, '');
    return digits ? '$' + digits : '';
  }

  for (var i = 0; i < btns.length; i++) {
    btns[i].addEventListener('click', function () {
      field.value = withSymbol(this.dataset.amt);
      mark(this.dataset.amt);
    });
  }

  field.addEventListener('input', function () {
    var caretAtEnd = field.selectionStart === field.value.length;
    var next = withSymbol(field.value);

    if (next !== field.value) {
      field.value = next;
      // Typing normally means the caret was at the end; putting it back there
      // stops the reformat from throwing the cursor to the start.
      if (caretAtEnd) { field.setSelectionRange(next.length, next.length); }
    }

    mark(field.value);
  });
})();
</script>

{{-- MARKER-INVEST-CONFIRM --}}
@include('invest._confirm', [
  'confirmTitle' => 'Thanks — that is with me.',
  'confirmBody'  => 'I will come back to you directly rather than automatically, so give it a day or so.',
])

<footer><div class="wrap">intake · intake.works</div></footer>

{{-- MARKER-INVEST-MOBILE — the two things this page exists for, always one tap
     away. Hidden above 640 where both cards are already on screen. --}}
{{-- MARKER-INVEST-NOCODE — one action now, so the dock carries one button. --}}
<div class="dock">
  <a class="a1" href="#ask">Request the proposal</a>
</div>

</body></html>
