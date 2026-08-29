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

    /** Test mode is per-request at Dropbox Sign, so this is just the default. */
    public static function isTestMode(): bool
    {
        return RaiseSetting::get('signing_test_mode', '1') === '1';
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
