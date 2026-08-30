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
<link rel="icon" href="{{ asset('favicon.svg') }}" type="image/svg+xml">
<link rel="icon" href="{{ asset('favicon-32.png') }}" sizes="32x32">
<link rel="apple-touch-icon" href="{{ asset('apple-touch-icon.png') }}">
{{-- MARKER-INVEST-RETURNS — no og:image here on purpose: a personal link
     pasted into a thread would unfurl the round to everyone in it. --}}
<link rel="stylesheet" href="{{ asset('css/fonts.css') }}">
@include('invest._styles')
</head><body>

<nav><div class="wrap">
  <a class="brand" href="/"><img src="{{ asset('icon.svg') }}" alt="" width="26" height="26"> intake</a>
  <span class="who">Invitation only</span>
</div></nav>

{{-- MARKER-INVEST-FULL — same rail and same sections as the personal page. --}}
@php
  $rail = [
    ['#terms', 'Terms', null],
    ['#docs', 'Documents', null],
    ['#s-keep', 'Retention', 's-keep'],
    ['#s-bike', 'Why bike first', 's-bike'],
    ['#s-cap', 'The platform', 's-cap'],
    ['#s-stack', 'What it replaces', 's-stack'],
    ['#s-rev', 'The model', 's-rev'],
    ['#s-cost', 'Cost and margin', 's-cost'],
    ['#s-horiz', 'The horizontal', 's-horiz'],
    ['#s-market', 'The market', 's-market'],
    ['#s-ask', 'The ask', 's-ask'],
    ['#s-risk', 'Risks', 's-risk'],
    ['#interest', 'Commit', null],
  ];
@endphp
@include('invest._rail')

@include('invest._round')

{{-- MARKER-INVEST-CONTEXT --}}
<section><div class="wrap">

  <details class="sec" id="s-keep">
    <summary>Most shops don't market at all <span class="cap">&mdash; and why that is the opportunity</span></summary>
    <div class="body">@include('invest._retention')</div>
  </details>

  <details class="sec" id="s-bike">
    <summary>Why bike first <span class="cap">&mdash; the hardest version of the problem</span></summary>
    <div class="body">@include('invest._bike')</div>
  </details>

  <details class="sec" id="s-cap">
    <summary>The platform <span class="cap">&mdash; one core, and what sits on top</span></summary>
    <div class="body">@include('invest._capability')</div>
  </details>

  <details class="sec" id="s-stack">
    <summary>What it replaces <span class="cap">&mdash; one shop&rsquo;s own invoices</span></summary>
    <div class="body">@include('invest._stack')</div>
  </details>

  <details class="sec" id="s-rev">
    <summary>The model <span class="cap">&mdash; two lines, one set of shops</span></summary>
    <div class="body">@include('invest._model')</div>
  </details>

  <details class="sec" id="s-cost">
    <summary>Cost and margin <span class="cap">&mdash; and the gap this raise does not close</span></summary>
    <div class="body">@include('invest._cost')</div>
  </details>

  <details class="sec" id="s-horiz">
    <summary>The horizontal <span class="cap">&mdash; the same product, sold sideways</span></summary>
    <div class="body">@include('invest._horizontal')</div>
  </details>

  <details class="sec" id="s-market">
    <summary>The market <span class="cap">&mdash; and the ceiling, which is not the plan</span></summary>
    <div class="body">@include('invest._returns')</div>
  </details>

  <details class="sec" id="s-ask">
    <summary>The ask <span class="cap">&mdash; where the $100k goes</span></summary>
    <div class="body">@include('invest._ask')</div>
  </details>

  <details class="sec" id="s-risk">
    <summary>What has to go right <span class="cap">&mdash; stated plainly</span></summary>
    <div class="body">@include('invest._risks')</div>
  </details>

</div></section>

<section id="interest"><div class="wrap">
  {{-- MARKER-SHARED-COMMIT — commit here rather than asking to be set up. --}}
  <p class="sub">Interested?</p>
  <h2>Say what you're thinking, and it's recorded.</h2>
  <p class="lede">Nothing binding — a statement of intent you can change or withdraw right up until the
    paperwork is signed. I'll email you your own page, where the documents, the signature and the funding
    details live.</p>

  @if(session('invest_lead_ok'))
    <div class="ok"><b>Recorded.</b> Your own page is on its way by email — the documents, the
      signature and the funding details all live there. Nothing is binding yet.</div>
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
      <label>Investing as</label>
      <input type="text" name="entity" value="{{ old('entity') }}" maxlength="190"
             placeholder="Leave blank if personally">

      <label>Amount</label>
      <input type="number" name="amount" value="{{ old('amount') }}" min="1" step="1" required
             placeholder="10000">
      @error('amount') <span class="cerr">{{ $message }}</span> @enderror

      <label>Anything you want to ask (optional)</label>
      <input type="text" name="note" value="{{ old('note') }}" maxlength="1000">
      @if($errors->any()) <span class="cerr">{{ $errors->first() }}</span> @endif
      <br><button class="btn" type="submit">Record my commitment</button>
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
