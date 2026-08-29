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

<section class="hero"><div class="wrap">
  <span class="eyebrow">Built by a shop owner</span>
  <h1 class="big">{!! $headline !!}</h1>
  <p class="lede wide">{{ $lede }}</p>
</div></section>

<section><div class="wrap">
  <p class="sub">What one three-location shop was actually paying</p>
  <div class="stack">
    <div class="srow"><b>Ascend POS · 3 locations</b><span class="note">Retail only</span><span class="amt">$750</span></div>
    <div class="srow"><b>Shopify + add-ons</b><span class="note">A second catalog to maintain</span><span class="amt">$680–880</span></div>
    <div class="srow"><b>MasterLinq</b><span class="note">Supplier data the register never saw</span><span class="amt">$550</span></div>
    <div class="srow"><b>Constant Contact</b><span class="note">Marketing, disconnected</span><span class="amt">$185</span></div>
    <div class="srow"><b>Booqable · Freshdesk</b><span class="note">Rentals and inbox</span><span class="amt">$109</span></div>
    <div class="srow sum"><b>Every month</b><span class="note">Six subscriptions, no shared customer record</span><span class="amt">$2,274–2,474</span></div>
    <div class="srow tot"><b>Intake, same three locations</b><span class="note">Intake does all the services listed above</span><span class="amt">$775</span></div>
  </div>
  <details class="m">
    <summary>Where these numbers come from</summary>
    <div class="inner"><p style="font-size:13.5px">One shop's own invoices, not list pricing — used because
      they're verifiable, not because they're typical. The saving isn't a discount on the same stack. It's
      a stack that isn't there.</p></div>
  </details>
</div></section>

<section><div class="wrap">
  <p class="sub">Why bike first</p>
  <h2 style="font-size:clamp(24px,3.2vw,34px);letter-spacing:-1.1px">The hardest version of the problem.</h2>
  <p class="lede wide">Specialty bike is a service business, a retail business and a rental business at
    once. A platform that runs a bike shop runs a ski shop, a paddle shop or a fitness studio without
    being rebuilt.</p>

  <div class="railwrap">
  <div class="grid3" id="rail">
    <div class="card"><div class="n">~97k</div><div class="k">Catalog rows, three distributors</div>
      <p>Cross-distributor product matching — months of work and supplier relationships a competitor starts
        from zero on. More distributors are being added, the architecture has no ceiling on how many it
        carries, and the same pipes serve industries beyond bike.</p></div>
    <div class="card"><div class="n">8</div><div class="k">States, founding rep group</div>
      <p>Sold by reps who already walk into every shop in the territory, rather than cold outbound into an
        industry that ignores it.</p></div>
    <div class="card"><div class="n">Live</div><div class="k">In production</div>
      <p>Not a prototype. A founding shop is signed and converting its full point of sale, and the founder's
        own mobile service business runs on it daily.</p></div>
  </div>
    <span class="fade l" id="fadeL"></span><span class="fade r" id="fadeR"></span>
    <button type="button" class="chev l" id="chevL" aria-label="Previous">&#8249;</button>
    <button type="button" class="chev r" id="chevR" aria-label="Next">&#8250;</button>
  </div>
  <div class="railfoot">
    <span class="dots" id="dots"><i class="on"></i><i></i><i></i></span>
    <p class="railhint" id="railhint">1 of 3 &mdash; swipe or tap &#8250;</p>
  </div>

  <ul class="tick">
    <li><b>Owner and GM of a multi-store specialty bicycle retailer</b> — buying, building, hiring,
      scheduling, opening locations, every vendor relationship</li>
    <li><b>70+ cycling events produced</b> through Velo Northwest, and a component brand designed and shipped</li>
    <li><b>Twenty years in the market</b> this is being sold into</li>
  </ul>
</div></section>

<section><div class="wrap">
  <div class="grid2">

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
      @endif
    </div>

    <div class="card" id="code">
      <h2>Have a code?</h2>
      <p>Open the proposal. Codes are issued to one person and can be withdrawn.</p>

      <form method="POST" action="{{ route('invest.enter') }}">
        @csrf
        <div class="code">
          <input type="text" name="code" placeholder="Paste your code" required autocomplete="off">
          <button class="btn ghost" type="submit">Open</button>
        </div>
        @error('code') <span class="cerr">{{ $message }}</span> @enderror
      </form>

      <div style="margin-top:26px;padding-top:20px;border-top:1px solid var(--line)">
        <h3>What's behind it</h3>
        <p style="font-size:14px;margin-top:9px">The full proposal and one-page summary, the two-year model
          and every assumption under it, the risks page, and the unsigned SAFE.</p>
      </div>

      <div class="legend">
        <b>Why the numbers aren't on this page.</b> Terms and progress are the offering, and the exemption
        this round relies on doesn't allow advertising it. What Intake is, and what it costs a shop, is
        just a company describing itself — that's everything above.
      </div>
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

<footer><div class="wrap">intake · intake.works</div></footer>

{{-- MARKER-INVEST-MOBILE — the two things this page exists for, always one tap
     away. Hidden above 640 where both cards are already on screen. --}}
<div class="dock">
  <a class="a1" href="#ask">Request access</a>
  <a class="a2" href="#code">I have a code</a>
</div>

<script>
(function () {
  var rail = document.getElementById('rail');
  if (!rail) { return; }

  var cards = rail.querySelectorAll('.card');
  var dots  = document.getElementById('dots');
  var hint  = document.getElementById('railhint');
  var chevL = document.getElementById('chevL'), chevR = document.getElementById('chevR');
  var fadeL = document.getElementById('fadeL'), fadeR = document.getElementById('fadeR');
  if (!cards.length || !dots || !hint || !chevL || !chevR) { return; }

  function step() { return cards[0].offsetWidth + 11; }

  function sync() {
    var i = Math.round(rail.scrollLeft / step());
    i = Math.max(0, Math.min(cards.length - 1, i));

    for (var d = 0; d < dots.children.length; d++) {
      dots.children[d].className = d === i ? 'on' : '';
    }
    hint.textContent = (i + 1) + ' of ' + cards.length +
      (i < cards.length - 1 ? ' \u2014 swipe or tap \u203A' : '');

    var atStart = rail.scrollLeft < 8;
    var atEnd   = rail.scrollLeft > rail.scrollWidth - rail.clientWidth - 8;
    chevL.style.opacity = atStart ? 0 : 1;
    chevL.style.pointerEvents = atStart ? 'none' : 'auto';
    chevR.style.opacity = atEnd ? 0 : 1;
    chevR.style.pointerEvents = atEnd ? 'none' : 'auto';
    fadeL.style.opacity = atStart ? 0 : 1;
    fadeR.style.opacity = atEnd ? 0 : 1;
  }

  rail.addEventListener('scroll', sync, {passive: true});
  chevR.addEventListener('click', function () { rail.scrollBy({left: step(), behavior: 'smooth'}); });
  chevL.addEventListener('click', function () { rail.scrollBy({left: -step(), behavior: 'smooth'}); });
  window.addEventListener('resize', sync);
  sync();
})();
</script>
</body></html>
