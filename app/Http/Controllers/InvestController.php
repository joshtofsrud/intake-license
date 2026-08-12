<?php

namespace App\Http\Controllers;

use App\Models\InvestLead;
use App\Models\InvestToken;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

// MARKER-INVEST-SITE
class InvestController extends Controller
{
    public function show(string $token)
    {
        $record = $this->resolve($token);

        $record->increment('views');
        $record->forceFill(['last_viewed_at' => now()])->save();

        return response()
            ->view('invest.page', ['token' => $record])
            ->header('X-Robots-Tag', 'noindex, nofollow, noarchive');
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

        abort_unless(isset($files[$doc]), 404);

        $path = storage_path('app/' . $files[$doc]);
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
