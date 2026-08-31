<?php

namespace App\Services;

// MARKER-SIGNING-CREDS
use App\Models\RaiseSetting;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Dropbox Sign, over plain HTTP rather than their SDK.
 *
 * Deliberately no composer dependency: the calls needed here are a handful of
 * REST endpoints with basic auth, and adding a package means a lock-file
 * change and a composer step on every deploy for very little.
 *
 * Auth is the API key as the basic-auth USERNAME with an empty password —
 * their scheme, not a mistake.
 */
class SigningService
{
    public const BASE = 'https://api.hellosign.com/v3';

    /** Stored encrypted; raise_settings itself is plaintext. */
    public static function key(): ?string
    {
        $stored = RaiseSetting::get('signing_api_key');

        if (! $stored) { return null; }

        try {
            return Crypt::decryptString($stored);
        } catch (\Throwable $e) {
            // A key encrypted under a previous APP_KEY can never be recovered.
            // Say so plainly rather than behaving as if none was set.
            Log::error('MARKER-SIGNING-CREDS stored key could not be decrypted', [
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    public static function putKey(?string $plain): void
    {
        RaiseSetting::put('signing_api_key', $plain ? Crypt::encryptString($plain) : null);
    }

    public static function hasKey(): bool
    {
        return (bool) RaiseSetting::get('signing_api_key');
    }

    public static function isConfigured(): bool
    {
        return (bool) self::key();
    }

    /**
     * MARKER-MANUAL-SAFE — whether Intake sends the SAFE itself.
     *
     * Off by default: sending through the API needs a paid Dropbox Sign tier,
     * and a button that always fails is worse than no button. With it off, the
     * same row offers the details needed to send it by hand.
     */
    public static function isAutomatic(): bool
    {
        return RaiseSetting::get('signing_automatic', '0') === '1';
    }

    /** Test mode is per-request at Dropbox Sign, so this is just the default. */
    public static function isTestMode(): bool
    {
        return RaiseSetting::get('signing_test_mode', '1') === '1';
    }

    public static function templateId(): ?string
    {
        return RaiseSetting::get('signing_template_id') ?: null;
    }

    /**
     * MARKER-SIGNING-SEND — send the SAFE to one investor.
     *
     * The three recital fields are SENDER fields on the template, filled here
     * from the investor's own record. The investor cannot change the amount
     * they are agreeing to; they can sign it or not.
     *
     * @return array{ok:bool,message:string,request_id:?string}
     */
    public static function sendSafe(\App\Models\Investor $investor): array
    {
        $key      = self::key();
        $template = self::templateId();

        if (! $key)      { return ['ok' => false, 'message' => 'No API key saved.', 'request_id' => null]; }
        if (! $template) { return ['ok' => false, 'message' => 'No template ID saved.', 'request_id' => null]; }
        if (! $investor->email) { return ['ok' => false, 'message' => 'That investor has no email.', 'request_id' => null]; }
        if (! $investor->amount) { return ['ok' => false, 'message' => 'No committed amount to put in the document.', 'request_id' => null]; }

        $payload = [
            'template_ids' => [$template],
            'test_mode'    => self::isTestMode() ? 1 : 0,
            'subject'      => 'Your SAFE with Intake Inc',
            'message'      => 'The amount and details are already filled in — please read it and sign if you are happy.',
            'signers'      => [
                [
                    'role'         => 'Investor',
                    'name'         => $investor->name,
                    'email_address' => $investor->email,
                ],
            ],
            // Sender fields. Their names must match the template's merge fields
            // exactly or the value is silently dropped and the document goes out
            // with a blank where the amount should be.
            'custom_fields' => [
                ['name' => 'investor_name',   'value' => $investor->entity ?: $investor->name],
                ['name' => 'purchase_amount', 'value' => number_format((int) $investor->amount)],
                ['name' => 'safe_date',       'value' => now()->format('F j, Y')],
            ],
        ];

        try {
            $response = Http::withBasicAuth($key, '')
                ->acceptJson()
                ->timeout(30)
                ->post(self::BASE . '/signature_request/send_with_template', $payload);
        } catch (\Throwable $e) {
            Log::error('MARKER-SIGNING-SEND request threw', ['investor' => $investor->id, 'error' => $e->getMessage()]);

            return ['ok' => false, 'message' => 'Could not reach Dropbox Sign.', 'request_id' => null];
        }

        if (! $response->successful()) {
            $detail = $response->json('error.error_msg') ?: ('HTTP ' . $response->status());
            Log::error('MARKER-SIGNING-SEND rejected', ['investor' => $investor->id, 'detail' => $detail]);

            return ['ok' => false, 'message' => $detail, 'request_id' => null];
        }

        $requestId = $response->json('signature_request.signature_request_id');

        return ['ok' => true, 'message' => 'Sent to ' . $investor->email . '.', 'request_id' => $requestId];
    }

    /**
     * MARKER-SIGNING-SEND — verify a callback.
     *
     * Dropbox Sign has no separate webhook secret: the hash is HMAC-SHA256 of
     * the event time concatenated with the event type, keyed on the API KEY.
     * Which means rotating the key silently breaks callbacks until the
     * dashboard is updated as well.
     */
    public static function callbackIsValid(array $event): bool
    {
        $key = self::key();

        if (! $key) { return false; }

        $time = (string) ($event['event_time'] ?? '');
        $type = (string) ($event['event_type'] ?? '');
        $hash = (string) ($event['event_hash'] ?? '');

        if ($time === '' || $type === '' || $hash === '') { return false; }

        return hash_equals(hash_hmac('sha256', $time . $type, $key), $hash);
    }

    /** MARKER-SIGNING-SEND — pull the executed PDF once it exists. */
    public static function downloadExecuted(string $requestId): ?string
    {
        $key = self::key();

        if (! $key) { return null; }

        try {
            $response = Http::withBasicAuth($key, '')
                ->timeout(60)
                ->get(self::BASE . '/signature_request/files/' . $requestId, ['file_type' => 'pdf']);
        } catch (\Throwable $e) {
            Log::error('MARKER-SIGNING-SEND download threw', ['request' => $requestId, 'error' => $e->getMessage()]);

            return null;
        }

        return $response->successful() ? $response->body() : null;
    }

    /**
     * Ask their account endpoint who we are.
     *
     * @return array{ok:bool,message:string,email:?string}
     */
    public static function testConnection(): array
    {
        $key = self::key();

        if (! $key) {
            return ['ok' => false, 'message' => 'No API key saved yet.', 'email' => null];
        }

        try {
            $response = Http::withBasicAuth($key, '')
                ->acceptJson()
                ->timeout(15)
                ->get(self::BASE . '/account');
        } catch (\Throwable $e) {
            Log::error('MARKER-SIGNING-CREDS connection test threw', ['error' => $e->getMessage()]);

            return ['ok' => false, 'message' => 'Could not reach Dropbox Sign: ' . $e->getMessage(), 'email' => null];
        }

        if ($response->status() === 401) {
            return ['ok' => false, 'message' => 'Dropbox Sign rejected that key.', 'email' => null];
        }

        if (! $response->successful()) {
            return [
                'ok'      => false,
                'message' => 'Dropbox Sign returned ' . $response->status() . '.',
                'email'   => null,
            ];
        }

        $email = $response->json('account.email_address');

        return [
            'ok'      => true,
            'message' => 'Connected' . ($email ? ' as ' . $email : '') . '.',
            'email'   => $email,
        ];
    }
}
