<?php

// MARKER-QBP-ADAPTER

namespace App\Services\Distributors;

use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * QBP Point-of-Sale API (API1).
 *
 * A skeleton. testConnection() works; the data methods do not exist yet and
 * say so loudly instead of returning empty arrays — an adapter that returns
 * nothing lets a sync "succeed" while writing no rows, which is a failure
 * that hides for weeks.
 *
 * Shape notes from QBP's developer guide, to be confirmed against a real
 * payload before any of it is relied on:
 *
 *   - Auth is a single key in an X-QBPAPI-KEY header.
 *   - A "model" groups related products (a size run, colour variants), which
 *     maps to our product/variant split: model -> distributor_product_no,
 *     sku -> distributor_variant_no.
 *   - Categories come back as a real tree, so category_path can be built
 *     properly rather than concatenated as BTI's is.
 *   - Bullet points exist at BOTH model and product level and must be
 *     combined to get the full set.
 *   - Images require a separate CLS subscription; product detail carries
 *     file names only.
 *   - Dealer cost is NOT documented as present on product detail. CLS
 *     explicitly excludes "Your Price". Confirm with the probe before
 *     designing anything that depends on cost arriving here.
 */
class QbpClient implements DistributorAdapter
{
    private string $apiKey;
    private string $base;

    public function __construct(string $apiKey, string $region = 'us')
    {
        $this->apiKey = trim($apiKey);
        $this->base = rtrim((string) config('distributors.qbp.base_url', 'https://api1.qbp.com/api/'), '/') . '/';
    }

    public function code(): string
    {
        return 'QBP';
    }

    public function name(): string
    {
        return 'QBP';
    }

    /**
     * Real. 1/brand is the smallest call that requires a valid key, so a
     * success here proves the credential rather than merely reaching a host.
     */
    public function testConnection(): array
    {
        // MARKER-QBP-TEST-SHAPE — ok/status/body, matching HlcClient and
        // BtiClient. The page reads 'status'; returning only a message meant
        // it rendered "HTTP ?" and discarded the explanation.
        if ($this->apiKey === '') {
            return ['ok' => false, 'status' => null, 'body' => 'No API key saved for QBP.'];
        }

        try {
            $res = $this->get('1/brand');
        } catch (\Throwable $e) {
            return ['ok' => false, 'status' => null, 'body' => 'Could not reach QBP: ' . $e->getMessage()];
        }

        $status = $res->status();

        if ($status === 401 || $status === 403) {
            return [
                'ok' => false, 'status' => $status,
                'body' => 'QBP rejected the key. This must be the API1 (Point-of-Sale) key — '
                        . 'a Content License Service key will not work here.',
            ];
        }

        if (! $res->successful()) {
            return [
                'ok' => false, 'status' => $status,
                'body' => 'QBP returned HTTP ' . $status . '. ' . mb_substr((string) $res->body(), 0, 200),
            ];
        }

        $json  = $res->json();
        $count = is_array($json) ? count($this->listish($json)) : 0;

        // A 200 carrying no brands is not a working connection — it usually
        // means the key is valid but the account has no catalog access.
        if ($count === 0) {
            return [
                'ok' => false, 'status' => $status,
                'body' => 'QBP answered but returned no brands. The key works; the account may not '
                        . 'have product access yet.',
            ];
        }

        return [
            'ok'     => true,
            'status' => $status,
            'body'   => 'Connected. QBP returned ' . $count . ' brands.',
        ];
    }

    // ---------------------------------------------------------------- todo

    public function brands(): array      { throw $this->pending('brands'); }
    public function categories(): array  { throw $this->pending('categories'); }
    public function products(array $opts = []): array { throw $this->pending('products'); }
    public function inventory(array $skus): array     { throw $this->pending('inventory'); }
    public function prices(array $skus): array        { throw $this->pending('prices'); }
    public function images(array $skus): array        { throw $this->pending('images'); }

    /**
     * Loud on purpose. Returning [] here would let a catalog sync finish,
     * write zero rows and report success.
     */
    private function pending(string $method): RuntimeException
    {
        return new RuntimeException(
            "QbpClient::{$method}() is not built yet. The QBP adapter currently supports "
            . 'testing the connection only — run `php artisan qbp:probe` to capture a real '
            . 'payload, then the field map and this method can be written against it.'
        );
    }

    // ---------------------------------------------------------------- http

    private function get(string $path, array $query = [])
    {
        return Http::withHeaders([
                'X-QBPAPI-KEY' => $this->apiKey,
                'Accept'       => 'application/json',
            ])
            ->timeout((int) config('distributors.qbp.timeout', 60))
            ->get($this->base . $path, $query);
    }

    /** QBP wraps lists in a named key; find it without assuming the name. */
    private function listish(array $payload): array
    {
        if (array_is_list($payload)) {
            return $payload;
        }
        foreach ($payload as $v) {
            if (is_array($v) && array_is_list($v)) {
                return $v;
            }
        }
        return [$payload];
    }
}
