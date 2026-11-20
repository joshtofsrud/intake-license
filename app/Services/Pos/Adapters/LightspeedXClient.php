<?php

namespace App\Services\Pos\Adapters;

use App\Models\PlatformPosAdapter;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * MARKER-POS-XSERIES: Lightspeed Retail X-Series.
 *
 * OAuth 2 like R-Series, but every store has its own domain prefix: the POS
 * sends it back on the callback, tokens and API calls go to that store's own
 * host, and refresh tokens rotate on every refresh. The prefix is saved in the
 * connection's context. Master-admin URLs use a {domain_prefix} placeholder.
 * Scopes are mandatory on new connections.
 */
class LightspeedXClient implements PosAdapter
{
    public function __construct(private PlatformPosAdapter $cfg) {}

    public static function code(): string
    {
        return 'lightspeed-x';
    }

    public static function defaults(): array
    {
        return [
            'label'         => 'Lightspeed Retail (X-Series)',
            'scopes'        => 'retailer:read outlets:read payment_types:read products:read products:write inventory:read inventory:write customers:read customers:write sales:read sales:write webhooks',
            'authorize_url' => 'https://secure.retail.lightspeed.app/connect',
            'token_url'     => 'https://{domain_prefix}.retail.lightspeed.app/api/1.0/token',
            'api_base'      => 'https://{domain_prefix}.retail.lightspeed.app/api/2.0',
        ];
    }

    public static function connectStyle(): string
    {
        return 'oauth';
    }

    public static function capabilities(): array
    {
        return [
            'locations'      => true,
            'payment_types'  => true,
            'items'          => true,
            'stock'          => true,
            'customers'      => true,
            'customer_write' => true,
            'sales_write'    => true,
            'refunds'        => true,
            'webhooks'       => true,
        ];
    }

    public function authorizeUrl(string $state, string $redirectUri): string
    {
        return $this->cfg->authorize_url . '?' . http_build_query([
            'response_type' => 'code',
            'client_id'     => $this->cfg->client_id,
            'redirect_uri'  => $redirectUri,
            'state'         => $state,
            'scope'         => (string) $this->cfg->scopes,
        ], '', '&', PHP_QUERY_RFC3986);
    }

    public function exchangeCode(string $code, string $redirectUri, array $callback = []): array
    {
        $prefix = $this->prefix((string) ($callback['domain_prefix'] ?? ''));
        $tok = $this->tokenCall($prefix, [
            'code'          => $code,
            'client_id'     => $this->cfg->client_id,
            'client_secret' => $this->cfg->client_secret,
            'grant_type'    => 'authorization_code',
            'redirect_uri'  => $redirectUri,
        ]);
        $tok['context'] = ['domain_prefix' => $this->prefix((string) ($tok['domain_prefix'] ?? $prefix))];
        unset($tok['domain_prefix']);

        return $tok;
    }

    public function refreshToken(string $refreshToken, array $context = []): array
    {
        $tok = $this->tokenCall($this->prefix((string) ($context['domain_prefix'] ?? '')), [
            'refresh_token' => $refreshToken,
            'client_id'     => $this->cfg->client_id,
            'client_secret' => $this->cfg->client_secret,
            'grant_type'    => 'refresh_token',
        ]);
        unset($tok['domain_prefix']);

        return $tok;
    }

    public function account(string $accessToken, array $context = []): array
    {
        $prefix = $this->prefix((string) ($context['domain_prefix'] ?? ''));
        $d = $this->get($accessToken, $this->api($prefix, 'retailer'))['data'] ?? [];

        return [
            'id'   => (string) ($d['id'] ?? $prefix),
            'name' => (string) ($d['name'] ?? $d['domain_prefix'] ?? $prefix),
        ];
    }

    public function locations(string $accessToken, string $accountId, array $context = []): array
    {
        return $this->named($this->get($accessToken, $this->api($this->prefix((string) ($context['domain_prefix'] ?? '')), 'outlets'))['data'] ?? []);
    }

    public function paymentTypes(string $accessToken, string $accountId, array $context = []): array
    {
        return $this->named($this->get($accessToken, $this->api($this->prefix((string) ($context['domain_prefix'] ?? '')), 'payment_types'))['data'] ?? []);
    }

