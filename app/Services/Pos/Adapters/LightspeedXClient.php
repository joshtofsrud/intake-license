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
            'scopes'        => 'retailer:read outlets:read registers:read users:read taxes:read payment_types:read products:read products:write suppliers:read suppliers:write inventory:read inventory:write customers:read customers:write sales:read sales:write webhooks',
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

    /** MARKER-POS-CUSTOMERS-IN */
    public function pullCustomers(string $accessToken, string $accountId, array $context, ?string $cursor): array
    {
        $prefix = $this->prefix((string) ($context['domain_prefix'] ?? ''));
        $body = $this->get($accessToken, $this->api($prefix, 'customers'), ['after' => (int) ($cursor ?? 0), 'page_size' => 1000, 'deleted' => 'true']);
        $rows = (array) ($body['data'] ?? []);
        $max = $body['version']['max'] ?? null;

        $out = [];
        foreach ($rows as $r) {
            if (! is_array($r) || empty($r['id'])) {
                continue;
            }
            $out[] = [
                'id'           => (string) $r['id'],
                'first_name'   => trim((string) ($r['first_name'] ?? '')),
                'last_name'    => trim((string) ($r['last_name'] ?? '')),
                'email'        => trim((string) ($r['email'] ?? '')),
                'phone'        => trim((string) (($r['mobile'] ?? '') ?: ($r['phone'] ?? ''))),
                'address1'     => trim((string) ($r['physical_address_1'] ?? $r['physical_address1'] ?? '')),
                'address2'     => trim((string) ($r['physical_address_2'] ?? $r['physical_address2'] ?? '')),
                'city'         => trim((string) ($r['physical_city'] ?? '')),
                'state'        => trim((string) ($r['physical_state'] ?? '')),
                'postcode'     => trim((string) ($r['physical_postcode'] ?? '')),
                'country'      => trim((string) ($r['physical_country_id'] ?? '')),
                'no_marketing' => ! empty($r['do_not_email']),
                'deleted'      => ! empty($r['deleted_at']),
            ];
        }

        return ['customers' => $out, 'cursor' => $max !== null ? (string) $max : $cursor, 'more' => count($rows) > 0];
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
        // MARKER-POS-REGISTER-PER-OUTLET: each register with the outlet it belongs to
        $out = [];
        foreach ((array) ($this->get($accessToken, $this->api($this->ctxPrefix($context), 'registers'))['data'] ?? []) as $r) {
            if (is_array($r) && isset($r['id']) && empty($r['deleted_at'])) {
                $out[] = ['id' => (string) $r['id'], 'name' => (string) ($r['name'] ?? $r['id']), 'outlet_id' => isset($r['outlet_id']) ? (string) $r['outlet_id'] : null];
            }
        }

        return $out;
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
            throw new \RuntimeException('Your Connected POS accepted the sale but recorded no items — nothing was saved there. Check the register, user, payment type and tax chosen under Connected POS.');
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

        // MARKER-POS-X-CREATE-SAFE: already there (made by the shop, or by an earlier try that
        // stopped part-way)? Link to it rather than make a duplicate.
        if (filled($fields['sku'] ?? null)) {
            // the products lookup answers 404 "product not found" when no product has the SKU,
            // which is the normal case for a new item, not an error
            try {
                $found = $this->get($accessToken, $base . '/products', ['sku' => (string) $fields['sku']])['data'] ?? [];
            } catch (RuntimeException $e) {
                if (! str_contains($e->getMessage(), 'HTTP 404')) {
                    throw $e;
                }
                $found = [];
            }
            $rows = is_array($found) ? (array_is_list($found) ? $found : [$found]) : [];
            foreach ($rows as $hit) {
                // only a product whose own SKU or codes really match — never trust the filter alone
                $codes = array_map(fn ($c) => (string) ($c['code'] ?? ''), (array) ($hit['codes'] ?? $hit['product_codes'] ?? []));
                if (is_array($hit) && ! empty($hit['id']) && ((string) ($hit['sku'] ?? '') === (string) $fields['sku'] || in_array((string) $fields['sku'], $codes, true))) {
                    return (string) $hit['id'];
                }
            }
        }

        $name = (string) ($fields['name'] ?: ($fields['sku'] ?? 'Item'));
        $family = fn (string $n) => array_filter([
            'name'           => $n,
            'classification' => 'STANDARD',
            'description'    => filled($fields['description'] ?? null) ? (string) $fields['description'] : null,
            'products'       => [['codes' => $this->codes($fields)]],
        ], fn ($v) => $v !== null);

        try {
            $res = $this->send('post', $accessToken, $base . '/product_families', $family($name));
        } catch (RuntimeException $e) {
            // product names must be unique in this POS; Intake may hold several items with one name
            if (! str_contains(strtolower($e->getMessage()), 'name is already in use')) {
                throw $e;
            }
            $res = $this->send('post', $accessToken, $base . '/product_families', $family($name . ' (' . ($fields['sku'] ?: substr(md5($name . microtime()), 0, 6)) . ')'));
        }
        $id = $res['data']['product_ids'][0] ?? null;
        if (! $id) {
            throw new RuntimeException('Your Connected POS did not return the new item.');
        }

        return (string) $id; // price is set separately (setItemPrice), once the POS has the product ready
    }

    /** A just-made product can answer "not found" for a moment: wait 1, 2, then 4 seconds and try again. */
    public function setItemPrice(string $accessToken, string $accountId, array $context, string $itemId, int $priceCents): void
    {
        $url = $this->productsBase($this->ctxPrefix($context)) . '/products/' . rawurlencode($itemId);
        $body = ['prices' => ['price_excluding_tax' => number_format($priceCents / 100, 2, '.', '')]];
        foreach ([1, 2, 4, 0] as $wait) {
            try {
                $this->send('patch', $accessToken, $url, $body);
                return;
            } catch (RuntimeException $e) {
                if ($wait === 0 || ! str_contains($e->getMessage(), 'HTTP 404')) {
                    throw $e;
                }
                sleep($wait);
            }
        }
    }

    public function uploadImage(string $accessToken, string $accountId, array $context, string $itemId, string $filePath, string $fileName): void
    {
        $url = $this->api($this->ctxPrefix($context), 'products/' . rawurlencode($itemId) . '/actions/image_upload');
        $res = Http::withToken($accessToken)->acceptJson()->timeout(60)
            ->attach('image', file_get_contents($filePath), $fileName)->post($url);
        $this->ok($res);
    }

    public function updateItem(string $accessToken, string $accountId, array $context, string $itemId, array $fields): void
    {
        $base = $this->productsBase($this->ctxPrefix($context));

        // family-level: name, description
        $fam = array_intersect_key($fields, ['name' => 1, 'description' => 1]);
        if ($fam) {
            $familyId = $this->get($accessToken, $base . '/products/' . rawurlencode($itemId) . '/family')['data']['id'] ?? null;
            if (! $familyId) {
                throw new RuntimeException('Your Connected POS did not say which product family this item belongs to.');
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

    /**
     * MARKER-POS-ITEM-DETAILS. The product family holds brand, category and inventory
     * tracking; the product holds its suppliers (supplier, code, supply price) and weight.
     * Brand, category and supplier are matched by name and created when missing.
     */
    public const GONE = 'This item was deleted in your Connected POS, so its details can\'t be set. Send it again from its item page if you still sell it.';

    public function setItemDetails(string $accessToken, string $accountId, array $context, string $itemId, array $details): void
    {
        $prefix = $this->ctxPrefix($context);
        $base = $this->productsBase($prefix);

        $fam = [];
        if (filled($details['brand'] ?? null)) {
            $fam['brand_id'] = $this->idFor($accessToken, $prefix, 'brands', (string) $details['brand']);
        }
        if (filled($details['category'] ?? null)) {
            $fam['category_id'] = $this->idFor($accessToken, $prefix, 'product_categories', (string) $details['category']);
        }
        if (array_key_exists('track_inventory', $details)) {
            $fam['track_inventory'] = (bool) $details['track_inventory'];
        }
        if ($fam) {
            $familyId = $this->get($accessToken, $base . '/products/' . rawurlencode($itemId) . '/family')['data']['id'] ?? null;
            if (! $familyId) {
                throw new RuntimeException('Your Connected POS did not say which product family this item belongs to.');
            }
            try {
                $this->send('patch', $accessToken, $base . '/product_families/' . rawurlencode((string) $familyId), $fam);
            } catch (RuntimeException $e) {
                // the POS still lists the product but won't change it: it was deleted there
                if (str_contains($e->getMessage(), 'HTTP 404')) {
                    throw new RuntimeException(self::GONE);
                }
                throw $e;
            }
        }

        $prod = [];
        $later = null;
        $supplierId = null;
        if (filled($details['supplier'] ?? null)) {
            // a supplier problem (like a missing permission) must not cost the item its weight
            try {
                $supplierId = $this->idFor($accessToken, $prefix, 'suppliers', (string) $details['supplier']);
            } catch (RuntimeException $e) {
                $later = str_contains($e->getMessage(), 'missing a permission')
                    ? 'Suppliers need a permission Intake does not have yet. Click Disconnect, then Connect again, then Fill in missing details.'
                    : 'Supplier not set: ' . $e->getMessage();
            }
        }
        if ($supplierId) {
            $sup = ['supplier_id' => $supplierId];
            if (filled($details['supplier_code'] ?? null)) {
                $sup['code'] = (string) $details['supplier_code'];
            }
            if (($details['cost_cents'] ?? null) !== null) {
                $sup['price'] = number_format(((int) $details['cost_cents']) / 100, 2, '.', '');
            }
            $prod['suppliers'] = [$sup];
        }
        if (($details['weight_oz'] ?? null) !== null && (float) $details['weight_oz'] > 0) {
            $prod['measurements'] = ['weight' => round((float) $details['weight_oz'], 2), 'weight_unit' => 'OZ'];
        }
        if ($prod) {
            $this->send('patch', $accessToken, $base . '/products/' . rawurlencode($itemId), $prod);
        }
        if ($later) {
            throw new RuntimeException($later);
        }
    }

    /**
     * A brand, category or supplier id by name (case and spacing ignored), created when
     * missing. Lists are read once and kept 30 minutes per store, so a run of item sends
     * doesn't re-read them for every item.
     */
    private function idFor(string $token, string $prefix, string $kind, string $name): string
    {
        $name = trim(preg_replace('/\s+/', ' ', $name));
        $key = 'posx:' . $prefix . ':' . $kind;
        $norm = fn ($n) => mb_strtolower(trim(preg_replace('/\s+/', ' ', (string) $n)));
        $map = \Illuminate\Support\Facades\Cache::remember($key, 1800, fn () => $this->nameMap($token, $prefix, $kind));
        if (isset($map[$norm($name)])) {
            return $map[$norm($name)];
        }

        $base = $this->productsBase($prefix);
        if ($kind === 'product_categories') {
            $this->send('post', $token, $base . '/product_categories/bulk', ['categories' => [['name' => $name]]]);
            $id = null;
        } else {
            $res = $this->send('post', $token, $base . '/' . $kind, ['name' => $name]);
            $d = $res['data'] ?? null;
            $id = is_string($d) ? $d : (is_array($d) ? ($d['id'] ?? null) : null);
        }
        // read the list again: confirms the new one, and covers a create that answers without its id
        \Illuminate\Support\Facades\Cache::forget($key);
        $map = \Illuminate\Support\Facades\Cache::remember($key, 1800, fn () => $this->nameMap($token, $prefix, $kind));
        $id = $map[$norm($name)] ?? $id;
        if (! $id) {
            $what = ['brands' => 'brand', 'product_categories' => 'category', 'suppliers' => 'supplier'][$kind] ?? $kind;
            throw new RuntimeException("Your Connected POS did not create the {$what} \"{$name}\".");
        }

        return (string) $id;
    }

    /** @return array<string,string> lower-cased name => id, every page */
    private function nameMap(string $token, string $prefix, string $kind): array
    {
        $out = [];
        $after = null;
        for ($page = 0; $page < 50; $page++) {
            $q = ['page_size' => 1000] + ($after !== null ? ['after' => $after] : []);
            $res = $this->get($token, $this->productsBase($prefix) . '/' . $kind, $q);
            $rows = $res['data'] ?? [];
            if (isset($rows['categories'])) {
                $rows = $rows['categories'];
            }
            if (! is_array($rows) || ! $rows) {
                break;
            }
            $max = $after;
            foreach ($rows as $r) {
                if (! is_array($r) || empty($r['id']) || ! empty($r['deleted_at'])) {
                    continue;
                }
                $k = mb_strtolower(trim(preg_replace('/\s+/', ' ', (string) ($r['name'] ?? ''))));
                if ($k !== '' && ! isset($out[$k])) {
                    $out[$k] = (string) $r['id'];
                }
                if (isset($r['version']) && ($max === null || (int) $r['version'] > $max)) {
                    $max = (int) $r['version'];
                }
            }
            $next = $res['version']['max'] ?? $max;
            if ($next === null || $next === $after || count($rows) < 1000) {
                break;
            }
            $after = (int) $next;
        }

        return $out;
    }

    /**
     * MARKER-POS-CUSTOMERS: search by email, then phone; create when nobody matches.
     * The POS needs a first and last name, so a missing one is filled from the other or the email.
     */
    public function findOrCreateCustomer(string $accessToken, string $accountId, array $context, array $c): ?string
    {
        $prefix = $this->ctxPrefix($context);
        foreach (['email' => $c['email'] ?? null, 'phone' => $c['phone'] ?? null] as $field => $val) {
            if (! filled($val)) {
                continue;
            }
            try {
                $rows = $this->get($accessToken, $this->api($prefix, 'search'), ['type' => 'customers', $field => (string) $val])['data'] ?? [];
            } catch (RuntimeException $e) {
                if (! str_contains($e->getMessage(), 'HTTP 404')) {
                    throw $e;
                }
                $rows = [];
            }
            foreach ((array) $rows as $r) {
                if (is_array($r) && ! empty($r['id']) && empty($r['deleted_at'])) {
                    return (string) $r['id'];
                }
            }
        }

        $first = trim((string) ($c['first_name'] ?? ''));
        $last  = trim((string) ($c['last_name'] ?? ''));
        if ($first === '' && $last === '') {
            $first = (string) strstr((string) ($c['email'] ?? 'Online'), '@', true) ?: 'Online';
            $last = 'Customer';
        }
        $body = array_filter([
            'first_name'         => $first !== '' ? $first : $last,
            'last_name'          => $last !== '' ? $last : $first,
            'email'              => $c['email'] ?? null,
            'phone'              => $c['phone'] ?? null,
            'physical_address1'  => $c['address1'] ?? null,
            'physical_address2'  => $c['address2'] ?? null,
            'physical_city'      => $c['city'] ?? null,
            'physical_state'     => $c['state'] ?? null,
            'physical_postcode'  => $c['postcode'] ?? null,
            'physical_country_id'=> filled($c['country'] ?? null) && strlen((string) $c['country']) === 2 ? strtoupper((string) $c['country']) : null,
            'note'               => 'Added by Intake from an online order.',
        ], fn ($v) => $v !== null && $v !== '');
        $res = $this->send('post', $accessToken, $this->api($prefix, 'customers'), $body);
        $id = $res['data']['id'] ?? $res['id'] ?? null;
        if (! $id) {
            throw new RuntimeException('Your Connected POS did not return the new customer.');
        }

        return (string) $id;
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

    /** SKU as the store's own code, plus any barcodes — each value once (the POS refuses repeats). At least one code is required. */
    private function codes(array $f): array
    {
        $codes = [];
        $seen = [];
        foreach (['upc' => 'UPC', 'ean' => 'EAN', 'sku' => 'CUSTOM'] as $k => $type) {
            $v = trim((string) ($f[$k] ?? ''));
            if ($v !== '' && ! isset($seen[$v])) {
                $seen[$v] = true;
                $codes[] = ['type' => $type, 'code' => $v];
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
            throw new RuntimeException('Your Connected POS did not say which store this is. Try connecting again.');
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
            throw new RuntimeException('Your Connected POS refused the sign-in: ' . $why);
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
            throw new RuntimeException('Your Connected POS no longer accepts Intake\'s access. Reconnect to fix it.');
        }
        if ($res->status() === 403) {
            throw new RuntimeException('Your Connected POS refused this request — the connection is missing a permission. Check the scopes in master admin, then reconnect.');
        }
        if (! $res->successful()) {
            $j = $res->json() ?: [];
            throw new RuntimeException('Your Connected POS answered with an error (HTTP ' . $res->status() . '): ' . (is_string($j['message'] ?? null) ? $j['message'] : (is_string($j['error'] ?? null) ? $j['error'] : json_encode($j['details'] ?? $j['errors'] ?? ''))));
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
