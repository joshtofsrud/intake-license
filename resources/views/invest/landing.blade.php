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
<link rel="stylesheet" href="{{ asset('css/fonts.css') }}">
@include('invest._styles')
<style>
h1.big{font-size:clamp(34px,5.4vw,60px);letter-spacing:-2.3px;line-height:1.04}
h1.big .l{color:var(--lime)}
section.hero{padding:84px 0 66px}
.lede.wide{font-size:clamp(16px,1.9vw,19px);margin-top:20px}
.grid3{display:grid;gap:14px;grid-template-columns:repeat(3,1fr);margin-top:28px}
.grid2{display:grid;gap:14px;grid-template-columns:1fr 1fr;margin-top:26px}
@media(max-width:860px){.grid3{grid-template-columns:1fr}}
@media(max-width:760px){.grid2{grid-template-columns:1fr}}
.card{background:var(--panel);border:1px solid var(--line);border-radius:12px;padding:22px}
.card.hi{border-color:var(--lime-line)}
.card p{font-size:14px;margin-top:9px}
.card .n{font-size:30px;font-weight:800;color:var(--lime);letter-spacing:-1.3px;line-height:1}
.card .k{font-size:10.5px;font-weight:600;letter-spacing:1.6px;text-transform:uppercase;color:var(--dim);margin-top:9px}
.stack{background:var(--panel);border:1px solid var(--line);border-radius:12px;overflow:hidden;margin-top:24px}
.srow{display:grid;grid-template-columns:minmax(0,1fr) minmax(0,1.1fr) 110px;gap:18px;padding:13px 18px;
  border-bottom:1px solid var(--line);font-size:14.5px;align-items:baseline}
.srow:last-child{border-bottom:0}
.srow b{color:var(--text);font-weight:550}
.srow .note{color:var(--dim);font-size:13px}
.srow .amt{text-align:right;font-variant-numeric:tabular-nums;color:var(--body);white-space:nowrap}
.srow.sum{border-top:1px solid var(--line2)}
.srow.sum b,.srow.sum .amt{color:var(--text);font-weight:700}
.srow.tot{background:var(--panel2);border-top:1px solid var(--line2)}
.srow.tot b,.srow.tot .amt{color:var(--lime);font-weight:700}
@media(max-width:700px){.srow{grid-template-columns:minmax(0,1fr) 100px}.srow .note{display:none}}
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
  <p class="fine">One shop's own invoices, not list pricing — used because they're verifiable, not because
    they're typical. The saving isn't a discount on the same stack. It's a stack that isn't there.</p>
</div></section>

<section><div class="wrap">
  <p class="sub">Why bike first</p>
  <h2 style="font-size:clamp(24px,3.2vw,34px);letter-spacing:-1.1px">The hardest version of the problem.</h2>
  <p class="lede wide">Specialty bike is a service business, a retail business and a rental business at
    once. A platform that runs a bike shop runs a ski shop, a paddle shop or a fitness studio without
    being rebuilt.</p>

  <div class="grid3">
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

  <ul class="tick">
    <li><b>Owner and GM of a multi-store specialty bicycle retailer</b> — buying, building, hiring,
      scheduling, opening locations, every vendor relationship</li>
    <li><b>70+ cycling events produced</b> through Velo Northwest, and a component brand designed and shipped</li>
    <li><b>Twenty years in the market</b> this is being sold into</li>
  </ul>
</div></section>

<section><div class="wrap">
  <div class="grid2">

    <div class="card hi">
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

    <div class="card">
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

  <p class="fine">{{ $fine }}</p>
</div></section>

<footer><div class="wrap">intake · intake.works</div></footer>
</body></html>
