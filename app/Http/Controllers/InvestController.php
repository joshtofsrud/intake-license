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
        $target = (int) RaiseSetting::get('target', (string) Investor::TARGET);
        $cap    = (int) RaiseSetting::get('cap', (string) Investor::CAP);

        return response()->view('invest.landing', [
            'headline'   => RaiseSetting::get('landing_headline',
                'For businesses that take appointments, sell retail and teach classes.'),
            'lede'       => RaiseSetting::get('landing_lede',
                'Point of sale, service work orders, scheduling, inventory and customer retention in '
                . 'one platform, built for independent bike shops first. It is live in production '
                . 'today, with a founding shop converting its full point of sale.'),
            'stageLabel' => RaiseSetting::get('landing_stage_label', 'Pre-revenue'),
            'stageSub'   => RaiseSetting::get('landing_stage_sub', 'Product live in production'),
            'fine'       => RaiseSetting::get('landing_fine',
                'This page is not an offer to sell or a solicitation of an offer to buy any security. '
                . 'Any offering is made only to individually qualified persons, by delivery of the '
                . 'offering documents, and only where lawful. Information provided on request.'),
            'instrument' => RaiseSetting::get('instrument', 'Post-money SAFE'),
            'target'     => $target,
            'cap'        => $cap,
            'isOpen'     => RaiseSetting::get('round_status', 'open') === 'open',
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
                    $to = RaiseSetting::get('notify_email') ?: config('mail.from.address');
                    $mail->to($to)->subject('Intake — someone asked for the proposal');
                }
            );
        } catch (\Throwable $e) {
            Log::error('MARKER-INVEST-LANDING request notify failed', ['error' => $e->getMessage()]);
        }

        Log::info('MARKER-INVEST-LANDING access requested', ['email' => $data['email']]);

        return back()->with('invest_request_ok', true);
    }

    /** MARKER-INVEST-LANDING — code entry. Wrong codes say nothing useful. */
    public function enter(Request $request)
    {
        $code = trim((string) $request->input('code'));

        $record = InvestToken::where('token', $code)->first();

        if (! $record || ! $record->is_active) {
            return back()->withErrors(['code' => 'That code isn\'t recognised, or it has been withdrawn.']);
        }

        return redirect()->route('invest.show', $record->token);
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
        ])->header('X-Robots-Tag', 'noindex, nofollow, noarchive');
    }

    /** Serves the proposal PDFs from storage so they are not publicly listable. */
    public function document(string $token, string $doc)
    {
        $this->resolve($token);

        $files = [
            'proposal'       => 'invest/Intake-Investment-Opportunity.pdf',
            'proposal-light' => 'invest/Intake-Investment-Opportunity-Light.pdf',
            'summary'        => 'invest/Intake-One-Page-Summary.pdf',
            'summary-light'  => 'invest/Intake-One-Page-Summary-Light.pdf',
        ];

        // MARKER-RAISE-SETUP — uploaded documents win over the shipped filenames.
        $uploaded = \App\Models\InvestDocument::where('slug', $doc)->where('is_active', true)->first();

        abort_unless($uploaded || isset($files[$doc]), 404);

        $path = storage_path('app/' . ($uploaded->path ?? $files[$doc]));
        abort_unless(is_file($path), 404);

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
            'name'  => ['required', 'string', 'max:120'],
            'email' => ['required', 'email', 'max:190'],
            'note'  => ['nullable', 'string', 'max:1000'],
        ]);

        InvestLead::create([
            'invest_token_id' => $record->id,
            'name'            => $data['name'],
            'email'           => $data['email'],
            'note'            => $data['note'] ?? null,
            'ip'              => $request->ip(),
        ]);

        // MARKER-RAISE-MESSAGES — welcome the lead without creating an investor record
        try {
            \Illuminate\Support\Facades\Mail::raw(
                "Hi " . $data['name'] . ",\n\nThanks for leaving your details. I'll be in touch directly — if you have questions before then, just reply to this message.\n\nJosh",
                function ($mail) use ($data) {
                    $mail->to($data['email'], $data['name'])->subject('Thanks for the interest in Intake');
                }
            );
        } catch (\Throwable $e) {
            Log::error('MARKER-RAISE-MESSAGES lead welcome failed', ['error' => $e->getMessage()]);
        }

        Log::info('MARKER-INVEST-SITE lead captured', ['email' => $data['email'], 'token_id' => $record->id]);

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
