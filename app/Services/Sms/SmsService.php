<?php

namespace App\Services\Sms;

use App\Models\Tenant;
use Illuminate\Support\Facades\Log;

class SmsService
{
    /**
     * Send an SMS to a phone number on behalf of a tenant.
     * Throws on provider error (so callers can log to the offer row).
     */
    public static function send(Tenant $tenant, string $to, string $body): void
    {
        $to = self::normalizePhone($to);
        if (!$to) {
            throw new \InvalidArgumentException('Invalid phone number');
        }

        // Driver selection: tenant-first. If the tenant has Twilio credentials AND
        // sms_enabled, use Twilio for them — even if the global default driver is 'null'.
        // This lets one shop send SMS while others stay in null mode (e.g. dev tenants).
        $driver = config('services.twilio.driver', 'null'); // MARKER-PATCH-224B
        if ($tenant->sms_enabled && $tenant->twilio_account_sid && $tenant->twilio_auth_token) {
            $driver = 'twilio';
        }

        if ($driver === 'null') {
            Log::info('SmsService (null driver)', [
                'tenant_id' => $tenant->id,
                'to'        => $to,
                'body'      => $body,
            ]);
            return;
        }

        if ($driver === 'twilio') {
            try {
                self::sendViaTwilio($tenant, $to, $body);
            } catch (\Throwable $e) {
                // Don't break the caller (booking flow, status update, etc.) on Twilio errors.
                // Log it, return silently. Caller-side: assume "best effort" delivery.
                Log::error('SmsService Twilio send failed', [
                    'tenant_id' => $tenant->id,
                    'to'        => $to,
                    'error'     => $e->getMessage(),
                ]);
            }
            return;
        }

        throw new \RuntimeException('Unknown SMS driver: ' . $driver);
    }

    private static function sendViaTwilio(Tenant $tenant, string $to, string $body): void
    {
        // Per-tenant credentials are the canonical source. Fall back to platform-level
        // env vars only if a tenant hasn't configured their own — this lets a tenant
        // shop with Twilio set up override the platform default. P15: fail open on
        // 3rd-party errors — log + continue rather than blocking the booking flow.
        $sid   = $tenant->twilio_account_sid ?: config('services.twilio.sid');   // MARKER-PATCH-224B
        $token = $tenant->twilio_auth_token  ?: config('services.twilio.token');
        $from  = $tenant->sms_from_number    ?: config('services.twilio.from');

        if (!$sid || !$token || !$from) {
            throw new \RuntimeException('Twilio credentials not configured for tenant ' . $tenant->id);
        }

        if (!class_exists(\Twilio\Rest\Client::class)) {
            throw new \RuntimeException('Twilio SDK not installed. Run: composer require twilio/sdk');
        }

        $client = new \Twilio\Rest\Client($sid, $token);
        $client->messages->create($to, [
            'from' => $from,
            'body' => $body,
        ]);
    }

    /**
     * Normalize a phone number to E.164 (best-effort; assumes US if no country code).
     */
    public static function normalizePhone(?string $raw): ?string
    {
        if (!$raw) return null;
        $digits = preg_replace('/\D+/', '', $raw);
        if (!$digits) return null;
        // If it's 10 digits, assume US and prepend +1
        if (strlen($digits) === 10) return '+1' . $digits;
        if (strlen($digits) === 11 && str_starts_with($digits, '1')) return '+' . $digits;
        // If already has country code prefix
        if (str_starts_with($raw, '+')) return '+' . $digits;
        return '+' . $digits;
    }
}
