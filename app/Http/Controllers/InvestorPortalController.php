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
