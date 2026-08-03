<?php
// MARKER-PATCH-HLC2

namespace App\Services\Distributors;

use App\Models\Tenant\TenantDistributorCatalogSubscription;
use InvalidArgumentException;
// QbpClient, HlcClient and BtiClient share this namespace, so no import.

/**
 * Resolves the right DistributorAdapter for a distributor code or a tenant
 * subscription. Nothing downstream (sync, availability, UI) should `new
 * HlcClient` directly — they ask the registry. Adding QBP/J&B/BTI later is a
 * single map entry plus the adapter class; the rest of the system is untouched.
 */
class DistributorRegistry
{
    /** distributor_code => adapter class */
    private const ADAPTERS = [
        'HLC' => HlcClient::class,
        // MARKER-BTI-ADAPTER — credentials ride in api_key as
        // "username:password"; BtiClient splits on the first colon, so the
        // shared (apiKey, region) constructor shape still holds.
        'BTI' => BtiClient::class,
        // MARKER-QBP-ADAPTER — present so master admin can hold the key and
        // test it. Only testConnection() works; the data methods throw.
        'QBP' => QbpClient::class,
    ];

    /**
     * MARKER-DIST-MULTI — what each distributor asks a shop for.
     *
     * HLC issues one API key. BTI issues a username and a password for HTTP
     * Basic. Both end up in the api_key credential slot (BTI's joined with a
     * colon) so make() keeps its single shape, but the shop sees the fields
     * its own distributor actually uses.
     *
     * @return array<int,array{name:string,label:string,type:string,hint:?string}>
     */
    public function credentialFields(string $code): array
    {
        return match (strtoupper($code)) {
            'BTI' => [
                ['name' => 'username', 'label' => 'BTI username', 'type' => 'text',
                 'hint' => 'The account number on your BTI Inventory Data Download page.'],
                ['name' => 'password', 'label' => 'BTI password', 'type' => 'password',
                 'hint' => 'The long code on the same page.'],
            ],
            default => [
                ['name' => 'api_key', 'label' => 'API key', 'type' => 'password',
                 'hint' => 'Issued by the distributor for your dealer account.'],
            ],
        };
    }

    /** Human label for a code, without building an adapter. */
    public function label(string $code): string
    {
        return match (strtoupper($code)) {
            'HLC' => 'HLC',
            'BTI' => 'BTI',
            'QBP' => 'QBP',
            default => strtoupper($code),
        };
    }

    /**
     * Collapse a submitted credential form into the stored shape. BTI's two
     * fields join with a colon because BtiClient splits on the first one.
     *
     * @param  array<string,string> $input
     */
    /**
     * MARKER-PARTIAL-CREDS — merge submitted fields over what is stored.
     *
     * This used to require BOTH BTI fields and return null otherwise. The
     * form tells the user a blank field keeps the saved value, so typing
     * only a corrected password saved nothing at all and still reported
     * success — a silent no-op on the one screen where being wrong is
     * invisible, because the stored value is masked.
     *
     * Now each field replaces its own part and a blank one keeps what is
     * there, which is what the screen already promises.
     *
     * @param  array<string,string> $input
     * @param  string|null $stored  the currently saved credential
     */
    public function packCredentials(string $code, array $input, ?string $stored = null): ?string
    {
        $stored = (string) $stored;

        if (strtoupper($code) === 'BTI') {
            $curUser = '';
            $curPass = '';
            if (str_contains($stored, ':')) {
                [$curUser, $curPass] = explode(':', $stored, 2);
            }

            $u = trim($input['username'] ?? '') ?: $curUser;
            $p = trim($input['password'] ?? '') ?: $curPass;

            // Still nothing to store if neither side has ever been given.
            return ($u === '' || $p === '') ? null : $u . ':' . $p;
        }

        $k = trim($input['api_key'] ?? '');
        return $k !== '' ? $k : ($stored !== '' ? $stored : null);
    }

    /**
     * MARKER-PARTIAL-CREDS — placeholder per credential field.
     *
     * Both BTI fields used to show the same masked string, which is the
     * username and password joined with a colon, so the username box hinted
     * at the password.
     *
     * @return array<string,string>
     */
    public function credentialHints(string $code, ?string $stored, callable $mask): array
    {
        $stored = (string) $stored;
        if ($stored === '') {
            return [];
        }

        if (strtoupper($code) === 'BTI' && str_contains($stored, ':')) {
            [$user, $pass] = explode(':', $stored, 2);
            return [
                // An account number, not a secret.
                'username' => $user,
                'password' => $mask($pass),
            ];
        }

        return ['api_key' => $mask($stored)];
    }

    /** @return array<int,string> supported distributor codes */
    public function supported(): array
    {
        return array_keys(self::ADAPTERS);
    }

    public function isSupported(string $code): bool
    {
        return isset(self::ADAPTERS[strtoupper($code)]);
    }

    /**
     * Build an adapter from raw credentials.
     *
     * @param array{api_key?: string, region?: string} $credentials
     */
    public function make(string $code, array $credentials): DistributorAdapter
    {
        $code = strtoupper($code);
        $class = self::ADAPTERS[$code] ?? null;

        if ($class === null) {
            throw new InvalidArgumentException("No distributor adapter registered for code [{$code}].");
        }

        $apiKey = (string) ($credentials['api_key'] ?? '');
        $region = (string) ($credentials['region'] ?? 'us');

        // Every current adapter shares the (apiKey, region) constructor shape.
        // If a future distributor needs a different signature, branch here.
        return new $class($apiKey, $region);
    }

    /**
     * Build an adapter for a tenant's saved subscription, reading its
     * (encrypted) credentials. Returns null when the distributor isn't
     * supported or the subscription has no usable key.
     */
    public function forSubscription(TenantDistributorCatalogSubscription $sub): ?DistributorAdapter
    {
        if (! $this->isSupported((string) $sub->distributor_code)) {
            return null;
        }

        $creds = (array) ($sub->credentials_encrypted ?? []);
        if ((string) ($creds['api_key'] ?? '') === '') {
            return null;
        }

        return $this->make((string) $sub->distributor_code, $creds);
    }
}
