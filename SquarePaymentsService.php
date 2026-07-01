<?php

namespace App\Services\Tenant;

use App\Models\Tenant\Tenant;
use Illuminate\Support\Facades\Http;

/**
 * SquarePaymentsService — tenant-connected Square (paste-token), the parallel to
 * DirectPaymentsService (Stripe). Each tenant pastes their own Square credentials,
 * which live in $tenant->settings (no dedicated columns, same as the Stripe bridge):
 *
 *   square_payments_mode                  sandbox | production
 *   square_sandbox_app_id                 Application ID (sandbox)
 *   square_sandbox_location_id            Location ID    (sandbox)
 *   square_sandbox_access_token           Access token   (sandbox)
 *   square_production_app_id / _location_id / _access_token   (production)
 *   square_webhook_signature_key          for webhook signature verification
 *
 * Square's REST API is called directly via the HTTP client — no SDK dependency.
 * This file is the connection foundation: mode/credential accessors + a
 * verifyConnection() check. Charge/refund/list methods land with the payment flow.
 */
class SquarePaymentsService
{
    public function __construct(protected Tenant $tenant) {}

    public function mode(): string
    {
        $s = $this->tenant->settings ?? [];
        return ($s['square_payments_mode'] ?? 'sandbox') === 'production' ? 'production' : 'sandbox';
    }

    public function appId(): ?string
    {
        $s = $this->tenant->settings ?? [];
        return $s['square_' . $this->mode() . '_app_id'] ?? null;
    }

    public function locationId(): ?string
    {
        $s = $this->tenant->settings ?? [];
        return $s['square_' . $this->mode() . '_location_id'] ?? null;
    }

    public function accessToken(): ?string
    {
        $s = $this->tenant->settings ?? [];
        return $s['square_' . $this->mode() . '_access_token'] ?? null;
    }

    public function webhookSignatureKey(): ?string
    {
        $s = $this->tenant->settings ?? [];
        return $s['square_webhook_signature_key'] ?? null;
    }

    /**
     * Square's API host differs by environment (the access token is tied to one).
     */
    public function apiBase(): string
    {
        return $this->mode() === 'production'
            ? 'https://connect.squareup.com'
            : 'https://connect.squareupsandbox.com';
    }

    /**
     * Configured well enough to attempt a charge: a token and a location for the
     * active mode. (Not a guarantee the credentials are valid — that's verifyConnection.)
     */
    public function isEnabled(): bool
    {
        return (bool) $this->accessToken() && (bool) $this->locationId();
    }

    /**
     * Authenticated HTTP client scoped to this tenant's Square account + environment.
     */
    protected function client()
    {
        return Http::withToken((string) $this->accessToken())
            ->acceptJson()
            ->timeout(15)
            ->baseUrl($this->apiBase());
    }

    /**
     * Confirm the pasted credentials work by retrieving the configured location.
     * Returns ['ok' => bool, 'message' => string] — never throws.
     */
    public function verifyConnection(): array
    {
        $mode = $this->mode();

        if (! $this->accessToken()) {
            return ['ok' => false, 'message' => "No access token saved for {$mode} mode."];
        }
        if (! $this->locationId()) {
            return ['ok' => false, 'message' => "No location ID saved for {$mode} mode."];
        }

        try {
            $resp = $this->client()->get('/v2/locations/' . $this->locationId());
        } catch (\Throwable $e) {
            return ['ok' => false, 'message' => 'Could not reach Square. Check the access token and try again.'];
        }

        if ($resp->successful()) {
            $loc  = $resp->json('location') ?? [];
            $name = $loc['name'] ?? 'location';
            $biz  = $loc['business_name'] ?? null;
            $who  = $biz ? "{$biz} — {$name}" : $name;
            return ['ok' => true, 'message' => "Connected to {$who} ({$mode})."];
        }

        if ($resp->status() === 401) {
            return ['ok' => false, 'message' => 'Square rejected the access token (401). Check that it matches the selected mode.'];
        }
        if ($resp->status() === 404) {
            return ['ok' => false, 'message' => "Location ID not found for this account ({$mode})."];
        }

        $detail = $resp->json('errors.0.detail') ?? ('HTTP ' . $resp->status());
        return ['ok' => false, 'message' => (string) $detail];
    }
}
