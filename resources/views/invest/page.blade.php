{{-- MARKER-INVEST-V2 — the short gated page. The twelve-page proposal is a
     PDF and stays one; this used to hold the whole deck in markup, which is
     how it ended up quoting a superseded cap while the PDF said otherwise.
     Git history has the old markup if any wording is wanted back. --}}
<!DOCTYPE html>
<html lang="en"><head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Intake — Investment Opportunity</title>
<meta name="robots" content="noindex, nofollow">
<link rel="stylesheet" href="{{ asset('css/fonts.css') }}">
@include('invest._styles')
</head><body>

<nav><div class="wrap">
  <a class="brand" href="/"><img src="{{ asset('icon.svg') }}" alt="" width="26" height="26"> intake</a>
  <span class="who">Invitation only</span>
</div></nav>

@include('invest._round')

{{-- MARKER-INVEST-CONTEXT --}}
@include('invest._context')

<section><div class="wrap">
  <p class="sub">Interested?</p>
  <h2>Tell me and I'll set up your own page.</h2>
  <p class="lede">This link is shared — it shows the round, not you. Commitments, the SAFE and funding
    happen on a page that belongs to one person, which I'll send you.</p>

  @if(session('invest_lead_ok'))
    <div class="ok">Thanks — that's with me. I'll come back to you directly rather than automatically.</div>
  @else
    <form method="POST" action="{{ route('invest.lead', ['token' => $token->token]) }}" style="margin-top:18px">
      @csrf
      <div style="position:absolute;left:-9999px" aria-hidden="true">
        <input type="text" name="company_website" tabindex="-1" autocomplete="off">
      </div>
      <label>Your name</label>
      <input type="text" name="name" value="{{ old('name') }}" required maxlength="120">
      <label>Email</label>
      <input type="email" name="email" value="{{ old('email') }}" required maxlength="190">
      <label>Anything you want to ask (optional)</label>
      <input type="text" name="note" value="{{ old('note') }}" maxlength="1000">
      @if($errors->any()) <span class="cerr">{{ $errors->first() }}</span> @endif
      <br><button class="btn" type="submit">Send</button>
    </form>
  @endif

  <div class="legend">
    <b>Why this page is short.</b> Everything that would make it long is in the proposal above, kept in
    one place so it cannot drift. What is on this page is the round as it stands right now, read from
    the same settings the documents are generated from.
  </div>

  {{-- MARKER-INVEST-MOBILE --}}
  <details class="m">
    <summary>Legal</summary>
    <div class="inner"><p style="font-size:12.5px">Not an offer to sell or a solicitation of an offer to
      buy any security. Any offering is made only by delivery of the offering documents to individually
      qualified persons, and only where lawful. Terms are subject to change until executed. Access to this
      page may be withdrawn.</p></div>
  </details>
</div></section>

<footer><div class="wrap">intake · intake.works</div></footer>
</body></html>
