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
     * MARKER-CONNECTED-POS-CATALOG: items changed since a timestamp, paged by Lightspeed's next link.
     * The cursor holds the window start, the next page's URL and the newest
     * timestamp seen, so the next run starts where this one finished.
     */
    public function pullItems(string $accessToken, string $accountId, array $context, ?string $cursor): array
    {
        $c = $this->cursor($cursor);
        // MARKER-CONNECTED-POS-SECTION: category and brand come along for Inventory › Import
        $q = ['limit' => 100, 'load_relations' => '["ItemPrices","Category","Manufacturer"]'];
        $body = $this->page($accessToken, $accountId, 'Item.json', $q, $c);
        $rows = $this->rows($body['Item'] ?? null);

        $items = [];
        foreach ($rows as $r) {
            if (! isset($r['itemID'])) {
                continue;
            }
            $c['max'] = max($c['max'] ?? '', (string) ($r['timeStamp'] ?? ''));
            $items[] = $this->mapItem($r);
        }

        return $this->advance($c, $body, count($rows), 100) + ['items' => $items];
    }

    /** MARKER-POS-CUSTOMERS-IN: not yet tried against a real R-Series account. */
    public function pullCustomers(string $accessToken, string $accountId, array $context, ?string $cursor): array
    {
        $c = $this->cursor($cursor);
        $q = ['limit' => 100, 'load_relations' => '["Contact"]', 'archived' => 'true'];
        $body = $this->page($accessToken, $accountId, 'Customer.json', $q, $c);
        $rows = $this->rows($body['Customer'] ?? null);

        $out = [];
        foreach ($rows as $r) {
            if (! isset($r['customerID'])) {
                continue;
            }
            $c['max'] = max($c['max'] ?? '', (string) ($r['timeStamp'] ?? ''));
            $ct = (array) ($r['Contact'] ?? []);
            $email = '';
            foreach ($this->rows($ct['Emails']['ContactEmail'] ?? null) as $e) { $email = $email ?: trim((string) ($e['address'] ?? '')); }
            $phone = '';
            foreach ($this->rows($ct['Phones']['ContactPhone'] ?? null) as $ph) { $phone = $phone ?: trim((string) ($ph['number'] ?? '')); }
            $ad = $this->rows($ct['Addresses']['ContactAddress'] ?? null)[0] ?? [];
            $out[] = [
                'id' => (string) $r['customerID'], 'first_name' => trim((string) ($r['firstName'] ?? '')), 'last_name' => trim((string) ($r['lastName'] ?? '')),
                'email' => $email, 'phone' => $phone,
                'address1' => trim((string) ($ad['address1'] ?? '')), 'address2' => trim((string) ($ad['address2'] ?? '')), 'city' => trim((string) ($ad['city'] ?? '')),
                'state' => trim((string) ($ad['state'] ?? '')), 'postcode' => trim((string) ($ad['zip'] ?? '')), 'country' => trim((string) ($ad['countryCode'] ?? '')),
                'no_marketing' => ($ct['noEmail'] ?? 'false') === 'true',
                'deleted' => ($r['archived'] ?? 'false') === 'true',
            ];
        }

        return $this->advance($c, $body, count($rows), 100) + ['customers' => $out];
    }

    /** MARKER-POS-SALES-HISTORY: not yet tried against a real R-Series account. */
    public function pullSales(string $accessToken, string $accountId, array $context, ?string $cursor): array
    {
        $c = $this->cursor($cursor);
        // MARKER-SALES-SINCE: a first read starts at "how far back", not the shop's first sale
        if (! $c['since'] && empty($c['next']) && ! empty($context['sales_since'])) {
            $c['since'] = \Illuminate\Support\Carbon::parse((string) $context['sales_since'])->utc()->format('Y-m-d\TH:i:sP');
        }
        $q = ['limit' => 100, 'load_relations' => '["SaleLines","SaleLines.Item"]'];
        $body = $this->page($accessToken, $accountId, 'Sale.json', $q, $c);
        $rows = $this->rows($body['Sale'] ?? null);

        $cents = fn ($v) => is_numeric($v) ? (int) round(((float) $v) * 100) : 0;
        $yes = fn ($v) => in_array(strtolower((string) $v), ['true', '1'], true);
        $out = [];
        foreach ($rows as $r) {
            if (! isset($r['saleID'])) {
                continue;
            }
            $c['max'] = max($c['max'] ?? '', (string) ($r['timeStamp'] ?? ''));
            $lines = [];
            foreach ($this->rows($r['SaleLines']['SaleLine'] ?? null) as $l) {
                $item = (array) ($l['Item'] ?? []);
                $cost = $l['avgCost'] ?? $l['fifoCost'] ?? null;
                $lines[] = [
                    'product_id'       => ! empty($l['itemID']) && $l['itemID'] !== '0' ? (string) $l['itemID'] : null,
                    'name'             => filled($item['description'] ?? null) ? (string) $item['description'] : (filled($l['Note']['note'] ?? null) ? (string) $l['Note']['note'] : null),
                    'sku'              => filled($item['customSku'] ?? null) ? (string) $item['customSku'] : (filled($item['systemSku'] ?? null) ? (string) $item['systemSku'] : null),
                    'quantity'         => (float) ($l['unitQuantity'] ?? 0),
                    'unit_price_cents' => $cents($l['unitPrice'] ?? 0),
                    'total_cents'      => $cents($l['calcSubtotal'] ?? 0),
                    'discount_cents'   => $cents($l['calcLineDiscount'] ?? 0),
                    'tax_cents'        => $cents($l['calcTax1'] ?? 0) + $cents($l['calcTax2'] ?? 0),
                    'unit_cost_cents'  => is_numeric($cost) ? (int) round(((float) $cost) * 100) : null,
                ];
            }
            $out[] = [
                'id'             => (string) $r['saleID'],
                'number'         => filled($r['ticketNumber'] ?? null) ? (string) $r['ticketNumber'] : null,
                'sold_at'        => (string) ($r['completeTime'] ?? $r['timeStamp'] ?? ''),
                'outlet_id'      => filled($r['shopID'] ?? null) ? (string) $r['shopID'] : null,
                'customer_id'    => ! empty($r['customerID']) && $r['customerID'] !== '0' ? (string) $r['customerID'] : null,
                'state'          => ($yes($r['voided'] ?? 'false') || $yes($r['archived'] ?? 'false')) ? 'void' : ($yes($r['completed'] ?? 'false') ? 'done' : 'open'),
                'subtotal_cents' => $cents($r['calcSubtotal'] ?? 0) - $cents($r['calcDiscount'] ?? 0),
                'tax_cents'      => $cents($r['calcTax1'] ?? 0) + $cents($r['calcTax2'] ?? 0),
                'lines'          => $lines,
            ];
        }

        return $this->advance($c, $body, count($rows), 100) + ['sales' => $out];
    }

    public function pullStock(string $accessToken, string $accountId, array $context, ?string $cursor): array
    {
        $c = $this->cursor($cursor);
        $q = ['limit' => 100];
        $body = $this->page($accessToken, $accountId, 'ItemShop.json', $q, $c);
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

        return $this->advance($c, $body, count($data), 100) + ['rows' => $rows];
    }

    /**
     * MARKER-R-CURSOR-PAGING: Lightspeed R dropped offset paging ("The offset
     * parameter is no longer supported"). Each response now carries the URL of
     * the next page, and the cursor keeps that URL, so a stopped import resumes
     * on the page it stopped at. A window is everything changed since `since`;
     * when it runs out, the next window starts at the newest timestamp seen.
     * An old offset cursor just restarts its window: rows upsert, so nothing doubles.
     *
     * @return array{since: ?string, next: ?string, max: ?string}
     */
    private function cursor(?string $cursor): array
    {
        $c = $cursor ? (json_decode($cursor, true) ?: []) : [];

        return ['since' => $c['since'] ?? null, 'next' => $c['next'] ?? null, 'max' => $c['max'] ?? null];
    }

    /** this window's next page: the URL Lightspeed handed back, or the first page of a new window */
    private function page(string $token, string $accountId, string $resource, array $query, array $c): array
    {
        if (! empty($c['next'])) {
            return $this->get($token, (string) $c['next']);
        }
        if ($c['since']) {
            $query['timeStamp'] = '>,' . $c['since'];
        }

        return $this->get($token, $this->url($accountId, $resource), $query);
    }

    /** The next page's URL, '' when this was the last, null when the response didn't say. */
    private function nextUrl(array $body): ?string
    {
        $attr = (array) ($body['@attributes'] ?? []);
        foreach ([$attr['next'] ?? null, $body['next'] ?? null] as $v) {
            if ($v !== null) return trim((string) $v);
        }
        return array_key_exists('@attributes', $body) || array_key_exists('next', $body) ? '' : null;
    }

    /** Next page in this window, or close the window at the newest timestamp seen. */
    private function advance(array $c, array $body, int $got, int $limit): array
    {
        $next = $this->nextUrl($body);
        if ($next === null && $got >= $limit) {
            // a full page with no next link: carrying on would repeat this page or skip the rest
            throw new \RuntimeException('Lightspeed R sent a full page without a link to the next one, so the import stopped rather than guess. Nothing was lost; try again shortly.');
        }
        if ($next !== null && $next !== '') {
            return ['cursor' => json_encode(['since' => $c['since'], 'next' => $next, 'max' => $c['max']]), 'more' => true];
        }

        return ['cursor' => json_encode(['since' => $c['max'] ?: $c['since'], 'next' => null, 'max' => null]), 'more' => false];
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
            'brand'       => filled($r['Manufacturer']['name'] ?? null) ? trim((string) $r['Manufacturer']['name']) : null,
            'description' => null,
            'price_cents' => $price,
            'active'      => ! in_array(strtolower((string) ($r['archived'] ?? 'false')), ['true', '1'], true),
            // MARKER-CONNECTED-POS-SECTION (not yet tried against a real R-Series account)
            'category'    => filled($r['Category']['fullPathName'] ?? null) ? trim((string) $r['Category']['fullPathName'])
                                : (filled($r['Category']['name'] ?? null) ? trim((string) $r['Category']['name']) : null),
            'cost_cents'  => is_numeric($r['defaultCost'] ?? null) ? (int) round(((float) $r['defaultCost']) * 100) : null,
            'track_stock' => isset($r['itemType']) ? ! in_array(strtolower((string) $r['itemType']), ['non_inventory', 'non-inventory'], true) : null,
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
        // an empty query must go as null: [] makes the client drop the URL's own ?after=… (the next-page link)
        $send = fn () => Http::withToken($token)->acceptJson()->timeout(20)->get($url, $query ?: null);
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

    /**
     * MARKER-POS-ITEM-DETAILS: not built for R-Series yet (it has no test account to check
     * the payload against). Says so plainly instead of pretending, and the tab shows it.
     */
    public function setItemDetails(string $accessToken, string $accountId, array $context, string $itemId, array $details): void
    {
        throw new \RuntimeException('Brand, category, supplier and cost are not sent to Lightspeed R-Series yet.');
    }

    /** MARKER-POS-CUSTOMERS: not built for R-Series yet; orders send without a customer. */
    public function findOrCreateCustomer(string $accessToken, string $accountId, array $context, array $c): ?string
    {
        return null;
    }
}
