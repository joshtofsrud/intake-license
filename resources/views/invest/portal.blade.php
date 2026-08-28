<!DOCTYPE html>
<html lang="en"><head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex,nofollow,noarchive">
<title>Intake — your position</title>
<!-- MARKER-RAISE-PORTAL -->
<style>
:root{
  /* MARKER-INVEST-PALETTE — the marketing site's neutrals, not a second
     scheme. Change them there and mirror here; an investor compares
     this page with intake.works within about a minute. */
  --bg:#0c0c0c;--panel:#141414;--line:rgba(255,255,255,.08);--text:#f0f0f0;--body:rgba(255,255,255,.45);--dim:rgba(255,255,255,.28);--lime:#BEF264}
*{box-sizing:border-box;margin:0;padding:0}
body{background:var(--bg);color:var(--text);font:16px/1.6 ui-sans-serif,system-ui,-apple-system,"Segoe UI",sans-serif;-webkit-font-smoothing:antialiased}
.wrap{max-width:760px;margin:0 auto;padding:48px 24px 80px}
.brand{font-size:20px;font-weight:700;letter-spacing:-.5px;margin-bottom:40px}
.eyebrow{font-size:11px;font-weight:600;letter-spacing:2.5px;text-transform:uppercase;color:var(--lime);margin-bottom:12px}
h1{font-size:32px;font-weight:800;letter-spacing:-1px;line-height:1.15;margin-bottom:10px}
p{color:var(--body);margin-bottom:14px}
.sub{font-size:11px;font-weight:600;letter-spacing:2px;text-transform:uppercase;color:var(--dim);border-bottom:1px solid var(--line);padding-bottom:8px;margin:36px 0 16px}
.cards{display:flex;gap:14px;flex-wrap:wrap}
.card{flex:1 1 180px;background:var(--panel);border:1px solid var(--line);border-radius:12px;padding:18px}
.card .n{font-size:26px;font-weight:800;color:var(--lime);letter-spacing:-1px;line-height:1}
.card .n.w{color:var(--text)}
.card .k{font-size:10px;font-weight:600;letter-spacing:1.6px;text-transform:uppercase;color:var(--dim);margin-top:8px}
.row{display:flex;justify-content:space-between;gap:16px;padding:12px 0;border-bottom:1px solid var(--line)}
.row span:first-child{color:var(--body)}
.steps{list-style:none}
.steps li{padding:10px 0 10px 26px;position:relative;color:var(--dim);border-bottom:1px solid var(--line)}
.steps li:before{content:"○";position:absolute;left:0}
.steps li.done{color:var(--text)}
.steps li.done:before{content:"●";color:var(--lime)}
.steps small{display:block;color:var(--dim);font-size:12px}
a.doc{display:flex;justify-content:space-between;gap:16px;padding:12px 0;border-bottom:1px solid var(--line);color:var(--text);text-decoration:none}
a.doc:hover{color:var(--lime)}
.warn{background:var(--panel);border:1px solid var(--line);border-left:3px solid var(--lime);border-radius:8px;padding:14px 16px;margin-top:16px}
.warn p{margin:0;font-size:14px}
.wire{font-family:ui-monospace,SFMono-Regular,Menlo,monospace;font-size:14px}
footer{margin-top:56px;padding-top:20px;border-top:1px solid var(--line);font-size:12px;color:var(--dim);display:flex;justify-content:space-between;gap:16px;flex-wrap:wrap}

/* MARKER-RAISE-INVITE */
.commitform{display:grid;gap:12px;margin-top:14px;max-width:420px}
.commitform label{display:block;font-size:11px;letter-spacing:1.5px;text-transform:uppercase;color:var(--dim);font-weight:600}
.commitform input{width:100%;margin-top:6px;background:var(--bg);border:1px solid var(--line);border-radius:8px;
  color:var(--text);font-family:inherit;font-size:15px;padding:10px 12px;outline:none}
.commitform input:focus{border-color:var(--lime-line)}
.commitform button{justify-self:start;background:var(--lime);color:#0c0c0c;border:0;border-radius:8px;
  font-family:inherit;font-size:14px;font-weight:700;padding:11px 20px;cursor:pointer}
.cerr{color:#FCA5A5;font-size:13px}
.cnote{font-size:12.5px;color:var(--dim);margin-top:12px;line-height:1.6;max-width:60ch}
</style>
</head><body>
<div class="wrap">

  <div class="brand">intake</div>

  <div class="eyebrow">Your position</div>
  <h1>{{ $investor->name }}</h1>
  <p>This page is yours. It shows your own commitment and paperwork, nothing about anyone else in the round.</p>

  <div class="cards">
    <div class="card">
      <div class="n">${{ number_format($investor->amount) }}</div>
      <div class="k">Committed</div>
    </div>
    <div class="card">
      <div class="n w">{{ $investor->percent }}%</div>
      <div class="k">At the ${{ number_format($cap) }} cap</div>
    </div>
    <div class="card">
      <div class="n w">{{ $investor->status }}</div>
      <div class="k">Status</div>
    </div>
  </div>

  {{-- MARKER-RAISE-INVITE — an invited person has nothing to show yet, so ask
       rather than printing a row of zeros at them. --}}
  @if (! $investor->signed_at && ! $investor->declined_at)
    <div class="sub">{{ $investor->committed_at ? 'Change your commitment' : 'Your commitment' }}</div>

    @if (session('commit_ok'))
      <div class="warn"><p><strong>Recorded.</strong> Nothing is binding yet — this is a statement of
        intent, and you can change it here until the paperwork is signed.</p></div>
    @endif

    <form method="POST" action="{{ route('invest.portal.commit', $investor->token) }}" class="commitform">
      @csrf
      <label>Your name
        <input type="text" name="name" value="{{ old('name', $investor->name) }}" maxlength="190"></label>
      <label>Investing as (leave blank if personally)
        <input type="text" name="entity" value="{{ old('entity', $investor->entity) }}" maxlength="190"
               placeholder="Entity name"></label>
      <label>Amount
        <input type="number" name="amount" value="{{ old('amount', $investor->amount ?: '') }}"
               min="1" step="1" required placeholder="10000"></label>
      <button type="submit">{{ $investor->committed_at ? 'Update' : 'Record my commitment' }}</button>
      @error('amount') <span class="cerr">{{ $message }}</span> @enderror
    </form>

    <p class="cnote">This commits you to nothing. It tells me what the round looks like before anything
      is papered, and it is yours to change or withdraw until the document is signed.</p>
  @endif

  <div class="sub">Where things stand</div>
  <ul class="steps">
    <li class="{{ $investor->committed_at ? 'done' : '' }}">Commitment recorded
      <small>{{ $investor->committed_at?->toFormattedDateString() ?: 'Not yet' }}</small></li>
    <li class="{{ $investor->signed_at ? 'done' : '' }}">Paperwork signed on both sides
      <small>{{ $investor->signed_at?->toFormattedDateString() ?: 'Not yet' }}</small></li>
    <li class="{{ $investor->funded_at ? 'done' : '' }}">Funds received
      <small>{{ $investor->funded_at?->toFormattedDateString() ?: 'Not yet' }}</small></li>
  </ul>

  @if ($documents->isNotEmpty())
    <div class="sub">Your documents</div>
    @foreach ($documents as $doc)
      <a class="doc" href="{{ route('invest.portal.doc', ['token' => $investor->token, 'documentId' => $doc->id]) }}">
        <span>{{ $doc->label }}{{ $doc->signed_at ? ' · signed' : '' }}</span>
        <span>Download</span>
      </a>
    @endforeach
  @endif

  @if ($investor->signed_at && ! $investor->funded_at && $wire['bank'])
    <div class="sub">Wire instructions</div>
    <div class="row wire"><span>Bank</span><span>{{ $wire['bank'] }}</span></div>
    <div class="row wire"><span>Account</span><span>{{ $wire['account'] }}</span></div>
    <div class="row wire"><span>Routing</span><span>{{ $wire['routing'] }}</span></div>
    <div class="row wire"><span>Reference</span><span>{{ $wire['reference'] ?: $investor->name }}</span></div>
    <div class="warn"><p><strong>These details will never change.</strong> If you get an email saying they have,
      it did not come from Intake. Call before you act on it.</p></div>
  @endif

  <div class="sub">Questions</div>
  <p>Reply to any message from Josh, or write to <a href="mailto:josh@intake.works" style="color:var(--lime)">josh@intake.works</a>.
    A SAFE in a pre-revenue company can go to zero — take it to your own advisor before you sign anything.</p>

  <footer>
    <span>Intake · intake.works</span>
    <span>Private to {{ $investor->name }}</span>
  </footer>

</div>
</body></html>
