<?php

namespace App\Services;

// MARKER-CONTRIBUTIONS
use App\Models\BillingSettings;
use App\Models\Contribution;
use Illuminate\Support\Facades\Log;
use Stripe\StripeClient;

/**
 * Contributions are a payment for nothing — no equity, no SAFE, no return.
 * Kept out of StripeBillingService on purpose: that class is about tenant
 * subscriptions, and money paid to Intake Inc for the project is a different
 * concern that should not share code paths with customer billing.
 */
class ContributionService
{
    public const MIN_CENTS = 500;        // $5
    public const MAX_CENTS = 1000000;    // $10,000 — above this, talk to a person

    public const DEFAULT_PRESETS = [25, 100, 250];

    /**
     * MARKER-CONTRIB-UI — the three buttons, from Raise setup.
     *
     * Forgiving on purpose and always returns something: a typo in that field
     * must not leave the public page with no buttons on it. Anything unusable
     * falls back to the defaults rather than rendering an empty row.
     */
    public static function presets(): array
    {
        $raw = (string) \App\Models\RaiseSetting::get('contribution_presets', '');

        $values = collect(preg_split('/[,\s]+/', $raw))
            ->map(fn ($v) => (int) preg_replace('/[^0-9]/', '', (string) $v))
            ->filter(fn ($v) => $v >= 1 && $v <= 10000)
            ->unique()
            ->take(3)
            ->values()
            ->all();

        return $values ?: self::DEFAULT_PRESETS;
    }

    public function isConfigured(): bool
    {
        return (bool) BillingSettings::current()->activeSecretKey();
    }

    private function client(): StripeClient
    {
        $key = BillingSettings::current()->activeSecretKey();

        if (! $key) {
            throw new \RuntimeException('Stripe is not configured');
        }

        return new StripeClient($key);
    }

    /**
     * Create the pending row and the Checkout session.
     *
     * The row exists BEFORE the redirect so an abandoned checkout is visible
     * rather than invisible, and so the webhook has something to match on.
     */
    public function start(array $data, string $successUrl, string $cancelUrl): string
    {
        $contribution = Contribution::create([
            'name'         => $data['name'] ?? null,
            'email'        => $data['email'] ?? null,
            'phone'        => $data['phone'] ?? null,
            'amount_cents' => $data['amount_cents'],
            'note'         => $data['note'] ?? null,
            'status'       => 'pending',
            'ip'           => $data['ip'] ?? null,
        ]);

        $session = $this->client()->checkout->sessions->create([
            'mode'                 => 'payment',
            'customer_email'       => $data['email'] ?? null,
            'client_reference_id'  => (string) $contribution->id,
            'success_url'          => $successUrl,
            'cancel_url'           => $cancelUrl,
            'line_items'           => [[
                'quantity'   => 1,
                'price_data' => [
                    'currency'     => 'usd',
                    'unit_amount'  => $contribution->amount_cents,
                    'product_data' => [
                        'name'        => 'Contribution to Intake',
                        // Said here too, because this is what appears on the
                        // Stripe page and in their receipt.
                        'description' => 'A contribution to the project. This is not an investment: '
                                       . 'it conveys no equity, no ownership and no return.',
                    ],
                ],
            ]],
            'metadata' => [
                'kind'            => 'contribution',
                'contribution_id' => (string) $contribution->id,
            ],
        ], [
            'idempotency_key' => 'contrib-' . $contribution->id,
        ]);

        $contribution->update(['stripe_session_id' => $session->id]);

        Log::info('MARKER-CONTRIBUTIONS checkout started', [
            'contribution' => $contribution->id,
            'amount'       => $contribution->amount_cents,
        ]);

        return $session->url;
    }
}
