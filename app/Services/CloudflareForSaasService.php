<?php

namespace App\Services;

use App\Exceptions\CloudflareException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * CloudflareForSaasService
 *
 * Thin wrapper around the Cloudflare API custom-hostname endpoints. Used by
 * the domain state machine (patch 118) to register tenant domains and
 * watch their lifecycle.
 *
 * Configuration: see config/services.php 'cloudflare' block.
 *
 * Cloudflare API docs:
 *   - Custom hostnames: https://developers.cloudflare.com/api/operations/custom-hostname-for-a-zone-create-custom-hostname
 *
 * Lifecycle (Cloudflare's side):
 *   - We POST a custom hostname with TXT-record ownership verification.
 *   - CF returns an ID and an `ownership_verification` block telling us
 *     the TXT record name + value the customer needs to add.
 *   - When DNS resolves and TXT verification succeeds, CF moves the
 *     hostname status to 'active' and starts issuing the cert.
 *   - We poll get() periodically and reflect status into tenant_domains.
 */
class CloudflareForSaasService
{
    private string $apiBase;
    private string $apiToken;
    private string $zoneId;
    private string $fallbackOrigin;
    private int $httpTimeout;

    public function __construct()
    {
        $cfg = config('services.cloudflare', []);
        $this->apiBase        = (string) ($cfg['api_base'] ?? 'https://api.cloudflare.com/client/v4');
        $this->apiToken       = (string) ($cfg['api_token'] ?? '');
        $this->zoneId         = (string) ($cfg['zone_id'] ?? '');
        $this->fallbackOrigin = (string) ($cfg['fallback_origin'] ?? 'link.intake.works');
        $this->httpTimeout    = (int) ($cfg['http_timeout'] ?? 15);
    }

    /**
     * Whether the service has the credentials it needs to make calls.
     */
    public function isConfigured(): bool
    {
        return $this->apiToken !== '' && $this->zoneId !== '';
    }

    // ──────────────────────────────────────────────────────────────────────
    // Public API
    // ──────────────────────────────────────────────────────────────────────

    /**
     * Register a custom hostname with Cloudflare.
     *
     * @return array{id:string, hostname:string, status:string, ownership_verification:array, raw:array}
     *
     * @throws CloudflareException
     */
    public function createCustomHostname(string $hostname): array
    {
        $this->assertConfigured();

        $hostname = strtolower(trim($hostname));
        if ($hostname === '') {
            throw new CloudflareException('hostname is required', 'invalid_input');
        }

        // We use TXT-record ownership verification (not HTTP) because tenants
        // may not yet have web hosting at the domain when adding it here.
        // CF will tell us the TXT record name + value to share with the tenant.
        $payload = [
            'hostname' => $hostname,
            'ssl' => [
                'method'          => 'txt',
                'type'            => 'dv',           // domain validation
                'settings' => [
                    'min_tls_version' => '1.2',
                ],
                'bundle_method'   => 'ubiquitous',
                'wildcard'        => false,
            ],
            'custom_metadata' => [
                'created_by' => 'intake',
            ],
        ];

        $response = $this->request('POST', "zones/{$this->zoneId}/custom_hostnames", $payload);

        $result = $response['result'] ?? [];
        return [
            'id'                     => (string) ($result['id'] ?? ''),
            'hostname'               => (string) ($result['hostname'] ?? $hostname),
            'status'                 => (string) ($result['status'] ?? 'pending'),
            'ownership_verification' => (array) ($result['ownership_verification'] ?? []),
            'raw'                    => $result,
        ];
    }

    /**
     * Fetch the current state of a custom hostname.
     *
     * @return array{id:string, hostname:string, status:string, ssl:array, raw:array}
     *
     * @throws CloudflareException
     */
    public function getCustomHostname(string $cfHostnameId): array
    {
        $this->assertConfigured();

        if ($cfHostnameId === '') {
            throw new CloudflareException('cfHostnameId is required', 'invalid_input');
        }

        $response = $this->request('GET', "zones/{$this->zoneId}/custom_hostnames/{$cfHostnameId}");

        $result = $response['result'] ?? [];
        return [
            'id'       => (string) ($result['id'] ?? ''),
            'hostname' => (string) ($result['hostname'] ?? ''),
            'status'   => (string) ($result['status'] ?? ''),
            'ssl'      => (array) ($result['ssl'] ?? []),
            'raw'      => $result,
        ];
    }

    /**
     * Remove a custom hostname from Cloudflare.
     *
     * Idempotent on the caller's side: a 404 from CF means it's already
     * gone, which is a successful outcome here.
     *
     * @throws CloudflareException for non-404 failures
     */
    public function deleteCustomHostname(string $cfHostnameId): bool
    {
        $this->assertConfigured();

        if ($cfHostnameId === '') {
            return true; // nothing to delete
        }

        try {
            $this->request('DELETE', "zones/{$this->zoneId}/custom_hostnames/{$cfHostnameId}");
            return true;
        } catch (CloudflareException $e) {
            if ($e->httpStatus === 404) {
                // Already gone — treat as success.
                return true;
            }
            throw $e;
        }
    }

    /**
     * List custom hostnames on the zone. Pagination passes through to CF.
     *
     * @return array{hostnames: array, total_count: int}
     *
     * @throws CloudflareException
     */
    public function listCustomHostnames(int $page = 1, int $perPage = 50): array
    {
        $this->assertConfigured();

        $response = $this->request('GET', "zones/{$this->zoneId}/custom_hostnames?page={$page}&per_page={$perPage}");

        return [
            'hostnames'   => (array) ($response['result'] ?? []),
            'total_count' => (int) ($response['result_info']['total_count'] ?? 0),
        ];
    }

    // ──────────────────────────────────────────────────────────────────────
    // Internals
    // ──────────────────────────────────────────────────────────────────────

    /**
     * Send an HTTP request to the Cloudflare API and normalize the response.
     */
    private function request(string $method, string $path, array $body = []): array
    {
        $url = rtrim($this->apiBase, '/') . '/' . ltrim($path, '/');

        try {
            $request = Http::withToken($this->apiToken)
                ->timeout($this->httpTimeout)
                ->acceptJson()
                ->asJson();

            $response = match (strtoupper($method)) {
                'GET'    => $request->get($url),
                'POST'   => $request->post($url, $body),
                'PATCH'  => $request->patch($url, $body),
                'DELETE' => $request->delete($url),
                default  => throw new CloudflareException(
                    "unsupported method: {$method}",
                    'invalid_method'
                ),
            };
        } catch (\Illuminate\Http\Client\ConnectionException $e) {
            Log::warning('[Cloudflare] connection failure', [
                'method' => $method,
                'path'   => $path,
                'error'  => $e->getMessage(),
            ]);
            throw new CloudflareException(
                'Could not reach Cloudflare: ' . $e->getMessage(),
                'network',
                [],
                0,
                $e,
            );
        }

        $json = $response->json() ?? [];
        $httpStatus = $response->status();

        if (!$response->successful() || (isset($json['success']) && $json['success'] === false)) {
            $cfErrors = (array) ($json['errors'] ?? []);
            $errorCode = $this->classifyError($httpStatus, $cfErrors);
            $message = $this->formatErrorMessage($cfErrors, $response->body());

            Log::warning('[Cloudflare] API error', [
                'method'      => $method,
                'path'        => $path,
                'http_status' => $httpStatus,
                'error_code'  => $errorCode,
                'cf_errors'   => $cfErrors,
            ]);

            throw new CloudflareException($message, $errorCode, $cfErrors, $httpStatus);
        }

        return $json;
    }

    /**
     * Map HTTP status + CF error array to a machine-readable code.
     */
    private function classifyError(int $httpStatus, array $cfErrors): string
    {
        // Cloudflare often returns useful error codes in errors[].code
        // (e.g. 1004 for hostname already exists, 9106 for invalid token).
        $firstCfCode = (int) ($cfErrors[0]['code'] ?? 0);

        return match (true) {
            $httpStatus === 401                     => 'unauthorized',
            $httpStatus === 403                     => 'forbidden',
            $httpStatus === 404                     => 'not_found',
            $httpStatus === 429                     => 'rate_limited',
            $firstCfCode === 1004                   => 'hostname_taken',
            $firstCfCode === 1414                   => 'invalid_hostname',
            $httpStatus >= 500                      => 'cf_server_error',
            default                                 => 'unknown',
        };
    }

    /**
     * Build a human-readable error message from the CF errors array.
     */
    private function formatErrorMessage(array $cfErrors, string $rawBody): string
    {
        if (empty($cfErrors)) {
            $snippet = mb_substr($rawBody, 0, 200);
            return $snippet !== '' ? "Cloudflare API error: {$snippet}" : 'Cloudflare API error (no body)';
        }

        $messages = [];
        foreach ($cfErrors as $err) {
            $code = $err['code'] ?? '?';
            $msg  = $err['message'] ?? 'no message';
            $messages[] = "[{$code}] {$msg}";
        }
        return 'Cloudflare API error: ' . implode(' · ', $messages);
    }

    private function assertConfigured(): void
    {
        if (!$this->isConfigured()) {
            throw new CloudflareException(
                'Cloudflare not configured. Set CLOUDFLARE_API_TOKEN and CLOUDFLARE_ZONE_ID in .env.',
                'not_configured'
            );
        }
    }
}
