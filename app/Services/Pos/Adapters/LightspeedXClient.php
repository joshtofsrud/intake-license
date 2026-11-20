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
            // MARKER-POS-XSERIES-FAMILIES: where products are created and edited
            'products_api_base' => 'https://{domain_prefix}.retail.lightspeed.app/api/2026-10',
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
            if (is_array($r) && isset($r['id'])) {
                $items[] = $this->mapProduct($r);
            }
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


    /** One API product in the shape pullItems returns. */
    private function mapProduct(array $r): array
    {
        $codes = [];
        foreach ((array) ($r['product_codes'] ?? []) as $c) {
            if (is_array($c) && ! empty($c['code'])) {
                $codes[strtoupper((string) ($c['type'] ?? ''))] = (string) $c['code'];
            }
        }
        $price = $r['price_excluding_tax'] ?? $r['price_including_tax'] ?? null;
        $name = trim((string) ($r['name'] ?? '') . (filled($r['variant_name'] ?? null) && ! str_contains((string) ($r['name'] ?? ''), (string) $r['variant_name']) ? ' / ' . $r['variant_name'] : ''));

        return [
            'id'          => (string) $r['id'],
            'parent_id'   => isset($r['variant_parent_id']) ? (string) $r['variant_parent_id'] : null,
            'name'        => $name !== '' ? $name : null,
            'sku'         => filled($r['sku'] ?? null) ? (string) $r['sku'] : null,
            'upc'         => $codes['UPC'] ?? null,
            'ean'         => $codes['EAN'] ?? null,
            'mpn'         => filled($r['supplier_code'] ?? null) ? (string) $r['supplier_code'] : ($codes['MPN'] ?? null),
            'brand'       => is_array($r['brand'] ?? null) ? ($r['brand']['name'] ?? null) : (is_string($r['brand_name'] ?? null) ? $r['brand_name'] : null),
            'description' => array_key_exists('description', $r) ? (string) ($r['description'] ?? '') : null,
            'price_cents' => is_numeric($price) ? (int) round(((float) $price) * 100) : null,
            'active'      => empty($r['deleted_at']) && (($r['is_active'] ?? $r['active'] ?? true) !== false),
        ];
    }

    // ── MARKER-CONNECTED-POS-PUSH ───────────────────────────────

    public function registers(string $accessToken, string $accountId, array $context): array
    {
        return $this->named($this->get($accessToken, $this->api($this->ctxPrefix($context), 'registers'))['data'] ?? []);
    }

    public function users(string $accessToken, string $accountId, array $context): array
    {
        $out = [];
        foreach ((array) ($this->get($accessToken, $this->api($this->ctxPrefix($context), 'users'))['data'] ?? []) as $u) {
            if (is_array($u) && isset($u['id']) && empty($u['deleted_at'])) {
                $out[] = ['id' => (string) $u['id'], 'name' => (string) ($u['display_name'] ?? $u['username'] ?? $u['id'])];
            }
        }

        return $out;
    }

    public function taxes(string $accessToken, string $accountId, array $context): array
    {
        return $this->named($this->get($accessToken, $this->api($this->ctxPrefix($context), 'taxes'))['data'] ?? []);
    }

    /**
     * A closed register sale. Uses the long-standing register_sales endpoint, which
     * can answer 200 with an empty sale when a field is wrong — so the reply is
     * checked for its lines before it counts as done.
     */
    public function createSale(string $accessToken, string $accountId, array $context, array $sale): string
    {
        $products = [];
        foreach ($sale['lines'] as $l) {
            $products[] = [
                'product_id' => $l['item_id'],
                'quantity'   => (float) $l['qty'],
                'price'      => round($l['unit_cents'] / 100, 2),
                'tax'        => round(($l['tax_cents'] / max(1, abs($l['qty']))) / 100, 4),
                'tax_id'     => $sale['tax_id'],
                'status'     => 'CONFIRMED',
            ];
        }
        $payload = array_filter([
            'source_id'              => $sale['reference'],
            'register_id'            => $sale['register_id'],
            'user_id'                => $sale['user_id'],
            'customer_id'            => $sale['customer_id'] ?? null,
            'state'                  => 'closed',
            'status'                 => 'CLOSED',
            'note'                   => $sale['note'],
            'return_for'             => $sale['return_for'] ?? null,
            'register_sale_products' => $products,
            'register_sale_payments' => [[
                'retailer_payment_type_id' => $sale['payment_type_id'],
                'payment_date'             => now()->utc()->format('Y-m-d H:i:s'),
                'amount'                   => round($sale['total_cents'] / 100, 2),
            ]],
        ], fn ($v) => $v !== null);

        $res = $this->send('post', $accessToken, $this->root($this->ctxPrefix($context)) . '/register_sales', $payload);
        $rs = $res['register_sale'] ?? $res['data'] ?? $res;
        if (empty($rs['id']) || empty($rs['register_sale_products'] ?? [1])) {
            throw new \RuntimeException('The POS accepted the sale but recorded no items — nothing was saved there. Check the register, user, payment type and tax chosen under Connected POS.');
        }

        return (string) $rs['id'];
    }

    /**
     * MARKER-POS-XSERIES-FAMILIES: products are created and edited through the
     * date-versioned product-family API (the old /api/products endpoints were
     * removed). A plain item is a STANDARD family with one product: the family
     * holds name and description, the product holds codes and price.
     */
    public function createItem(string $accessToken, string $accountId, array $context, array $fields): string
    {
        $base = $this->productsBase($this->ctxPrefix($context));
        $family = array_filter([
            'name'           => (string) ($fields['name'] ?: ($fields['sku'] ?? 'Item')),
            'classification' => 'STANDARD',
            'description'    => filled($fields['description'] ?? null) ? (string) $fields['description'] : null,
            'products'       => [['codes' => $this->codes($fields)]],
        ], fn ($v) => $v !== null);

        $res = $this->send('post', $accessToken, $base . '/product_families', $family);
        $id = $res['data']['product_ids'][0] ?? null;
        if (! $id) {
            throw new RuntimeException('The POS did not return the new item.');
        }
        if (isset($fields['price_cents']) && $fields['price_cents'] !== null) {
            $this->send('patch', $accessToken, $base . '/products/' . rawurlencode((string) $id), [
                'prices' => ['price_excluding_tax' => number_format($fields['price_cents'] / 100, 2, '.', '')],
            ]);
        }

        return (string) $id;
    }

    public function updateItem(string $accessToken, string $accountId, array $context, string $itemId, array $fields): void
    {
        $base = $this->productsBase($this->ctxPrefix($context));

        // family-level: name, description
        $fam = array_intersect_key($fields, ['name' => 1, 'description' => 1]);
        if ($fam) {
            $familyId = $this->get($accessToken, $base . '/products/' . rawurlencode($itemId) . '/family')['data']['id'] ?? null;
            if (! $familyId) {
                throw new RuntimeException('The POS did not say which product family this item belongs to.');
            }
            $this->send('patch', $accessToken, $base . '/product_families/' . rawurlencode((string) $familyId), $fam);
        }

        // product-level: codes are replaced as a whole, so send the full set
        if (array_key_exists('sku', $fields) || array_key_exists('upc', $fields)) {
            $cur = $this->getItem($accessToken, $accountId, $context, $itemId) ?? [];
            $codes = $this->codes([
                'sku' => array_key_exists('sku', $fields) ? $fields['sku'] : ($cur['sku'] ?? null),
                'upc' => array_key_exists('upc', $fields) ? $fields['upc'] : ($cur['upc'] ?? null),
                'ean' => $cur['ean'] ?? null,
            ]);
            $this->send('patch', $accessToken, $base . '/products/' . rawurlencode($itemId), ['codes' => $codes]);
        }
    }

    public function getItem(string $accessToken, string $accountId, array $context, string $itemId): ?array
    {
        try {
            $d = $this->get($accessToken, $this->api($this->ctxPrefix($context), 'products/' . rawurlencode($itemId)))['data'] ?? null;
        } catch (\RuntimeException $e) {
            if (str_contains($e->getMessage(), 'HTTP 404')) {
                return null;
            }
            throw $e;
        }

        return is_array($d) && isset($d['id']) ? $this->mapProduct($d) : null;
    }

    /** SKU as the store's own code, plus any barcodes. At least one code is required. */
    private function codes(array $f): array
    {
        $codes = [];
        if (filled($f['sku'] ?? null)) {
            $codes[] = ['type' => 'CUSTOM', 'code' => (string) $f['sku']];
        }
        foreach (['upc' => 'UPC', 'ean' => 'EAN'] as $k => $type) {
            if (filled($f[$k] ?? null)) {
                $codes[] = ['type' => $type, 'code' => (string) $f[$k]];
            }
        }
        if (! $codes) {
            $codes[] = ['type' => 'CUSTOM', 'code' => 'INTAKE-' . strtoupper(\Illuminate\Support\Str::random(8))];
        }

        return $codes;
    }

    /** The products API base (master admin), with the store's prefix filled in. */
    private function productsBase(string $prefix): string
    {
        $tpl = (string) ($this->cfg->products_api_base ?: self::defaults()['products_api_base']);

        return rtrim($this->fill($tpl, $prefix), '/');
    }

    private function ctxPrefix(array $context): string
    {
        return $this->prefix((string) ($context['domain_prefix'] ?? ''));
    }

    /** The store's /api root (for the older endpoints), from the configured 2.x base. */
    private function root(string $prefix): string
    {
        return preg_replace('#/api/[^/]+/?$#', '/api', rtrim($this->fill((string) $this->cfg->api_base, $prefix), '/'));
    }

    private function send(string $method, string $token, string $url, array $json): array
    {
        $go = fn () => Http::withToken($token)->acceptJson()->asJson()->timeout(30)->{$method}($url, $json);
        $res = $go();
        if ($res->status() === 429) {
            sleep(min(5, max(1, (int) ($res->header('Retry-After') ?: 2))));
            $res = $go();
        }

        return $this->ok($res);
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
            throw new RuntimeException('The POS answered with an error (HTTP ' . $res->status() . '): ' . (is_string($j['message'] ?? null) ? $j['message'] : (is_string($j['error'] ?? null) ? $j['error'] : json_encode($j['details'] ?? $j['errors'] ?? ''))));
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
