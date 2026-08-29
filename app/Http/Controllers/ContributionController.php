<?php

namespace App\Http\Controllers;

// MARKER-CONTRIBUTIONS
use App\Services\ContributionService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class ContributionController extends Controller
{
    public function __construct(private ContributionService $contributions) {}

    public function start(Request $request)
    {
        // Honeypot.
        if (filled($request->input('company_website'))) {
            return redirect()->route('marketing.invest');
        }

        $data = $request->validate([
            'name'   => ['required', 'string', 'max:120'],
            'email'  => ['required', 'email', 'max:190'],
            'amount' => ['required', 'numeric', 'min:5', 'max:10000'],
        ]);

        if (! $this->contributions->isConfigured()) {
            return back()->withErrors(['amount' => 'Card payments are not available right now.']);
        }

        try {
            $url = $this->contributions->start(
                [
                    'name'         => $data['name'],
                    'email'        => $data['email'],
                    'amount_cents' => (int) round($data['amount'] * 100),
                    'ip'           => $request->ip(),
                ],
                route('invest.contribute.thanks'),
                route('marketing.invest') . '#support',
            );
        } catch (\Throwable $e) {
            Log::error('MARKER-CONTRIBUTIONS checkout failed', ['error' => $e->getMessage()]);

            return back()->withErrors(['amount' => 'Something went wrong starting the payment.']);
        }

        return redirect()->away($url);
    }

    /**
     * Anyone can type this URL, so it says thank you and nothing more — it
     * never marks anything paid. Only the verified webhook does that.
     */
    public function thanks()
    {
        return response()
            ->view('invest.contributed')
            ->header('X-Robots-Tag', 'noindex, nofollow');
    }
}
