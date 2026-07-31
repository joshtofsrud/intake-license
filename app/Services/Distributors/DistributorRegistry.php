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
