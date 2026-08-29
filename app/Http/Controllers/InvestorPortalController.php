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

        // MARKER-INVEST-V2 — the portal now carries the round as well as the
        // person, so an investor has one link rather than two.
        $target = Investor::target();
        $cap    = Investor::cap();
        $all    = Investor::whereNull('declined_at')->get();

        return response()->view('invest.portal', [
            'investor'   => $investor,
            'documents'  => $investor->documents()->where('visible_to_investor', true)->get(),
            'wire'       => RaiseSetting::wireInstructions(),
            'cap'        => $cap,
            'target'     => $target,
            'instrument' => RaiseSetting::get('instrument', 'Post-money SAFE'),
            'equity'     => $cap > 0 ? round($target / $cap * 100, 1) : 0,
            'funded'     => (int) $all->sum('amount_received'),
            'committed'  => (int) $all->whereNotNull('committed_at')->sum('amount'),
            'showBar'    => RaiseSetting::get('show_progress', '1') === '1',
            'docs'       => \App\Support\InvestDocuments::listed(),
            'docUrl'     => fn (string $slug) => route('invest.portal.proposal', [
                'token' => $investor->token, 'doc' => $slug,
            ]),
        ])->header('X-Robots-Tag', 'noindex, nofollow, noarchive');
    }

    /**
     * MARKER-RAISE-INVITE — the investor states their own commitment.
     *
     * The same state the admin form sets: an amount and committed_at. It
     * stays editable until the paperwork is signed, because until then
     * nothing about it is binding and pretending otherwise is worse.
     */
    public function commit(\Illuminate\Http\Request $request, string $token)
    {
        $investor = $this->resolve($token);

        if ($investor->signed_at || $investor->declined_at) {
            return back();
        }

        $data = $request->validate([
            'amount' => ['required', 'numeric', 'min:1', 'max:100000000'],
            'entity' => ['nullable', 'string', 'max:190'],
            'name'   => ['nullable', 'string', 'max:190'],
        ]);

        $was = (int) $investor->amount;

        $investor->forceFill([
            'name'         => $data['name'] ?: $investor->name,
            'entity'       => $data['entity'] ?: null,
            'amount'       => (int) $data['amount'],
            'committed_at' => $investor->committed_at ?: now(),
        ])->save();

        InvestorEvent::log(
            $investor->id,
            $was ? 'commitment_changed' : 'committed',
            $was
                ? 'Changed their commitment from $' . number_format($was) . ' to $' . number_format($investor->amount)
                : 'Stated a commitment of $' . number_format($investor->amount)
        );

        if (! $was) {
            \App\Services\InvestorMessenger::send('commitment', $investor);
        }

        return back()->with('commit_ok', true);
    }

    /** MARKER-INVEST-V2 — the round's shared documents, served on this link too. */
    public function proposal(string $token, string $doc)
    {
        $investor = $this->resolve($token);

        $path = \App\Support\InvestDocuments::path($doc);
        abort_unless($path, 404);

        InvestorEvent::log($investor->id, 'document_downloaded', 'Opened the ' . $doc);

        return response()->file($path, [
            'Content-Type' => 'application/pdf',
            'X-Robots-Tag' => 'noindex, nofollow, noarchive',
        ]);
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
