<?php

namespace App\Http\Controllers\Webhooks;

use App\Http\Controllers\Controller;
use App\Models\Tenant\TenantDomain;
use App\Services\DomainProvisioningService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * CloudflareWebhookController
 *
 * Receives Cloudflare custom-hostname notifications. Verifies the
 * webhook signature and triggers a sync for the affected domain.
 *
 * Webhook setup: Cloudflare dashboard → Notifications → Add destination
 * → Webhooks. Point at POST /webhooks/cloudflare. Use the shared secret
 * stored in CLOUDFLARE_WEBHOOK_SECRET.
 *
 * Cloudflare signs each request via the X-Cf-Webhook-Auth header (HMAC-SHA256
 * of the request body using the shared secret). We verify before doing anything.
 */
class CloudflareWebhookController extends Controller
{
    public function __construct(
        private readonly DomainProvisioningService $provisioning,
    ) {}

    public function handle(Request $request): JsonResponse
    {
        $secret = (string) config('services.cloudflare.webhook_secret', '');
        if ($secret === '') {
            Log::error('[CloudflareWebhook] no webhook secret configured');
            return response()->json(['error' => 'not_configured'], 503);
        }

        // Cloudflare signature verification.
        $signatureHeader = (string) $request->header('cf-webhook-auth', '');
        $rawBody = $request->getContent();

        $expected = hash_hmac('sha256', $rawBody, $secret);
        if (!hash_equals($expected, $signatureHeader)) {
            Log::warning('[CloudflareWebhook] signature mismatch', [
                'remote_ip' => $request->ip(),
            ]);
            return response()->json(['error' => 'invalid_signature'], 401);
        }

        $payload = json_decode($rawBody, true);
        if (!is_array($payload)) {
            return response()->json(['error' => 'invalid_payload'], 400);
        }

        // Cloudflare webhook payloads include the hostname and the event type.
        // We use the hostname to locate the TenantDomain row, then sync.
        $hostname = (string) ($payload['data']['hostname'] ?? $payload['hostname'] ?? '');
        $hostname = strtolower(trim($hostname));

        if ($hostname === '') {
            Log::info('[CloudflareWebhook] received event without hostname', [
                'event' => $payload['text'] ?? $payload['alert_type'] ?? 'unknown',
            ]);
            return response()->json(['ok' => true, 'noted' => 'no_hostname']);
        }

        $domain = TenantDomain::where('hostname', $hostname)->first();
        if (!$domain) {
            // CF notified us about a hostname we don't track. Could be
            // a stale CF entry or one created outside this app. Log and ack.
            Log::info('[CloudflareWebhook] event for unknown hostname', [
                'hostname' => $hostname,
            ]);
            return response()->json(['ok' => true, 'noted' => 'unknown_hostname']);
        }

        // Trigger a sync. This pulls the current state from CF and updates
        // our row to match.
        try {
            $this->provisioning->syncFromCloudflare($domain);
        } catch (\Throwable $e) {
            Log::error('[CloudflareWebhook] sync failure', [
                'hostname' => $hostname,
                'error'    => $e->getMessage(),
            ]);
            // Still 200 - CF retries non-2xx, and the polling loop will
            // catch up anyway. We don't want a retry storm.
        }

        return response()->json(['ok' => true]);
    }
}
