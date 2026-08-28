<!DOCTYPE html>
{{-- MARKER-INVEST-LANDING — public door. No totals, no forecast, no deck. --}}
<html lang="en"><head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Intake</title>
<meta name="robots" content="noindex, nofollow">
<link rel="stylesheet" href="{{ asset('css/fonts.css') }}">
<style>
:root{
  --bg:#0B0F0C; --panel:#111710; --line:#1F2A1E;
  --text:#F2F4EE; --body:#8D9A8B; --dim:#5F6A5E;
  --lime:#BEF264; --lime-soft:rgba(190,242,100,.09); --lime-line:rgba(190,242,100,.34);
  --red:#FCA5A5; --max:1080px;
}
*{margin:0;padding:0;box-sizing:border-box}
body{background:var(--bg);color:var(--text);font-family:Inter,system-ui,sans-serif;
  font-size:16px;line-height:1.6;-webkit-font-smoothing:antialiased}
.wrap{max-width:var(--max);margin:0 auto;padding:0 28px}
nav{border-bottom:1px solid var(--line)}
nav .wrap{display:flex;align-items:center;gap:18px;height:66px}
.brand{display:flex;align-items:center;gap:10px;font-size:19px;font-weight:700;letter-spacing:-.5px;
  color:var(--text);text-decoration:none}
.logo{width:27px;height:27px;border-radius:7px;background:var(--lime)}
.invite{margin-left:auto;font-size:10px;font-weight:600;letter-spacing:1.6px;text-transform:uppercase;
  color:var(--lime);border:1px solid var(--lime-line);background:var(--lime-soft);border-radius:5px;padding:4px 9px}
.eyebrow{font-size:11px;font-weight:600;letter-spacing:2.6px;text-transform:uppercase;color:var(--lime)}
h1{font-size:clamp(32px,5.2vw,56px);font-weight:800;letter-spacing:-2.1px;line-height:1.05;margin:18px 0 0}
h2{font-size:22px;font-weight:800;letter-spacing:-.9px;margin:0}
h3{font-size:15.5px;font-weight:600}
p{color:var(--body);line-height:1.65}
.lede{font-size:clamp(16px,1.9vw,19px);max-width:62ch;margin-top:20px}
b,strong{color:var(--text);font-weight:600}
section{padding:60px 0;border-top:1px solid var(--line)}
section.hero{border-top:0;padding:76px 0 60px}
.tags{display:flex;flex-wrap:wrap;gap:34px;margin-top:38px;padding-top:22px;border-top:1px solid var(--line)}
.tags div b{display:block;font-size:13px;font-weight:700;letter-spacing:1.4px;text-transform:uppercase;color:var(--lime)}
.tags div span{font-size:11.5px;letter-spacing:1.4px;text-transform:uppercase;color:var(--dim);font-weight:500}
.grid{display:grid;gap:14px;grid-template-columns:1fr 1fr}
@media(max-width:760px){.grid{grid-template-columns:1fr}}
.card{background:var(--panel);border:1px solid var(--line);border-radius:12px;padding:24px}
.card.hi{border-color:var(--lime-line)}
.card p{font-size:14px;margin-top:10px}
label{display:block;font-size:10.5px;font-weight:600;letter-spacing:1.6px;text-transform:uppercase;
  color:var(--dim);margin:16px 0 7px}
input,textarea{width:100%;background:var(--bg);border:1px solid var(--line);border-radius:8px;
  color:var(--text);font-family:inherit;font-size:15px;padding:11px 13px;outline:none}
input:focus,textarea:focus{border-color:var(--lime-line)}
textarea{min-height:80px;resize:vertical}
.btn{display:inline-block;background:var(--lime);color:#0B0F0C;border:0;border-radius:8px;
  font-family:inherit;font-size:14.5px;font-weight:700;padding:12px 22px;cursor:pointer;margin-top:20px}
.btn.ghost{background:none;color:var(--text);border:1px solid var(--line);font-weight:600}
.code{display:flex;gap:10px;margin-top:20px;flex-wrap:wrap}
.code input{flex:1;min-width:200px;font-family:ui-monospace,monospace}
.code .btn{margin-top:0}
.err{color:var(--red);font-size:13px;margin-top:8px}
.ok{border:1px solid var(--lime-line);background:var(--lime-soft);border-radius:10px;
  padding:16px 18px;margin-top:18px;font-size:14px;color:var(--text)}