    /**
     * MARKER-CONNECTED-POS-CATALOG: products by version. The API returns rows newer
     * than `after` with the highest version seen; an empty page means caught up.
     */
    public function pullItems(string $accessToken, string $accountId, array $context, ?string $cursor): array
    {
        $prefix = $this->prefix((string) ($context['domain_prefix'] ?? ''));
        $body = $this->get($accessToken, $this->api($prefix, 'products'), ['after' => (int) ($cursor ?? 0), 'page_size' => 500]);
        $rows = (array) ($body['data'] ?? []);
        $max = $body['version']['max'] ?? null;

        $items = [];
        foreach ($rows as $r) {
            if (! is_array($r) || ! isset($r['id'])) {
                continue;
            }
            $codes = [];
            foreach ((array) ($r['product_codes'] ?? []) as $c) {
                if (is_array($c) && ! empty($c['code'])) {
                    $codes[strtoupper((string) ($c['type'] ?? ''))] = (string) $c['code'];
                }
            }
            $price = $r['price_excluding_tax'] ?? $r['price_including_tax'] ?? null;
            $name = trim((string) ($r['name'] ?? '') . (filled($r['variant_name'] ?? null) && ! str_contains((string) ($r['name'] ?? ''), (string) $r['variant_name']) ? ' / ' . $r['variant_name'] : ''));
            $items[] = [
                'id'          => (string) $r['id'],
                'parent_id'   => isset($r['variant_parent_id']) ? (string) $r['variant_parent_id'] : null,
                'name'        => $name !== '' ? $name : null,
                'sku'         => filled($r['sku'] ?? null) ? (string) $r['sku'] : null,
                'upc'         => $codes['UPC'] ?? null,
                'ean'         => $codes['EAN'] ?? null,
                'mpn'         => filled($r['supplier_code'] ?? null) ? (string) $r['supplier_code'] : ($codes['MPN'] ?? null),
                'brand'       => is_array($r['brand'] ?? null) ? ($r['brand']['name'] ?? null) : (is_string($r['brand_name'] ?? null) ? $r['brand_name'] : null),
                'price_cents' => is_numeric($price) ? (int) round(((float) $price) * 100) : null,
                'active'      => empty($r['deleted_at']) && (($r['is_active'] ?? $r['active'] ?? true) !== false),
            ];
        }

        return ['items' => $items, 'cursor' => $max !== null ? (string) $max : $cursor, 'more' => count($rows) > 0];
    }

    public function pullStock(string $accessToken, string $accountId, array $context, ?string $cursor): array
    {
        $prefix = $this->prefix((string) ($context['domain_prefix'] ?? ''));
        $body = $this->get($accessToken, $this->api($prefix, 'inventory'), ['after' => (int) ($cursor ?? 0), 'page_size' => 1000]);
        $data = (array) ($body['data'] ?? []);
        $max = $body['version']['max'] ?? null;

        $rows = [];
        foreach ($data as $r) {
            if (! is_array($r) || ! isset($r['product_id'], $r['outlet_id'])) {
                continue;
            }
            $q = $r['inventory_level'] ?? $r['current_amount'] ?? $r['count'] ?? 0;
            $rows[] = ['item_id' => (string) $r['product_id'], 'location_id' => (string) $r['outlet_id'], 'qty' => (int) floor((float) $q)];
        }

        return ['rows' => $rows, 'cursor' => $max !== null ? (string) $max : $cursor, 'more' => count($data) > 0];
    }

    // ── internals ───────────────────────────────────────────────

    /** A store's prefix goes into a hostname, so it must be a plain label. */
    private function prefix(string $p): string
    {
        $p = strtolower(trim($p));
        if (! preg_match('/^[a-z0-9][a-z0-9-]{0,62}$/', $p)) {
            throw new RuntimeException('The POS did not say which store this is. Try connecting again.');
        }

        return $p;
    }

    private function fill(string $template, string $prefix): string
    {
        return str_replace('{domain_prefix}', $prefix, $template);
    }

    private function api(string $prefix, string $resource): string
    {
        return rtrim($this->fill((string) $this->cfg->api_base, $prefix), '/') . '/' . $resource;
    }

    private function tokenCall(string $prefix, array $form): array
    {
        $res = Http::asForm()->acceptJson()->timeout(20)->post($this->fill((string) $this->cfg->token_url, $prefix), $form);
        $j = $res->json() ?: [];
        if (! $res->successful() || empty($j['access_token'])) {
            $why = $j['error_description'] ?? $j['error'] ?? $j['message'] ?? ('HTTP ' . $res->status());
            throw new RuntimeException('The POS refused the sign-in: ' . $why);
        }

        return [
            'access_token'  => (string) $j['access_token'],
            'refresh_token' => isset($j['refresh_token']) ? (string) $j['refresh_token'] : null,
            'expires_in'    => isset($j['expires_in']) ? (int) $j['expires_in'] : null,
            'domain_prefix' => $j['domain_prefix'] ?? null,
        ];
    }

    private function get(string $token, string $url, array $query = []): array
    {
        $send = fn () => Http::withToken($token)->acceptJson()->timeout(20)->get($url, $query);
        $res = $send();
        if ($res->status() === 429) {
            sleep(min(5, max(1, (int) ($res->header('Retry-After') ?: 2))));
            $res = $send();
        }

        return $this->ok($res);
    }

    private function ok(Response $res): array
    {
        if ($res->status() === 401) {
            throw new RuntimeException('The POS no longer accepts Intake\'s access. Reconnect to fix it.');
        }
        if ($res->status() === 403) {
            throw new RuntimeException('The POS refused this request — the connection is missing a permission. Check the scopes in master admin, then reconnect.');
        }
        if (! $res->successful()) {
            $j = $res->json() ?: [];
            throw new RuntimeException('The POS answered with an error: ' . ($j['message'] ?? $j['error'] ?? ('HTTP ' . $res->status())));
        }

        return $res->json() ?: [];
    }

    /** @return list<array{id: string, name: string}> */
    private function named(array $rows): array
    {
        $out = [];
        foreach ($rows as $r) {
            if (! is_array($r) || ! isset($r['id']) || ! empty($r['deleted_at'])) {
                continue;
            }
            $out[] = ['id' => (string) $r['id'], 'name' => (string) ($r['name'] ?? $r['id'])];
        }

        return $out;
    }
}
