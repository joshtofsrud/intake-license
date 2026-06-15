<?php
// MARKER-PATCH-HLC1

namespace App\Services\Distributors;

/**
 * The contract every distributor integration implements. HLC ships first;
 * QBP / J&B / BTI drop in behind the same interface, one adapter at a time.
 *
 * Identity layering (per the build plan):
 *   - These methods return a distributor's RAW feed shape.
 *   - Normalisation into platform_distributor_catalogs (one row per
 *     distributor+variant, grouped by UPC/MPN) happens in the sync layer,
 *     NOT here. Adapters stay thin.
 *
 * Per-tenant by construction: an adapter instance is built with a single
 * tenant's credentials, so cost & availability always come back keyed to
 * that shop's account — one tenant never sees another's pricing.
 */
interface DistributorAdapter
{
    /** Stable distributor code stored on catalog/subscription rows, e.g. 'HLC'. */
    public function code(): string;

    /** Human label for UI. */
    public function name(): string;

    /**
     * Lightweight connectivity + auth probe.
     *
     * @return array{ok: bool, status: int|null, body: mixed}
     */
    public function testConnection(): array;

    /** All brands (slow-changing; cache ~24h). */
    public function brands(): array;

    /** Category tree (slow-changing; cache ~24h). */
    public function categories(): array;

    /**
     * Paginated product catalog.
     *
     * @param array{pageStartIndex?: int, pageSize?: int, upcs?: array, vendorParts?: array} $opts
     */
    public function products(array $opts = []): array;

    /** Live per-warehouse availability for the given variants/UPCs. */
    public function inventory(array $skus): array;

    /** Live per-tenant pricing (cost / MAP / MSRP tiers) for the given variants/UPCs. */
    public function prices(array $skus): array;

    /** Image paths for the given variants/UPCs. */
    public function images(array $skus): array;
}
