<?php

namespace App\Services;

use App\Models\Tenant;
use App\Models\Tenant\TenantAppointment;
use Illuminate\Support\Facades\Http;

class PayPalService
{
    private string $clientId;
    private string $secret;
    private string $baseUrl;

    public function __construct(Tenant $tenant)
    {
        $s    = $tenant->settings ?? [];
        $mode = $s['paypal_mode'] ?? 'sandbox';

        if ($mode === 'live') {
            $this->clientId = $s['paypal_live_client_id'] ?? '';
            $this->secret   = $s['paypal_live_secret']    ?? '';
            $this->baseUrl  = 'https://api-m.paypal.com';
        } else {
            $this->clientId = $s['paypal_test_client_id'] ?? '';
            $this->secret   = $s['paypal_test_secret']    ?? '';
            $this->baseUrl  = 'https://api-m.sandbox.paypal.com';
        }
    }

    public function isConfigured(): bool
    {
        return !empty($this->clientId) && !empty($this->secret);
    }

    // ----------------------------------------------------------------
    // Get an OAuth access token
    // ----------------------------------------------------------------
    private function accessToken(): string
    {
        $response = Http::withBasicAuth($this->clientId, $this->secret)
            ->asForm()
            ->post("{$this->baseUrl}/v1/oauth2/token", ['grant_type' => 'client_credentials']);

        if (!$response->successful()) {
            throw new \RuntimeException('PayPal auth failed: ' . ($response->json('error_description') ?? 'Unknown'));
        }

        return $response->json('access_token');
    }

    // ----------------------------------------------------------------
    // Create a PayPal order for a booking
    // Returns ['order_id' => ..., 'approve_url' => ...]
    // ----------------------------------------------------------------
    public function createOrder(TenantAppointment $appointment): array
    {
        $token    = $this->accessToken();
        $currency = strtoupper(tenant()->currency ?? 'USD');
        $amount   = number_format($appointment->total_cents / 100, 2, '.', '');

        $response = Http::withToken($token)
            ->post("{$this->baseUrl}/v2/checkout/orders", [
                'intent' => 'CAPTURE',
                'purchase_units' => [[
                    'reference_id' => $appointment->ra_number,
                    'description'  => "Booking {$appointment->ra_number}",
                    'amount'       => [
                        'currency_code' => $currency,
                        'value'         => $amount,
                    ],
                ]],
                'application_context' => [
                    'return_url' => url("/book/paypal/return?ra={$appointment->ra_number}"),
                    'cancel_url' => url('/book'),
                    'brand_name' => tenant()->name,
                    'user_action'=> 'PAY_NOW',
                ],
            ]);

        if (!$response->successful()) {
            throw new \RuntimeException('PayPal order creation failed: ' . ($response->json('message') ?? 'Unknown'));
        }

        $data = $response->json();
        $orderId = $data['id'];

        $appointment->update(['paypal_order_id' => $orderId]);

        // Find the approve URL
        $approveUrl = collect($data['links'] ?? [])
            ->firstWhere('rel', 'approve')['href'] ?? null;

        return [
            'order_id'    => $orderId,
            'approve_url' => $approveUrl,
        ];
    }

    // ----------------------------------------------------------------
    // Capture a PayPal order after buyer approval
    // ----------------------------------------------------------------
    public function captureOrder(string $orderId): array
    {
        $token    = $this->accessToken();
        $response = Http::withToken($token)
            ->post("{$this->baseUrl}/v2/checkout/orders/{$orderId}/capture");

        if (!$response->successful()) {
            throw new \RuntimeException('PayPal capture failed: ' . ($response->json('message') ?? 'Unknown'));
        }

        return $response->json();
    }

    // ----------------------------------------------------------------
    // Handle PayPal return after buyer approval
    // ----------------------------------------------------------------
    public static function handleReturn(Tenant $tenant, string $orderId): TenantAppointment
    {
        $service     = new self($tenant);
        $capture     = $service->captureOrder($orderId);
        $status      = $capture['status'] ?? '';

        $appointment = TenantAppointment::where('tenant_id', $tenant->id)
            ->where('paypal_order_id', $orderId)
            ->firstOrFail();

        if ($status === 'COMPLETED') {
            $captured    = $capture['purchase_units'][0]['payments']['captures'][0] ?? [];
            $amountValue = $captured['amount']['value'] ?? 0;
            $paidCents   = (int) round((float) $amountValue * 100);

            $appointment->update([
                'payment_status' => 'paid',
                'paid_cents'     => $paidCents,
                'payment_method' => 'paypal',
            ]);
        }

        return $appointment;
    }
}
