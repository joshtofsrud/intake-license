<?php

namespace App\Services;

use App\Models\Tenant;
use App\Models\Tenant\TenantAppointment;
use Illuminate\Support\Facades\Http;

class StripeService
{
    private string $secretKey;

    public function __construct(Tenant $tenant)
    {
        $s    = $tenant->settings ?? [];
        $mode = $s['stripe_mode'] ?? 'test';
        $this->secretKey = $mode === 'live'
            ? ($s['stripe_live_sk'] ?? '')
            : ($s['stripe_test_sk'] ?? '');
    }

    public function isConfigured(): bool
    {
        return !empty($this->secretKey);
    }

    // ----------------------------------------------------------------
    // Create a PaymentIntent for a booking
    // Returns ['client_secret' => ..., 'payment_intent_id' => ...]
    // ----------------------------------------------------------------
    public function createPaymentIntent(TenantAppointment $appointment): array
    {
        $response = Http::withBasicAuth($this->secretKey, '')
            ->asForm()
            ->post('https://api.stripe.com/v1/payment_intents', [
                'amount'               => $appointment->total_cents,
                'currency'             => strtolower(tenant()->currency ?? 'usd'),
                'metadata[ra_number]'  => $appointment->ra_number,
                'metadata[tenant_id]'  => $appointment->tenant_id,
                'description'          => "Booking {$appointment->ra_number}",
                'automatic_payment_methods[enabled]' => 'true',
            ]);

        if (!$response->successful()) {
            throw new \RuntimeException('Stripe error: ' . ($response->json('error.message') ?? 'Unknown error'));
        }

        $data = $response->json();

        $appointment->update(['stripe_payment_intent_id' => $data['id']]);

        return [
            'client_secret'      => $data['client_secret'],
            'payment_intent_id'  => $data['id'],
        ];
    }

    // ----------------------------------------------------------------
    // Handle incoming Stripe webhook
    // ----------------------------------------------------------------
    public static function handleWebhook(Tenant $tenant, string $payload, string $signature): void
    {
        $s      = $tenant->settings ?? [];
        $secret = $s['stripe_webhook_secret'] ?? '';

        // Verify signature
        if ($secret && !self::verifyWebhookSignature($payload, $signature, $secret)) {
            throw new \RuntimeException('Invalid Stripe webhook signature.');
        }

        $event = json_decode($payload, true);
        $type  = $event['type'] ?? '';

        if ($type === 'payment_intent.succeeded') {
            $pi = $event['data']['object'];
            $appointment = TenantAppointment::where('tenant_id', $tenant->id)
                ->where('stripe_payment_intent_id', $pi['id'])
                ->first();
            if ($appointment) {
                $appointment->update([
                    'payment_status' => 'paid',
                    'paid_cents'     => $pi['amount_received'],
                    'payment_method' => 'stripe',
                ]);
            }
        }

        if ($type === 'payment_intent.payment_failed') {
            $pi = $event['data']['object'];
            TenantAppointment::where('tenant_id', $tenant->id)
                ->where('stripe_payment_intent_id', $pi['id'])
                ->update(['payment_status' => 'unpaid']);
        }
    }

    private static function verifyWebhookSignature(string $payload, string $header, string $secret): bool
    {
        $parts     = explode(',', $header);
        $timestamp = null;
        $signatures = [];

        foreach ($parts as $part) {
            [$key, $val] = explode('=', $part, 2);
            if ($key === 't') $timestamp = $val;
            if ($key === 'v1') $signatures[] = $val;
        }

        if (!$timestamp || empty($signatures)) return false;

        $signed  = $timestamp . '.' . $payload;
        $expected = hash_hmac('sha256', $signed, $secret);

        foreach ($signatures as $sig) {
            if (hash_equals($expected, $sig)) return true;
        }

        return false;
    }
}
