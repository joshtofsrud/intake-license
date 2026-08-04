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
