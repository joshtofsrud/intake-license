#!/usr/bin/env bash
set -euo pipefail

# apply-raise-portal.sh — MARKER-RAISE-PORTAL  (patch 3 of 3)
# The investor portal: one investor sees their own position, documents and wire details. No login.
# Requires MARKER-RAISE-MESSAGES.

echo "==> checking repo root"
test -f artisan || { echo "run this from the intake-license repo root"; exit 1; }

grep -q "MARKER-RAISE-MESSAGES" app/Filament/Pages/Raise.php || { echo "apply-raise-messages.sh must be applied first"; exit 1; }

if grep -q "MARKER-RAISE-PORTAL" routes/web.php; then
  echo "MARKER-RAISE-PORTAL already present — nothing to do."
  exit 0
fi

mkdir -p app/Http/Controllers resources/views/invest

echo "==> portal controller"
cat > app/Http/Controllers/InvestorPortalController.php <<'PCEOF'
<?php

namespace App\Http\Controllers;

use App\Models\Investor;
use App\Models\InvestorDocument;
use App\Models\InvestorEvent;
use App\Models\RaiseSetting;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

// MARKER-RAISE-PORTAL
class InvestorPortalController extends Controller
{
    public function show(string $token)
    {
        $investor = $this->resolve($token);

        if (! $investor->opened_at) {
            $investor->forceFill(['opened_at' => now()])->save();
            InvestorEvent::log($investor->id, 'opened', 'Opened their portal for the first time');
        }

        $investor->forceFill(['portal_seen_at' => now()])->save();

        return response()->view('invest.portal', [
            'investor'  => $investor,
            'documents' => $investor->documents()->where('visible_to_investor', true)->get(),
            'wire'      => RaiseSetting::wireInstructions(),
            'cap'       => Investor::CAP,
        ])->header('X-Robots-Tag', 'noindex, nofollow, noarchive');
    }

    public function document(string $token, int $documentId)
    {
        $investor = $this->resolve($token);

        $doc = InvestorDocument::where('investor_id', $investor->id)
            ->where('visible_to_investor', true)
            ->findOrFail($documentId);

        InvestorEvent::log($investor->id, 'document_downloaded', 'Downloaded: ' . $doc->label);

        return Storage::disk('local')->download($doc->path, $doc->original_name);
    }

    private function resolve(string $token): Investor
    {
        $investor = Investor::where('token', $token)->first();

        abort_unless($investor, SymfonyResponse::HTTP_NOT_FOUND);

        return $investor;
    }
}
PCEOF

echo "==> portal view"
cat > resources/views/invest/portal.blade.php <<'PVEOF'
<!DOCTYPE html>
<html lang="en"><head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex,nofollow,noarchive">
<title>Intake — your position</title>
<!-- MARKER-RAISE-PORTAL -->
<style>
:root{--bg:#0B0F0C;--panel:#111710;--line:#1F2A1E;--text:#F2F4EE;--body:#8D9A8B;--dim:#5F6A5E;--lime:#BEF264}
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
PVEOF

echo "==> routes"
cat >> routes/web.php <<'RTEOF'

// MARKER-RAISE-PORTAL — one investor's own position, no login, token in the URL
Route::get('/invest/i/{token}', [\App\Http\Controllers\InvestorPortalController::class, 'show'])
    ->name('invest.portal');
Route::get('/invest/i/{token}/doc/{documentId}', [\App\Http\Controllers\InvestorPortalController::class, 'document'])
    ->whereNumber('documentId')
    ->name('invest.portal.doc');
RTEOF

echo "==> document-ready notification"
python3 - <<'PYEOF'
import io
p = "app/Filament/Pages/InvestorRecord.php"
src = io.open(p, encoding="utf-8").read()
assert "MARKER-RAISE-PORTAL" not in src

anchor = """        InvestorEvent::log($investor->id, 'document', 'Document added: ' . $this->docLabel);"""
assert src.count(anchor) == 1

src = src.replace(anchor, anchor + """

        // MARKER-RAISE-PORTAL — the document is now visible on their page, so tell them
        \\App\\Services\\InvestorMessenger::send('document_ready', $investor);""", 1)

io.open(p, "w", encoding="utf-8").write(src)
print("   document upload now notifies the investor")
PYEOF

echo ""
echo "MARKER-RAISE-PORTAL applied."
echo "  each investor's private page: /invest/i/<their token>"
echo "  the link is on their record page in master admin"
echo "  wire details appear only once they are marked signed and not yet funded"
