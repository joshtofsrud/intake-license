<?php

namespace App\Services\Pos\Adapters;

use App\Models\PlatformPosAdapter;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * MARKER-POS-CONNECT: Lightspeed Retail R-Series.
 *
 * OAuth 2 authorisation code flow; one Intake app, each shop approves it from
 * its own account. Access tokens are short-lived and refreshed by the
 * connection service. Endpoints and scopes come from master admin, seeded
 * from defaults() below, so a URL change at the POS is a settings edit.
 *
 * Rate limit is a per-account leaky bucket where writes cost far more than
 * reads; a 429 is retried once after the server's Retry-After.
 */
class LightspeedRClient implements PosAdapter
{
    public function __construct(private PlatformPosAdapter $cfg) {}

    public static function code(): string
    {
        return 'lightspeed-r';
    }

    public static function defaults(): array
    {
        return [
            'label'         => 'Lightspeed Retail (R-Series)',
            'scopes'        => 'employee:inventory employee:customers employee:register employee:register_refund',
            'authorize_url' => 'https://cloud.lightspeedapp.com/oauth/authorize.php',
            'token_url'     => 'https://cloud.lightspeedapp.com/oauth/access_token.php',
            'api_base'      => 'https://api.lightspeedapp.com/API/V3/Account',
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
            'webhooks'       => false, // polled on change timestamps instead
        ];
    }

    public function authorizeUrl(string $state, string $redirectUri): string
    {
        return $this->cfg->authorize_url . '?' . http_build_query([
            'response_type' => 'code',
            'client_id'     => $this->cfg->client_id,
            'scope'         => (string) $this->cfg->scopes,
            'state'         => $state,
        ], '', '&', PHP_QUERY_RFC3986);
    }

    public function exchangeCode(string $code, string $redirectUri, array $callback = []): array
    {
        return $this->tokenCall([
            'client_id'     => $this->cfg->client_id,
            'client_secret' => $this->cfg->client_secret,
            'code'          => $code,
            'grant_type'    => 'authorization_code',
            'redirect_uri'  => $redirectUri,
        ]);
    }

    public function refreshToken(string $refreshToken, array $context = []): array
    {
        return $this->tokenCall([
            'client_id'     => $this->cfg->client_id,
            'client_secret' => $this->cfg->client_secret,
            'refresh_token' => $refreshToken,
            'grant_type'    => 'refresh_token',
        ]);
    }

    public function account(string $accessToken, array $context = []): array
    {
        $body = $this->get($accessToken, rtrim($this->cfg->api_base, '/') . '.json');
        $acct = $this->rows($body['Account'] ?? null)[0] ?? null;
        if (! $acct || ! isset($acct['accountID'])) {
            throw new RuntimeException('Your Connected POS did not return an account for this login.');
        }

        return ['id' => (string) $acct['accountID'], 'name' => (string) ($acct['name'] ?? '')];
    }

    public function locations(string $accessToken, string $accountId, array $context = []): array
    {
        $body = $this->get($accessToken, $this->url($accountId, 'Shop.json'), ['limit' => 100]);

        return $this->named($this->rows($body['Shop'] ?? null), 'shopID');
    }

    public function paymentTypes(string $accessToken, string $accountId, array $context = []): array
    {
        $body = $this->get($accessToken, $this->url($accountId, 'PaymentType.json'), ['limit' => 100]);

        return $this->named($this->rows($body['PaymentType'] ?? null), 'paymentTypeID');
    }

    /**
     * MARKER-CONNECTED-POS-CATALOG: items changed since a timestamp, paged by offset.
     * The cursor holds the window start, the offset inside it and the newest
     * timestamp seen, so the next run starts where this one finished.
     */
    public function pullItems(string $accessToken, string $accountId, array $context, ?string $cursor): array
    {
        $c = $this->cursor($cursor);
        $q = ['limit' => 100, 'offset' => $c['offset'], 'sort' => 'timeStamp', 'load_relations' => '["ItemPrices"]'];
        if ($c['since']) {
            $q['timeStamp'] = '>,' . $c['since'];
        }
        $body = $this->get($accessToken, $this->url($accountId, 'Item.json'), $q);
        $rows = $this->rows($body['Item'] ?? null);

        $items = [];
        foreach ($rows as $r) {
            if (! isset($r['itemID'])) {
                continue;
            }
            $c['max'] = max($c['max'] ?? '', (string) ($r['timeStamp'] ?? ''));
            $items[] = $this->mapItem($r);
        }

        return $this->advance($c, count($rows), 100) + ['items' => $items];
    }

