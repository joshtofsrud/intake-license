<?php
// MARKER-PATCH-224

namespace App\Services\Sms;

use App\Models\Tenant;
use Illuminate\Support\Facades\Log;

/**
 * TwilioProvisioningService — Intake-managed numbers on the PLATFORM
 * Twilio account. One number per tenant (the unique index enforces it);
 * the number's inbound webhook is configured at purchase so replies route
 * from message one.
 *
 * BYO tenants keep their own account; syncInboundWebhookByo() points
 * THEIR number at our inbound URL using THEIR credentials.
 */
class TwilioProvisioningService
{
    public function platformConfigured(): bool
    {
        return (bool) (env('TWILIO_SID') && env('TWILIO_TOKEN'));
    }

    public function inboundUrl(): string
    {
        return rtrim((string) config('app.url'), '/') . '/webhooks/twilio/inbound';
    }

    /** @return array<int, array{number:string, friendly:string, locality:?string}> */
    public function searchTollFree(int $limit = 8): array
    {
        $results = $this->platformClient()->availablePhoneNumbers('US')->tollFree
            ->read(['smsEnabled' => true], $limit);

        return array_map(fn ($n) => [
            'number'   => $n->phoneNumber,
            'friendly' => $n->friendlyName,
            'locality' => null,
        ], $results);
    }

    /** @return array<int, array{number:string, friendly:string, locality:?string}> */
    public function searchLocal(string $areaCode, int $limit = 8): array
    {
        $results = $this->platformClient()->availablePhoneNumbers('US')->local
            ->read(['areaCode' => (int) $areaCode, 'smsEnabled' => true], $limit);

        return array_map(fn ($n) => [
            'number'   => $n->phoneNumber,
            'friendly' => $n->friendlyName,
            'locality' => $n->locality ?: null,
        ], $results);
    }

    /**
     * Buy the number on the platform account, wire its inbound webhook,
     * stamp the tenant. Tenant SID/token stay NULL — SmsService's platform
     * fallback is exactly the managed-number send path.
     */
    public function purchase(Tenant $tenant, string $e164): void
    {
        $purchased = $this->platformClient()->incomingPhoneNumbers->create([
            'phoneNumber'  => $e164,
            'smsUrl'       => $this->inboundUrl(),
            'smsMethod'    => 'POST',
            'friendlyName' => 'Intake — ' . mb_substr($tenant->name, 0, 50),
        ]);

        $tenant->update([
            'sms_from_number'    => SmsService::normalizePhone($purchased->phoneNumber),
            'sms_enabled'        => true,
            'twilio_number_sid'  => $purchased->sid,
            'twilio_account_sid' => null,
            'twilio_auth_token'  => null,
        ]);

        Log::info('twilio_provisioning.purchased', [
            'tenant_id' => $tenant->id,
            'number'    => $purchased->phoneNumber,
            'sid'       => $purchased->sid,
        ]);
    }

    /** Master-admin escape hatch: release a managed number back to Twilio. */
    public function release(Tenant $tenant): void
    {
        if (!$tenant->twilio_number_sid) {
            throw new \RuntimeException('Tenant has no Intake-managed number to release.');
        }

        $this->platformClient()->incomingPhoneNumbers($tenant->twilio_number_sid)->delete();

        $tenant->update([
            'sms_from_number'   => null,
            'sms_enabled'       => false,
            'twilio_number_sid' => null,
        ]);

        Log::info('twilio_provisioning.released', ['tenant_id' => $tenant->id]);
    }

    /**
     * BYO path: point the tenant-owned number's inbound webhook at our URL
     * using the TENANT's credentials. Returns the matched number SID.
     */
    public function syncInboundWebhookByo(Tenant $tenant): string
    {
        if (!$tenant->twilio_account_sid || !$tenant->twilio_auth_token || !$tenant->sms_from_number) {
            throw new \RuntimeException('Save your Twilio credentials and from-number first.');
        }

        $client = new \Twilio\Rest\Client($tenant->twilio_account_sid, $tenant->twilio_auth_token);
        $matches = $client->incomingPhoneNumbers->read(['phoneNumber' => $tenant->sms_from_number], 1);

        if (empty($matches)) {
            throw new \RuntimeException('That number was not found on your Twilio account.');
        }

        $client->incomingPhoneNumbers($matches[0]->sid)->update([
            'smsUrl'    => $this->inboundUrl(),
            'smsMethod' => 'POST',
        ]);

        return $matches[0]->sid;
    }

    private function platformClient(): \Twilio\Rest\Client
    {
        if (!$this->platformConfigured()) {
            throw new \RuntimeException('Platform Twilio credentials (TWILIO_SID / TWILIO_TOKEN) are not configured.');
        }
        if (!class_exists(\Twilio\Rest\Client::class)) {
            throw new \RuntimeException('Twilio SDK not installed.');
        }

        return new \Twilio\Rest\Client(env('TWILIO_SID'), env('TWILIO_TOKEN'));
    }
}
