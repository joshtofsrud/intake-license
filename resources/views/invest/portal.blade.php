{{-- MARKER-INVEST-V2 — one link that carries everything: the same round block
     the gated page shows, then this person's own commitment, signature and
     funding. Two URLs per investor was one too many. --}}
<!DOCTYPE html>
<html lang="en"><head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Intake — {{ $investor->name }}</title>
<meta name="robots" content="noindex, nofollow">
<link rel="stylesheet" href="{{ asset('css/fonts.css') }}">
@include('invest._styles')
</head><body>

<nav><div class="wrap">
  <a class="brand" href="/"><img src="{{ asset('icon.svg') }}" alt="" width="26" height="26"> intake</a>
  <span class="who"><b>{{ $investor->name }}</b> · your link</span>
</div></nav>

@include('invest._round')

<section><div class="wrap">
  <p class="sub">Your position</p>

  @if(session('commit_ok'))
    <div class="ok"><b>Recorded.</b> Nothing is binding yet — this is a statement of intent, and it is
      yours to change until the paperwork is signed.</div>
  @endif

  <div class="steps" style="margin-top:18px">

    {{-- 1 · commitment --}}
    <div class="step {{ $investor->committed_at ? 'done' : '' }}">
      <div class="stepn">{{ $investor->committed_at ? '✓' : '1' }}</div>
      <div class="stepb">
        <h3>Commitment
          @if($investor->committed_at)
            <span class="pill on">${{ number_format($investor->amount) }}{{ $investor->entity ? ' · ' . $investor->entity : ' · personally' }}</span>
          @endif
        </h3>

        @if($investor->signed_at || $investor->declined_at)
          <p>Locked now that the paperwork has moved on. If something needs changing, reply to any of my
            emails rather than looking for a button here.</p>
        @else
          <p>A statement of intent, not a contract. Change or withdraw it here at any time until the
            document is signed.</p>

          <form method="POST" action="{{ route('invest.portal.commit', $investor->token) }}">
            @csrf
            <label>Your name</label>
            <input type="text" name="name" value="{{ old('name', $investor->name) }}" maxlength="190">
            <label>Investing as</label>
            <input type="text" name="entity" value="{{ old('entity', $investor->entity) }}" maxlength="190"
                   placeholder="Leave blank if personally">
            <label>Amount</label>
            <input type="number" name="amount" value="{{ old('amount', $investor->amount ?: '') }}"
                   min="1" step="1" required placeholder="10000">
            @error('amount') <span class="cerr">{{ $message }}</span> @enderror
            <br><button class="btn ghost" type="submit">
              {{ $investor->committed_at ? 'Update' : 'Record my commitment' }}</button>
          </form>
        @endif
      </div>
    </div>

    {{-- 2 · signature --}}
    <div class="step {{ $investor->signed_at ? 'done' : ($investor->committed_at ? '' : 'wait') }}">
      <div class="stepn">{{ $investor->signed_at ? '✓' : '2' }}</div>
      <div class="stepb">
        <h3>Signature
          @if($investor->signed_at)
            <span class="pill on">Signed {{ $investor->signed_at->toFormattedDateString() }}</span>
          @elseif($investor->committed_at)
            <span class="pill amber">Waiting on the document</span>
          @else
            <span class="pill">After a commitment</span>
          @endif
        </h3>
        <p>The SAFE goes out through an e-signature service, which handles identity, the audit trail and
          the countersigned copy. This page records the outcome; it never captures a signature itself, and
          never marks a document executed on its own say-so.</p>
        @if($documents->isNotEmpty())
          <div class="docs" style="margin-top:14px">
            @foreach($documents as $doc)
              <a class="doc" href="{{ route('invest.portal.doc', ['token' => $investor->token, 'documentId' => $doc->id]) }}">
                <b>{{ $doc->label }} &rarr;</b>
                <span>{{ $doc->signed_at ? 'Signed ' . $doc->signed_at->toFormattedDateString() : 'Download' }}</span>
              </a>
            @endforeach
          </div>
        @endif
      </div>
    </div>

    {{-- 3 · funds --}}
    <div class="step {{ $investor->funded_at ? 'done' : ($investor->signed_at ? '' : 'wait') }}">
      <div class="stepn">{{ $investor->funded_at ? '✓' : '3' }}</div>
      <div class="stepb">
        <h3>Funds
          @if($investor->funded_at)
            <span class="pill on">Received {{ $investor->funded_at->toFormattedDateString() }}</span>
          @elseif($investor->signed_at)
            <span class="pill amber">Due</span>
          @else
            <span class="pill">Unlocks once executed</span>
          @endif
        </h3>

        @if($investor->signed_at && ! $investor->funded_at && $wire['bank'])
          <p>Wire or ACH, referencing your name.</p>
          <div class="wire">
            <div><span>Bank</span>{{ $wire['bank'] }}</div>
            <div><span>Account</span>{{ $wire['account'] }}</div>
            <div><span>Routing</span>{{ $wire['routing'] }}</div>
            <div><span>Reference</span>{{ $wire['reference'] ?: $investor->name }}</div>
          </div>
          <p class="fine" style="border:0;padding:0;margin-top:12px">
            <b>These details will never change.</b> If you get an email saying they have, it is not from
            me — call before sending anything.</p>
        @elseif(! $investor->signed_at)
          <p>Details appear here once the document is executed. Nothing is due before then.</p>
        @endif

        <p class="fine" style="border:0;padding:0;margin-top:12px">
          <b>No card, deliberately.</b> Card networks don't permit securities purchases, and a chargeback
          can't be unwound once a SAFE is signed. Bank transfer only, at any amount.</p>
      </div>
    </div>

  </div>

  <div class="legend">
    <b>What this page does and doesn't do.</b> It shows you the documents and records where you are. It
    does not take your money, hold your bank details, or countersign anything — the transfer happens at
    your bank and the signature at the e-signature service. If a status here looks stale, that service is
    the truth and this is a mirror of it.
  </div>

  <p class="fine">Not an offer to sell or a solicitation of an offer to buy any security. Any offering is
    made only by delivery of the offering documents to individually qualified persons, and only where
    lawful. Terms are subject to change until executed. This link is personal to you and may be withdrawn.</p>
</div></section>

<footer><div class="wrap">
  intake · intake.works — questions? Just reply to any of my emails.
</div></footer>
</body></html>
