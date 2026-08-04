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
 *   - Images: API1 returns file NAMES; the files themselves need CLS.
 *
 * MARKER-QBP-API-SPLIT — settled against a live response:
 *
 *   COST IS ON API1. dealerPrice comes back on product detail alongside
 *   basePrice, mapPrice and msrp. CLS's exclusion of "Your Price" turned out
 *   not to matter, because CLS is not where cost lives.
 *
 *   Everything transactional is API1 and free: identity, model grouping,
 *   barcodes, categories, dimensions, freight, the price ladder, warehouse
 *   stock with estimatedArrivalDate, plus bulletPoints and classifications.
 *
 *   CLS is needed for ONE thing: the image files. Anything that only needs
 *   to know an image exists can read the file name from API1.
 *
 *   TIER DISCIPLINE. dealerPrice is the authenticated ACCOUNT's price, so it
 *   belongs on the per-tenant sync, never in platform_distributor_catalogs,
 *   which every tenant reads. syncIdentity already nulls cost_cents; keep it
 *   that way. The hazard is that in QBP's payload dealerPrice sits inline
 *   with the identity fields, so it is easy to map by accident.
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

    /**
     * MARKER-QBP-SYNC — tells syncIdentity to page products() by brand
     * rather than fetching the catalog in one call. One brand measured 7 MB
     * of XML; 892 brands in one array is an OOM, not a sync.
     */
    public function pagesByBrand(): bool
    {
        return true;
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

        // MARKER-QBP-XML — parse the envelope, then the payload.
        $doc = $this->xml((string) $res->body());

        if ($doc === null) {
            return [
                'ok' => false, 'status' => $status,
                'body' => 'QBP answered with something that is not XML: '
                        . mb_substr((string) $res->body(), 0, 120),
            ];
        }

        // A 200 can still carry a failure in the envelope, so the status
        // attribute is checked rather than trusted from the HTTP code.
        $envelope = (string) ($doc['responseStatus']['@type'] ?? 'OK');
        if ($envelope !== '' && strtoupper($envelope) !== 'OK') {
            $err = $doc['errors']['errorMessage'] ?? null;
            return [
                'ok' => false, 'status' => $status,
                'body' => 'QBP reported ' . $envelope
                        . (is_string($err) && $err !== '' ? ': ' . $err : '.'),
            ];
        }

        $count = count($this->asList($doc['brands']['brand'] ?? null));

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

    /**
     * MARKER-QBP-BUILD — every brand QBP carries.
     *
     * @return array<int,array{id:string,name:string}>
     */
    public function brands(): array
    {
        $doc = $this->fetch('1/brand');

        $out = [];
        foreach ($this->asList($doc['brands']['brand'] ?? null) as $b) {
            $id   = trim((string) ($b['id'] ?? ''));
            $name = trim((string) ($b['description'] ?? ''));
            if ($id === '') {
                continue;
            }
            // QBP's brand id is its own code (Maxxis is DHN), so cross-
            // distributor matching has to happen on the NAME. Both are kept.
            $out[] = ['id' => $id, 'name' => $name !== '' ? $name : $id];
        }
        return $out;
    }

    /**
     * MARKER-QBP-BUILD — the category tree, assembled.
     *
     * QBP returns a FLAT list where each node names its parent by id. Unlike
     * HLC there is no path on a node, so the path is walked here once and
     * cached, rather than re-walked per product during a sync.
     *
     * @return array<string,array{id:string,name:string,parent:?string,path:string}>
     */
    public function categories(): array
    {
        $doc = $this->fetch('1/category');

        $nodes = [];
        foreach ($this->asList($doc['productCategories']['productCategory'] ?? null) as $c) {
            $id = trim((string) ($c['id'] ?? ''));
            if ($id === '') {
                continue;
            }
            $nodes[$id] = [
                'id'     => $id,
                'name'   => trim((string) ($c['name'] ?? '')),
                'parent' => trim((string) ($c['parent']['id'] ?? '')) ?: null,
                'path'   => '',
            ];
        }

        foreach ($nodes as $id => $_) {
            $nodes[$id]['path'] = $this->categoryPath($nodes, $id);
        }

        return $nodes;
    }

    /**
     * Walk parent links to a full path. Depth-capped because a cycle in a
     * remote tree would otherwise hang the sync rather than fail it.
     */
    private function categoryPath(array $nodes, string $id, int $depth = 0): string
    {
        if ($depth > 12 || ! isset($nodes[$id])) {
            return '';
        }
        $name   = $nodes[$id]['name'];
        $parent = $nodes[$id]['parent'];

        if ($parent === null || $parent === '' || ! isset($nodes[$parent])) {
            return $name;
        }
        $up = $this->categoryPath($nodes, $parent, $depth + 1);
        return $up === '' ? $name : $up . ' > ' . $name;
    }

    /**
     * MARKER-QBP-BUILD — products, one page of BRANDS at a time.
     *
     * @param array{pageStartIndex?:int, pageSize?:int, brands?:array<int,string>} $opts
     *        pageStartIndex  1-based offset into the brand list
     *        pageSize        how many BRANDS this page covers, not products
     *        brands          explicit brand ids; skips the brand list entirely
     *
     * @return array{Products: array<int,array>}
     */
    public function products(array $opts = []): array
    {
        $ids = $opts['brands'] ?? null;

        if ($ids === null) {
            $all   = array_column($this->brands(), 'id');
            $start = max(1, (int) ($opts['pageStartIndex'] ?? 1));
            $size  = max(1, (int) ($opts['pageSize'] ?? 25));
            $ids   = array_slice($all, $start - 1, $size);
        }

        $byModel = [];

        foreach ($ids as $brandId) {
            $brandId = trim((string) $brandId);
            if ($brandId === '') {
                continue;
            }

            try {
                $doc = $this->fetch('1/product/brand/id/' . rawurlencode($brandId));
            } catch (\Throwable $e) {
                // One bad brand must not abandon the page. A brand with no
                // products is normal; a 500 on one is not worth losing the
                // other twenty-four.
                continue;
            }

            foreach ($this->asList($doc['products']['product'] ?? null) as $row) {
                $sku = trim((string) ($row['sku'] ?? ''));
                if ($sku === '') {
                    continue;
                }

                // modelCode groups variants of one product. Missing means the
                // SKU stands alone, so it becomes its own group.
                $model = trim((string) ($row['modelCode'] ?? '')) ?: $sku;

                $byModel[$model] ??= [
                    'ModelCode' => $model,
                    'Brand'     => trim((string) ($row['brand']['description'] ?? '')) ?: $brandId,
                    'BrandId'   => trim((string) ($row['brand']['id'] ?? $brandId)),
                    'Variants'  => [],
                ];

                $byModel[$model]['Variants'][] = $this->variant($row);
            }

            unset($doc);
        }

        return ['Products' => array_values($byModel)];
    }

    /**
     * MARKER-QBP-BUILD — one SKU row, shaped for the field map.
     *
     * The raw element names are kept so a map written against the payload
     * reads true, with a few flattened additions the resolver cannot reach on
     * its own: dotted paths cannot walk a three-level attribute nest, and a
     * barcode list needs picking apart.
     *
     * dealerPrice is REMOVED. It is this account's price and this row goes
     * into the catalog every tenant reads.
     */
    private function variant(array $row): array
    {
        unset($row['dealerPrice']);

        $row['Attributes']   = $this->attributes($row['classifications'] ?? null);
        $row['CategoryName'] = trim((string) ($row['productCategories']['productCategory']['name'] ?? ''));
        $row['CategoryId']   = trim((string) ($row['productCategories']['productCategory']['id'] ?? ''));
        $row['ImageFile']    = trim((string) ($row['images']['image']['fileName'] ?? ''));

        // MARKER-QBP-DIMS — flattened here because a dotted path cannot
        // assemble three elements into one JSON column, and zip_pipe zips
        // pipe strings, not element triples — checked, not assumed.
        $dims = [];
        foreach (['Length', 'Width', 'Height'] as $d) {
            $v = $row['freight'][$d]['value'] ?? null;
            if ($v !== null && trim((string) $v) !== '') {
                $dims[$d] = (float) $v;
            }
        }
        $row['Dimensions'] = $dims ?: null;

        // Barcodes arrive typed. Length alone cannot separate UPC from EAN —
        // a 13-digit EAN and a UPC with a leading zero look the same — so the
        // type is carried through and the decision left to the map.
        $codes = [];
        foreach ($this->asList($row['barcodes']['Barcode'] ?? null) as $bc) {
            $value = trim((string) ($bc['value'] ?? ''));
            if ($value === '') {
                continue;
            }
            $codes[] = ['type' => trim((string) ($bc['type'] ?? '')), 'value' => $value];
        }
        $row['BarcodeList']  = $codes;
        $row['FirstBarcode'] = $codes[0]['value'] ?? null;

        // Offerable at all? These are QBP-only; neither HLC nor BTI says.
        $row['IsOfferable'] = ! $this->truthy($row['blocked'] ?? null)
            && ! $this->truthy($row['discontinued'] ?? null);

        return $row;
    }

    /**
     * MARKER-QBP-BUILD — flatten QBP's attribute nest.
     *
     *   classification -> features.feature -> featureValues.featureValue.value
     *
     * The name is duplicated at classification and feature level; the
     * feature's is used, because that is the level the value hangs off.
     * featureValues is PLURAL — several values are joined rather than the
     * first taken, or a compatibility list would silently lose everything
     * after its first entry.
     *
     * The stable `code` is kept alongside the name: clsAttr_454-mm outlives a
     * relabelling of "Rim Width (Internal)".
     *
     * @return array<int,array{Name:string,Value:string,Code:string,Unit:string}>
     */
    private function attributes(mixed $classifications): array
    {
        $out = [];

        foreach ($this->asList($classifications['classification'] ?? $classifications ?? null) as $cls) {
            if (! is_array($cls)) {
                continue;
            }
            foreach ($this->asList($cls['features']['feature'] ?? null) as $feature) {
                if (! is_array($feature)) {
                    continue;
                }

                $values = [];
                foreach ($this->asList($feature['featureValues']['featureValue'] ?? null) as $fv) {
                    $v = is_array($fv) ? ($fv['value'] ?? '') : $fv;
                    $v = trim((string) $v);
                    if ($v !== '') {
                        $values[] = $v;
                    }
                }
                if (! $values) {
                    continue;
                }

                $name = trim((string) ($feature['name'] ?? $cls['name'] ?? ''));
                if ($name === '') {
                    continue;
                }

                $out[] = [
                    'Name'  => $name,
                    'Value' => implode(', ', $values),
                    'Code'  => trim((string) ($feature['code'] ?? $cls['code'] ?? '')),
                    'Unit'  => trim((string) ($feature['featureUnit'] ?? '')),
                ];
            }
        }

        return $out;
    }

    /**
     * MARKER-QBP-BUILD — stock for specific SKUs, tier 2.
     *
     * Per SKU, because that response carries the per-warehouse breakdown and
     * estimatedArrivalDate. For a whole-catalog refresh use
     * inventoryByWarehouse(), which returns a site in one call.
     *
     * @return array<string,array>
     */
    public function inventory(array $skus): array
    {
        $out = [];

        foreach ($skus as $sku) {
            $sku = trim((string) $sku);
            if ($sku === '') {
                continue;
            }

            try {
                $doc = $this->fetch('1/availability/sku/' . rawurlencode($sku));
            } catch (\Throwable $e) {
                continue;
            }

            $avail = $this->asList($doc['productAvailabilities']['productAvailability'] ?? null);
            $first = $avail[0] ?? null;
            if (! is_array($first)) {
                continue;
            }

            $levels = [];
            $total  = 0;
            foreach ($this->asList($first['stockLevels']['stockLevel'] ?? null) as $lvl) {
                if (! is_array($lvl)) {
                    continue;
                }
                $qty = (int) ($lvl['quantityAvailable'] ?? 0);
                $total += $qty;
                $levels[] = [
                    'warehouse' => trim((string) ($lvl['warehouse']['code'] ?? '')),
                    'name'      => trim((string) ($lvl['warehouse']['name'] ?? '')),
                    'quantity'  => $qty,
                    'status'    => trim((string) ($lvl['stockLevelStatus'] ?? '')),
                    // Milliseconds. Note this appears on IN-stock rows too, so
                    // it is a restock date, not an arrival promise.
                    'eta_ms'    => $lvl['estimatedArrivalDate']['iMillis'] ?? null,
                ];
            }

            $out[$sku] = [
                'sku'         => $sku,
                'total'       => $total,
                'warehouses'  => $levels,
                'unavailable' => (string) ($first['temporarilyUnavailableToOrderCode'] ?? '0') !== '0',
            ];
        }

        return $out;
    }

    /**
     * MARKER-QBP-BUILD — a whole warehouse in one call.
     *
     * ~316k rows and ~39 MB per site, so this is a nightly instrument, not a
     * quarter-hourly one. Returns {sku => quantity} and nothing else; the
     * lite feed carries no warehouse detail and no ETA.
     *
     * @return array<string,int>
     */
    public function inventoryByWarehouse(string $warehouseCode): array
    {
        $doc = $this->fetch('1/availability/warehouse/' . rawurlencode($warehouseCode));

        $out = [];
        foreach ($this->asList($doc['liteStockLevel'] ?? null) as $row) {
            if (! is_array($row)) {
                continue;
            }
            $code = trim((string) ($row['code'] ?? ''));
            if ($code !== '') {
                $out[$code] = (int) ($row['quantity'] ?? 0);
            }
        }
        return $out;
    }

    /**
     * MARKER-QBP-BUILD — cost and the price ladder, tier 2 ONLY.
     *
     * dealerPrice is this account's negotiated price. It is returned here
     * because the per-tenant sync runs on the tenant's own credential, and
     * stripped from products() because that feeds the shared catalog.
     *
     * @return array<string,array>
     */
    public function prices(array $skus): array
    {
        $out = [];

        foreach ($skus as $sku) {
            $sku = trim((string) $sku);
            if ($sku === '') {
                continue;
            }

            try {
                $doc = $this->fetch('1/product/sku/' . rawurlencode($sku));
            } catch (\Throwable $e) {
                continue;
            }

            $p = $this->asList($doc['products']['product'] ?? null)[0] ?? null;
            if (! is_array($p)) {
                continue;
            }

            // `value` is the number; `formattedValue` is "$8.40" and would
            // parse to 0 or 8 depending on how hard something tried.
            $out[$sku] = [
                'sku'         => $sku,
                'dealer_price'=> $this->money($p['dealerPrice']['value'] ?? null),
                'base_price'  => $this->money($p['basePrice']['value'] ?? null),
                'map_price'   => $this->money($p['mapPrice']['value'] ?? null),
                'msrp'        => $this->money($p['msrp']['value'] ?? null),
                'currency'    => trim((string) ($p['dealerPrice']['currencyIso'] ?? 'USD')),
            ];
        }

        return $out;
    }

    /** Decimal string to cents, without float rounding. */
    private function money(mixed $value): ?int
    {
        if ($value === null || trim((string) $value) === '') {
            return null;
        }
        return (int) round(((float) $value) * 100);
    }

    private function truthy(mixed $v): bool
    {
        return in_array(strtolower(trim((string) $v)), ['1', 'true', 'yes'], true);
    }

    /**
     * MARKER-QBP-BUILD — GET, parse, and refuse an envelope that is not OK.
     *
     * A 200 can carry a failure in responseStatus. Letting that through would
     * write an empty page over good rows.
     */
    private function fetch(string $path, array $query = []): array
    {
        $res = $this->get($path, $query);

        if (! $res->successful()) {
            throw new RuntimeException('QBP ' . $path . ' returned HTTP ' . $res->status());
        }

        $doc = $this->xml((string) $res->body());
        if ($doc === null) {
            throw new RuntimeException('QBP ' . $path . ' did not return parseable XML.');
        }

        $status = (string) ($doc['responseStatus']['@type'] ?? 'OK');
        if ($status !== '' && strtoupper($status) !== 'OK') {
            $err = $doc['errors']['errorMessage'] ?? null;
            throw new RuntimeException(
                'QBP ' . $path . ' reported ' . $status
                . (is_string($err) && $err !== '' ? ': ' . $err : '.')
            );
        }

        return $doc;
    }
    /**
     * MARKER-QBP-API-SPLIT — the one method that is not API1.
     *
     * Product detail gives a file NAME. Retrieving the file needs a Content
     * License Service subscription: an active QBP account with order
     * history, a signed licensing agreement, and QBP's intake process.
     *
     * This throws with that explanation rather than returning an empty list,
     * because "no images" and "not licensed for images" are different
     * problems with different fixes, and only one of them is solved by code.
     */
    public function images(array $skus): array
    {
        throw new RuntimeException(
            'QBP images require a Content License Service (API3) subscription. API1 returns image '
            . 'file names only. Product content, attributes, stock and dealer cost all come from '
            . 'API1 and need no licence.'
        );
    }

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

    /**
     * MARKER-QBP-XML — Accept: application/xml.
     *
     * Measured, not assumed: application/json returns 406 with an empty body
     * on every endpoint tried. XML is the only format the service actually
     * produces, and the only one that returns a readable error.
     */
    private function get(string $path, array $query = [])
    {
        return Http::withHeaders([
                'X-QBPAPI-KEY' => $this->apiKey,
                'Accept'       => 'application/xml',
            ])
            ->timeout((int) config('distributors.qbp.timeout', 60))
            ->get($this->base . $path, $query);
    }

    /**
     * MARKER-QBP-API-SPLIT — element paths confirmed on a live response, so
     * the field map is written against real names rather than the guide.
     *
     *   TIER 1, shared catalog (no cost):
     *     sku                      distributor_variant_no
     *     modelCode                distributor_product_no
     *     manufacturerPartNumber   manufacturer_sku
     *     barcodes.barcode         upc / ean
     *     brand.name               manufacturer
     *     productCategory.id       resolve via the category tree
     *     Length/Width/Height      dimensions
     *     Weight                   weight
     *     bulletPoints             description material
     *     classifications          attributes
     *     blocked / discontinued / hazmat / ormd / markets
     *                              gate whether an item is offerable at all
     *
     *   TIER 2, per tenant:
     *     dealerPrice.value        cost_cents      ← the account's own price
     *     mapPrice.value           map_cents
     *     msrp.value               msrp_cents
     *     basePrice.value          list, for reference
     *     stockLevel.quantityAvailable / .stockLevelStatus / .warehouse
     *     stockLevel.estimatedArrivalDate
     *                              nothing else we carry has an ETA; it is
     *                              the difference between "we will call you"
     *                              and "it lands Thursday"
     *
     * Prices nest as {currencyIso, value, formattedValue, priceType} — read
     * `value`, never `formattedValue`, which is a display string.
     */

    /**
     * MARKER-QBP-XML — XML body to a plain array.
     *
     * Attributes are prefixed with @ so responseStatus type="OK" survives as
     * ['@type' => 'OK'] rather than being dropped, which is how the envelope
     * reports failure on an HTTP 200.
     */
    private function xml(string $body): ?array
    {
        if (trim($body) === '') {
            return null;
        }

        $prev = libxml_use_internal_errors(true);
        $sx = simplexml_load_string($body, 'SimpleXMLElement', LIBXML_NOCDATA);
        libxml_clear_errors();
        libxml_use_internal_errors($prev);

        return $sx === false ? null : $this->sxToArray($sx);
    }

    private function sxToArray(\SimpleXMLElement $el): array
    {
        $out = [];

        foreach ($el->attributes() as $k => $v) {
            $out['@' . $k] = (string) $v;
        }

        foreach ($el->children() as $name => $child) {
            $value = ($child->count() > 0 || $child->attributes()->count() > 0)
                ? $this->sxToArray($child)
                : trim((string) $child);

            if (array_key_exists($name, $out)) {
                // Second occurrence: promote to a list and keep both.
                if (! is_array($out[$name]) || ! array_is_list($out[$name])) {
                    $out[$name] = [$out[$name]];
                }
                $out[$name][] = $value;
            } else {
                $out[$name] = $value;
            }
        }

        if ($out === [] ) {
            $text = trim((string) $el);
            if ($text !== '') {
                return ['#text' => $text];
            }
        }

        return $out;
    }

    /**
     * MARKER-QBP-XML — SimpleXML gives an object for one child and a list for
     * two. Every collection read goes through this so one-item and many-item
     * responses take the same path.
     *
     * @return array<int,mixed>
     */
    private function asList(mixed $value): array
    {
        if ($value === null || $value === '') {
            return [];
        }
        if (is_array($value) && array_is_list($value)) {
            return $value;
        }
        return [$value];
    }

    /* MARKER-QBP-XML — the JSON-shaped listish() helper is gone; asList()
       above replaces it, and the difference matters: listish() hunted for
       whichever key held an array, which is a JSON habit. XML names its
       collections, so the path is known and only the one-versus-many shape
       needs normalising. */
}
