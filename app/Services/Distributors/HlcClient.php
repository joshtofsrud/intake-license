<?php
// MARKER-PATCH-HLC1

namespace App\Services\Distributors;

use App\Exceptions\DistributorException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;

/**
 * HLC (Hawley/HLC) API v4.1 adapter.
 *
 *   Docs : https://developer.hlc.bike   (REST, per-dealer API key)
 *   Base : https://api.hlc.bike/{region}/v4.1     region: us | ca
 *   Auth : header  Authorization: ApiKey {key}    +  language: {lang}
 *
 * Built with ONE tenant's key, so cost & availability come back keyed to that
 * dealer account. We never mix shops' pricing.
 *
 * NOTE: v4.1 request param names for Products search (barcode / vendor part)
 * and the Prices PriceTypeId set still need confirmation against a live
 * response — that is exactly what `distributors:hlc-test` dumps. The endpoint
 * PATHS below match HLC's published Catalog service list.
 */
class HlcClient implements DistributorAdapter
{
    private string $base;
    private string $language;
    private int $timeout;
    private int $retries;
    private int $retrySleepMs;
    private int $pageSize;
    private string $authStyle;

    public function __construct(
        private readonly string $apiKey,
        private readonly string $region = 'us',
        ?array $config = null,
    ) {
        $cfg = $config ?? config('distributors.hlc', []);
        $base    = rtrim((string) ($cfg['base_url'] ?? 'https://api.hlc.bike'), '/');
        $version = (string) ($cfg['version'] ?? 'v4.1');

        $this->base         = "{$base}/{$this->region}/{$version}";
        $this->language     = (string) ($cfg['language'] ?? 'en');
        $this->timeout      = (int) ($cfg['timeout'] ?? 20);
        $this->retries      = (int) ($cfg['retries'] ?? 2);
        $this->retrySleepMs = (int) ($cfg['retry_sleep_ms'] ?? 400);
        $this->pageSize     = (int) ($cfg['page_size'] ?? 100);
        $this->authStyle    = (string) ($cfg['auth_style'] ?? 'authorization_apikey');
    }

    public function code(): string
    {
        return 'HLC';
    }

    public function name(): string
    {
        return 'HLC';
    }

    /**
     * Override the auth header form at runtime (used by the test command
     * to probe which style HLC's catalog endpoints accept).
     */
    public function setAuthStyle(string $style): static
    {
        $this->authStyle = $style;
        return $this;
    }

    /** @return array<string,string> */
    private function authHeaders(): array
    {
        return match ($this->authStyle) {
            'bare_apikey'          => ['ApiKey' => $this->apiKey],
            'authorization_bearer' => ['Authorization' => 'Bearer ' . $this->apiKey],
            'authorization_raw'    => ['Authorization' => $this->apiKey],
            default                => ['Authorization' => 'ApiKey ' . $this->apiKey],
        };
    }

    private function http(): PendingRequest
    {
        return Http::baseUrl($this->base)
            ->timeout($this->timeout)
            ->retry($this->retries, $this->retrySleepMs)
            ->withHeaders(array_merge($this->authHeaders(), [
                'language'         => $this->language,
                'Accept'           => 'application/json',
                'Accept-Encoding'  => 'gzip, deflate',
            ]));
    }

    /**
     * @return array<mixed>
     */
    private function get(string $path, array $query = []): array
    {
        $res = $this->http()->get($path, $query);

        if ($res->failed()) {
            throw new DistributorException(
                "HLC {$path} failed: HTTP {$res->status()}",
                'HLC',
                $res->status(),
                $path,
            );
        }

        return $res->json() ?? [];
    }

    public function testConnection(): array
    {
        // System/Echo is the cheapest authenticated probe.
        try {
            $res = $this->http()->get('System/Echo');

            return [
                'ok'     => $res->successful(),
                'status' => $res->status(),
                'body'   => $res->json() ?? $res->body(),
            ];
        } catch (\Throwable $e) {
            return ['ok' => false, 'status' => null, 'body' => $e->getMessage()];
        }
    }

    public function brands(): array
    {
        return $this->get('Catalog/Brands');
    }

    public function categories(): array
    {
        return $this->get('Catalog/Categories');
    }

    public function products(array $opts = []): array
    {
        $query = [
            'pageStartIndex' => $opts['pageStartIndex'] ?? 1,
            'pageSize'       => $opts['pageSize'] ?? $this->pageSize,
        ];

        // v4.x added search by barcode (UPC) and vendor part number.
        if (! empty($opts['upcs'])) {
            $query['barcodes'] = implode(',', (array) $opts['upcs']);
        }
        if (! empty($opts['vendorParts'])) {
            $query['vendorPartNumbers'] = implode(',', (array) $opts['vendorParts']);
        }

        return $this->get('Catalog/Products', $query);
    }

    public function inventory(array $skus): array
    {
        return $this->get('Catalog/Products/Inventory', ['skus' => implode(',', $skus)]);
    }

    public function prices(array $skus): array
    {
        return $this->get('Catalog/Products/Prices', ['skus' => implode(',', $skus)]);
    }

    public function images(array $skus): array
    {
        return $this->get('Catalog/Products/Images', ['skus' => implode(',', $skus)]);
    }
}
