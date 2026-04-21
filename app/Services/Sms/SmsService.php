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

        $driver = config('services.twilio.driver', env('TWILIO_DRIVER', 'null'));

        if ($driver === 'null') {
            Log::info('SmsService (null driver)', [
                'tenant_id' => $tenant->id,
                'to'        => $to,
                'body'      => $body,
            ]);
            return;
        }

        if ($driver === 'twilio') {
            self::sendViaTwilio($tenant, $to, $body);
            return;
        }

        throw new \RuntimeException('Unknown SMS driver: ' . $driver);
    }

    private static function sendViaTwilio(Tenant $tenant, string $to, string $body): void
    {
        $sid   = env('TWILIO_SID');
        $token = env('TWILIO_TOKEN');
        $from  = env('TWILIO_FROM');

        if (!$sid || !$token || !$from) {
            throw new \RuntimeException('Twilio credentials not configured (TWILIO_SID, TWILIO_TOKEN, TWILIO_FROM)');
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
