<?php

namespace App\Http\Controllers;

use App\Models\InvestLead;
use App\Models\Investor;          // MARKER-INVEST-LANDING
use App\Models\RaiseSetting;      // MARKER-INVEST-LANDING
use App\Models\InvestToken;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

// MARKER-INVEST-SITE
class InvestController extends Controller
{
    /**
     * MARKER-INVEST-LANDING — the public door at /invest.
     *
     * Deliberately thin: what the round is, a way to ask, a way in. The
     * numbers shown are the two that describe the instrument, both read
     * from the round settings so they cannot drift from the model behind
     * the gate. Nothing about how much has been committed appears here.
     */
    public function landing()
    {
        return response()->view('invest.landing', [
            // MARKER-INVEST-V2 — landing_headline_v2: the default changed with the
            // page, and reusing the old key would have silently kept the old line
            // for anyone who never edited it.
            'headline'   => RaiseSetting::get('landing_headline',
                'Shops run four systems<br>that don\'t <span class="l">talk to each other</span>.'),
            'lede'       => RaiseSetting::get('landing_lede',
                'A bike shop books a repair in one tool, rings the sale in another, tracks the part on a '
                . "supplier's website, and emails the customer from a third. None of them share a customer "
                . 'record, and staff reconcile the gaps by hand every evening. Intake is all of it as one '
                . 'record — and it is running real work today.'),
            'fine'       => RaiseSetting::get('landing_fine',
                'This page is not an offer to sell or a solicitation of an offer to buy any security. '
                . 'Any offering is made only to individually qualified persons, by delivery of the '
                . 'offering documents, and only where lawful. Information provided on request.'),
            // MARKER-INVEST-V2 — the public page states no terms, so it is handed
            // none. Only whether the round is open, which changes what it says.
            'isOpen'     => RaiseSetting::get('round_status', 'open') === 'open',
            // MARKER-CONTRIB-UI — the contribute presets, set in Raise setup.
            'presets'    => \App\Services\ContributionService::presets(),
        ])->header('X-Robots-Tag', 'noindex, nofollow, noarchive');
    }

    /**
     * MARKER-INVEST-LANDING — someone asking to be let in.
     *
     * Writes a lead with no token attached, which is what distinguishes a
     * request from a lead left on the gated page. Nothing is sent back but
     * an acknowledgement: the code is issued by hand, from Raise admin.
     */
    public function requestAccess(Request $request)
    {
        // Honeypot: real people never fill this in.
        if (filled($request->input('company_website'))) {
            return back()->with('invest_request_ok', true);
        }

        $data = $request->validate([
            'name'  => ['required', 'string', 'max:120'],
            'email' => ['required', 'email', 'max:190'],
            'note'  => ['required', 'string', 'max:1000'],
        ], [
            'note.required' => 'Please say how we know each other — access is issued by introduction.',
        ]);

        InvestLead::create([
            'invest_token_id' => null,
            'name'            => $data['name'],
            'email'           => $data['email'],
            'note'            => $data['note'],
            'ip'              => $request->ip(),
        ]);

        try {
            \Illuminate\Support\Facades\Mail::raw(
                "Access request from " . $data['name'] . " <" . $data['email'] . ">\n\n"
                . "How they say you know each other:\n" . $data['note']
                . "\n\nIssue or decline it in Raise admin.",
                function ($mail) {
                    // MARKER-MAIL-FROM — never the framework placeholder: an access
                    // request landing at example.com is a request nobody sees.
                    $to = RaiseSetting::get('notify_email')
                        ?: \App\Models\PlatformSettings::fromAddress();
                    $mail->to($to)->subject('Intake — someone asked for the proposal');
                }
            );
        } catch (\Throwable $e) {
            Log::error('MARKER-INVEST-LANDING request notify failed', ['error' => $e->getMessage()]);
        }

        // MARKER-MAIL-FROM — the lead row is written above regardless, so a
        // notification that could not be addressed loses the alert, not the
        // request itself. Worth saying out loud in the log.
        if (! (RaiseSetting::get('notify_email') ?: \App\Models\PlatformSettings::fromAddress())) {
            Log::warning('MARKER-MAIL-FROM no notify address — access request saved but nobody was told', [
                'email' => $data['email'],
            ]);
        }

        Log::info('MARKER-INVEST-LANDING access requested', ['email' => $data['email']]);

        return back()->with('invest_request_ok', true);
    }