.hp{position:absolute;left:-9999px;width:1px;height:1px;overflow:hidden}
.fine{margin-top:26px;padding-top:16px;border-top:1px solid var(--line);font-size:12.5px;
  color:var(--dim);line-height:1.6}
.legend{border-left:2px solid var(--lime-line);padding:10px 0 10px 16px;margin-top:22px;
  font-size:13.5px;color:var(--body)}
.legend b{color:var(--lime)}
footer{border-top:1px solid var(--line);padding:30px 0;font-size:12px;color:var(--dim)}
</style>
</head><body>

<nav><div class="wrap">
  <a class="brand" href="/"><span class="logo"></span> intake</a>
  <span class="invite">By introduction</span>
</div></nav>

<section class="hero"><div class="wrap">
  <span class="eyebrow">Intake</span>
  <h1>{{ $headline }}</h1>
  <p class="lede">{{ $lede }}</p>

  <div class="tags">
    <div><b>${{ number_format($target) }}</b><span>Raise</span></div>
    <div><b>{{ $instrument }}</b><span>${{ number_format($cap / 1000000, 1) }}M cap</span></div>
    <div><b>{{ $stageLabel }}</b><span>{{ $stageSub }}</span></div>
  </div>

  <div class="legend">
    <b>What this page is.</b> Three facts about the round and a way to get in touch — nothing more.
    No forecasts, no traction figures, and no progress toward the target. Those are behind the access
    code, where the audience is people who have asked and been sent one individually.
  </div>
</div></section>

<section><div class="wrap">
  <div class="grid">

    <div class="card hi">
      <h2>Request the proposal</h2>

      @if(! $isOpen)
        <p>This round isn't open at the moment. You're welcome to leave your details and I'll be in
          touch if that changes.</p>
      @else
        <p>The full proposal is sent by hand, not downloaded. Tell me who you are and how we know each
          other and I'll send an access code.</p>
      @endif

      @if(session('invest_request_ok'))
        <div class="ok">Thanks — that's with me. I'll come back to you directly rather than
          automatically, so give it a day or so.</div>
      @else
        <form method="POST" action="{{ route('invest.request') }}">
          @csrf
          <div class="hp"><label>Company website</label><input type="text" name="company_website" tabindex="-1" autocomplete="off"></div>

          <label>Your name</label>
          <input type="text" name="name" value="{{ old('name') }}" required maxlength="120">
          @error('name') <p class="err">{{ $message }}</p> @enderror

          <label>Email</label>
          <input type="email" name="email" value="{{ old('email') }}" required maxlength="190">
          @error('email') <p class="err">{{ $message }}</p> @enderror

          <label>How do we know each other?</label>
          <textarea name="note" required maxlength="1000">{{ old('note') }}</textarea>
          @error('note') <p class="err">{{ $message }}</p> @enderror

          <button class="btn" type="submit">Request access</button>
        </form>

        <p class="fine">That last question isn't a formality. This round is raised under an exemption
          that depends on a pre-existing relationship, so your answer in your own words is part of the
          record. Requests from people I don't know will get a polite no rather than a code.</p>
      @endif
    </div>

    <div class="card">
      <h2>Have a code?</h2>
      <p>Enter it to open the proposal. Codes are issued to one person and can be withdrawn.</p>

      <form method="POST" action="{{ route('invest.enter') }}">
        @csrf
        <div class="code">
          <input type="text" name="code" placeholder="Paste your code" required autocomplete="off">
          <button class="btn ghost" type="submit">Open</button>
        </div>
        @error('code') <p class="err">{{ $message }}</p> @enderror
      </form>

      <div style="margin-top:26px;padding-top:20px;border-top:1px solid var(--line)">
        <h3>What's behind it</h3>
        <p style="font-size:14px;margin-top:10px">The full proposal and one-page summary, the two-year
          model and the assumptions under it, and the risks page.</p>
      </div>

      <div style="margin-top:22px;padding-top:20px;border-top:1px solid var(--line)">
        <h3>Lost your code?</h3>
        <p style="font-size:14px;margin-top:10px">Use the request form. Re-issuing is quicker than
          finding the old email.</p>
      </div>
    </div>

  </div>

  <p class="fine">{{ $fine }}</p>
</div></section>

<footer><div class="wrap">intake · intake.works</div></footer>
</body></html>