    public function pullStock(string $accessToken, string $accountId, array $context, ?string $cursor): array
    {
        $c = $this->cursor($cursor);
        $q = ['limit' => 100, 'offset' => $c['offset'], 'sort' => 'timeStamp'];
        if ($c['since']) {
            $q['timeStamp'] = '>,' . $c['since'];
        }
        $body = $this->get($accessToken, $this->url($accountId, 'ItemShop.json'), $q);
        $data = $this->rows($body['ItemShop'] ?? null);

        $rows = [];
        foreach ($data as $r) {
            $c['max'] = max($c['max'] ?? '', (string) ($r['timeStamp'] ?? ''));
            // shopID 0 is the all-shops total, not a place
            if (! isset($r['itemID'], $r['shopID']) || (string) $r['shopID'] === '0') {
                continue;
            }
            $rows[] = ['item_id' => (string) $r['itemID'], 'location_id' => (string) $r['shopID'], 'qty' => (int) floor((float) ($r['qoh'] ?? 0))];
        }

        return $this->advance($c, count($data), 100) + ['rows' => $rows];
    }

    /** @return array{since: ?string, offset: int, max: ?string} */
    private function cursor(?string $cursor): array
    {
        $c = $cursor ? (json_decode($cursor, true) ?: []) : [];

        return ['since' => $c['since'] ?? null, 'offset' => (int) ($c['offset'] ?? 0), 'max' => $c['max'] ?? null];
    }

    /** Next page in this window, or close the window at the newest timestamp seen. */
    private function advance(array $c, int $got, int $limit): array
    {
        if ($got >= $limit) {
            return ['cursor' => json_encode(['since' => $c['since'], 'offset' => $c['offset'] + $got, 'max' => $c['max']]), 'more' => true];
        }

        return ['cursor' => json_encode(['since' => $c['max'] ?: $c['since'], 'offset' => 0, 'max' => null]), 'more' => false];
    }


    /** One API item in the shape pullItems returns. R-Series items carry no description here. */
    private function mapItem(array $r): array
    {
        $price = null;
        foreach ($this->rows($r['Prices']['ItemPrice'] ?? null) as $p) {
            if (($p['useType'] ?? '') === 'Default' && is_numeric($p['amount'] ?? null)) {
                $price = (int) round(((float) $p['amount']) * 100);
            }
        }

        return [
            'id'          => (string) $r['itemID'],
            'parent_id'   => ! empty($r['itemMatrixID']) && $r['itemMatrixID'] !== '0' ? 'm' . $r['itemMatrixID'] : null,
            'name'        => filled($r['description'] ?? null) ? (string) $r['description'] : null,
            'sku'         => filled($r['customSku'] ?? null) ? (string) $r['customSku'] : (filled($r['systemSku'] ?? null) ? (string) $r['systemSku'] : null),
            'upc'         => filled($r['upc'] ?? null) ? (string) $r['upc'] : null,
            'ean'         => filled($r['ean'] ?? null) ? (string) $r['ean'] : null,
            'mpn'         => filled($r['manufacturerSku'] ?? null) ? (string) $r['manufacturerSku'] : null,
            'brand'       => null,
            'description' => null,
            'price_cents' => $price,
            'active'      => ! in_array(strtolower((string) ($r['archived'] ?? 'false')), ['true', '1'], true),
        ];
    }

    // ── MARKER-CONNECTED-POS-PUSH ───────────────────────────────

    public function registers(string $accessToken, string $accountId, array $context): array
    {
        return $this->named($this->rows($this->get($accessToken, $this->url($accountId, 'Register.json'), ['limit' => 100])['Register'] ?? null), 'registerID');
    }

    public function users(string $accessToken, string $accountId, array $context): array
    {
        $out = [];
        foreach ($this->rows($this->get($accessToken, $this->url($accountId, 'Employee.json'), ['limit' => 100])['Employee'] ?? null) as $e) {
            if (isset($e['employeeID']) && ! in_array(strtolower((string) ($e['archived'] ?? 'false')), ['true', '1'], true)) {
                $out[] = ['id' => (string) $e['employeeID'], 'name' => trim(($e['firstName'] ?? '') . ' ' . ($e['lastName'] ?? '')) ?: '#' . $e['employeeID']];
            }
        }

        return $out;
    }

    /** R-Series applies tax from its own tax classes, so there is nothing to choose. */
    public function taxes(string $accessToken, string $accountId, array $context): array
    {
        return [];
    }

    public function createSale(string $accessToken, string $accountId, array $context, array $sale): string
    {
        $lines = [];
        foreach ($sale['lines'] as $l) {
            $lines[] = ['itemID' => $l['item_id'], 'unitQuantity' => (int) $l['qty'], 'unitPrice' => round($l['unit_cents'] / 100, 2), 'tax' => $l['tax_cents'] !== 0];
        }
        $body = array_filter([
            'employeeID'            => $sale['user_id'],
            'registerID'            => $sale['register_id'],
            'shopID'                => $sale['outlet_id'],
            'customerID'            => $sale['customer_id'] ?? null,
            'completed'             => true,
            'referenceNumber'       => $sale['reference'],
            'referenceNumberSource' => 'Intake',
            'SaleLines'             => ['SaleLine' => $lines],
            'SalePayments'          => ['SalePayment' => [['amount' => round($sale['total_cents'] / 100, 2), 'paymentTypeID' => $sale['payment_type_id']]]],
        ], fn ($v) => $v !== null);
        $res = $this->send('post', $accessToken, $this->url($accountId, 'Sale.json'), $body);
        $id = $this->rows($res['Sale'] ?? null)[0]['saleID'] ?? null;
        if (! $id) {
            throw new RuntimeException('Your Connected POS did not return the sale.');
        }

        return (string) $id;
    }