    public function show(string $token)
    {
        $record = $this->resolve($token);

        $record->increment('views');
        $record->forceFill(['last_viewed_at' => now()])->save();

        // MARKER-INVEST-LIVE — the proposal quotes the round as it stands now.
        $target = (int) RaiseSetting::get('target', (string) Investor::TARGET);
        $cap    = (int) RaiseSetting::get('cap', (string) Investor::CAP);

        $investors = Investor::whereNull('declined_at')->get();

        return response()->view('invest.page', [
            'token'      => $record,
            'target'     => $target,
            'cap'        => $cap,
            'instrument' => RaiseSetting::get('instrument', 'Post-money SAFE'),
            'equity'     => $cap > 0 ? round($target / $cap * 100, 1) : 0,
            // Signed-and-funded is money in the account. Committed is a
            // statement of intent. Showing one number for both would flatter.
            'funded'     => (int) $investors->sum('amount_received'),
            'committed'  => (int) $investors->whereNotNull('committed_at')->sum('amount'),
            'showBar'    => RaiseSetting::get('show_progress', '1') === '1',
            // MARKER-INVEST-V2 — the round block is shared, so it is handed the
            // documents and a way to build their URLs rather than knowing which
            // surface it is rendering on.
            'docs'       => \App\Support\InvestDocuments::listed(),
            'docUrl'     => fn (string $slug) => route('invest.doc', ['token' => $record->token, 'doc' => $slug]),
        ])->header('X-Robots-Tag', 'noindex, nofollow, noarchive');
    }

    /** Serves the proposal PDFs from storage so they are not publicly listable. */
    public function document(string $token, string $doc)
    {
        $this->resolve($token);

        // MARKER-INVEST-V2 — one map, shared with the portal.
        $path = \App\Support\InvestDocuments::path($doc);
        abort_unless($path, 404);

        return response()->file($path, [
            'Content-Type'  => 'application/pdf',
            'X-Robots-Tag'  => 'noindex, nofollow, noarchive',
        ]);
    }

    public function lead(Request $request, string $token)
    {
        $record = $this->resolve($token);

        // Honeypot: real people never fill this in.
        if (filled($request->input('company_website'))) {
            return back()->with('invest_lead_ok', true);
        }

        $data = $request->validate([
            'name'   => ['required', 'string', 'max:120'],
            'email'  => ['required', 'email', 'max:190'],
            'entity' => ['nullable', 'string', 'max:190'],
            'amount' => ['required', 'numeric', 'min:1', 'max:100000000'],
            'note'   => ['nullable', 'string', 'max:1000'],
        ], [
            'amount.required' => 'How much were you thinking? It is not binding — you can change it.',
        ]);

        InvestLead::create([
            'invest_token_id' => $record->id,
            'name'            => $data['name'],
            'email'           => $data['email'],
            'note'            => $data['note'] ?? null,
            'ip'              => $request->ip(),
        ]);

        // MARKER-SHARED-COMMIT — the commitment creates the record, and the
        // record IS their page. An existing investor on the same email is
        // updated rather than duplicated, so a second visit does not produce a
        // second cap-table line.
        $investor = Investor::firstOrNew(['email' => $data['email']]);

        $isNew = ! $investor->exists;

        $investor->fill([
            'name'   => $data['name'] ?: $investor->name,
            'entity' => $data['entity'] ?: $investor->entity,
            'amount' => (int) $data['amount'],
        ]);

        if ($isNew) {
            $investor->self_declared = true;
        }

        $investor->committed_at = $investor->committed_at ?: now();
        $investor->save();

        \App\Models\InvestorEvent::log(
            $investor->id,
            $isNew ? 'committed' : 'commitment_changed',
            'Stated $' . number_format($investor->amount) . ' from the shared link'
                . ($data['note'] ? ' — "' . $data['note'] . '"' : '')
        );

        try {
            \App\Services\InvestorMessenger::send('commitment', $investor);
        } catch (\Throwable $e) {
            Log::error('MARKER-SHARED-COMMIT confirmation failed', ['error' => $e->getMessage()]);
        }

        Log::info('MARKER-SHARED-COMMIT commitment from the shared link', [
            'email' => $data['email'], 'amount' => $investor->amount, 'new' => $isNew,
        ]);

        return back()->with('invest_lead_ok', true);
    }

    private function resolve(string $token): InvestToken
    {
        $record = InvestToken::where('token', $token)->first();

        if (! $record || ! $record->is_active) {
            abort(SymfonyResponse::HTTP_NOT_FOUND);
        }

        return $record;
    }
}
