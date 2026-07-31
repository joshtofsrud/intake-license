<?php
// MARKER-PATCH-HLC2

namespace App\Services\Distributors;

use App\Models\Tenant\TenantDistributorCatalogSubscription;
use InvalidArgumentException;

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
    public function packCredentials(string $code, array $input): ?string
    {
        if (strtoupper($code) === 'BTI') {
            $u = trim($input['username'] ?? '');
            $p = trim($input['password'] ?? '');
            return ($u === '' || $p === '') ? null : $u . ':' . $p;
        }
        $k = trim($input['api_key'] ?? '');
        return $k === '' ? null : $k;
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