    public function createItem(string $accessToken, string $accountId, array $context, array $fields): string
    {
        $res = $this->send('post', $accessToken, $this->url($accountId, 'Item.json'), $this->itemPayload($fields));
        $id = $this->rows($res['Item'] ?? null)[0]['itemID'] ?? null;
        if (! $id) {
            throw new RuntimeException('Your Connected POS did not return the new item.');
        }

        return (string) $id;
    }

    public function setItemPrice(string $accessToken, string $accountId, array $context, string $itemId, int $priceCents): void
    {
        $this->updateItem($accessToken, $accountId, $context, $itemId, ['price_cents' => $priceCents]);
    }

    public function uploadImage(string $accessToken, string $accountId, array $context, string $itemId, string $filePath, string $fileName): void
    {
        $res = Http::withToken($accessToken)->acceptJson()->timeout(60)
            ->attach('image', file_get_contents($filePath), $fileName)
            ->post($this->url($accountId, 'Item/' . rawurlencode($itemId) . '/Image.json'));
        $this->ok($res);
    }

    public function updateItem(string $accessToken, string $accountId, array $context, string $itemId, array $fields): void
    {
        $this->send('put', $accessToken, $this->url($accountId, 'Item/' . rawurlencode($itemId) . '.json'), $this->itemPayload($fields));
    }

    public function getItem(string $accessToken, string $accountId, array $context, string $itemId): ?array
    {
        try {
            $body = $this->get($accessToken, $this->url($accountId, 'Item/' . rawurlencode($itemId) . '.json'), ['load_relations' => '["ItemPrices"]']);
        } catch (RuntimeException $e) {
            if (str_contains($e->getMessage(), 'HTTP 404')) {
                return null;
            }
            throw $e;
        }
        $r = $this->rows($body['Item'] ?? null)[0] ?? null;

        return $r && isset($r['itemID']) ? $this->mapItem($r) : null;
    }

    private function itemPayload(array $f): array
    {
        $out = [];
        foreach (['name' => 'description', 'sku' => 'customSku', 'upc' => 'upc', 'ean' => 'ean', 'mpn' => 'manufacturerSku'] as $k => $api) {
            if (array_key_exists($k, $f) && $f[$k] !== null) {
                $out[$api] = (string) $f[$k];
            }
        }
        if (isset($f['price_cents'])) {
            $out['Prices'] = ['ItemPrice' => [['amount' => round($f['price_cents'] / 100, 2), 'useType' => 'Default', 'useTypeID' => 1]]];
        }

        return $out;
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

    private function url(string $accountId, string $resource): string
    {
        return rtrim($this->cfg->api_base, '/') . '/' . rawurlencode($accountId) . '/' . $resource;
    }

    private function tokenCall(array $form): array
    {
        $res = Http::asForm()->acceptJson()->timeout(20)->post($this->cfg->token_url, $form);
        $j = $res->json() ?: [];
        if (! $res->successful() || empty($j['access_token'])) {
            $why = $j['error_description'] ?? $j['error'] ?? $j['message'] ?? ('HTTP ' . $res->status());
            throw new RuntimeException('Your Connected POS refused the sign-in: ' . $why);
        }

        return [
            'access_token'  => (string) $j['access_token'],
            'refresh_token' => isset($j['refresh_token']) ? (string) $j['refresh_token'] : null,
            'expires_in'    => isset($j['expires_in']) ? (int) $j['expires_in'] : null,
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
        if (! $res->successful()) {
            $j = $res->json() ?: [];
            throw new RuntimeException('Your Connected POS answered with an error (HTTP ' . $res->status() . '): ' . (is_string($j['message'] ?? null) ? $j['message'] : ''));
        }

        return $res->json() ?: [];
    }

    /** The API returns one record as an object and several as a list. */
    private function rows(mixed $v): array
    {
        if (! is_array($v) || $v === []) {
            return [];
        }

        return array_is_list($v) ? $v : [$v];
    }

    /** @return list<array{id: string, name: string}> */
    private function named(array $rows, string $idKey): array
    {
        $out = [];
        foreach ($rows as $r) {
            if (! isset($r[$idKey]) || in_array(strtolower((string) ($r['archived'] ?? 'false')), ['true', '1'], true)) {
                continue;
            }
            $out[] = ['id' => (string) $r[$idKey], 'name' => (string) ($r['name'] ?? ('#' . $r[$idKey]))];
        }

        return $out;
    }
}
