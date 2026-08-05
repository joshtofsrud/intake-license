<?php

// MARKER-QBP-CLS

namespace App\Services\Distributors;

use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * QBP Content License Service (API3).
 *
 * Separate from QbpClient: a different host, a different key, and a different
 * licence. API1 is free and carries the catalog; CLS is licensed and carries
 * the images.
 *
 * Only the image service is implemented. CLS also serves product detail,
 * categories and inventory, but API1 already supplies all of those — and
 * unlike CLS it also supplies dealer cost, which CLS explicitly excludes.
 * There is no reason to pay a second call for data we already hold.
 *
 * LICENCE, quoted because it constrains the code: "License terms require the
 * use of these URLs as the only mechanism for displaying product images. Do
 * not download the actual image files or serve them via any other means."
 * Nothing here fetches an image. It builds URLs.
 */
class QbpClsClient
{
    private string $apiKey;
    private string $base;

    public function __construct(string $apiKey)
    {
        $this->apiKey = trim($apiKey);
        $this->base = rtrim((string) config('distributors.qbp_cls.base_url', 'https://cls.qbp.com/api3/'), '/') . '/';
    }

    /**
     * MARKER-QBP-CLS — the account's image URL prefix and available sizes.
     *
     * The prefix embeds an Image Service ID unique to this QBP account, so
     * the result belongs to one tenant and must not be shared between them.
     *
     * @return array{ok:bool, imageUrl:?string, imageSizes:array<int,string>, error:?string}
     */
    public function imageServiceInfo(): array
    {
        if ($this->apiKey === '') {
            return ['ok' => false, 'imageUrl' => null, 'imageSizes' => [], 'error' => 'No CLS API key saved.'];
        }

        try {
            $res = Http::withHeaders([
                    'X-QBPAPI-KEY' => $this->apiKey,
                    'Accept'       => 'application/json',
                ])
                ->timeout((int) config('distributors.qbp_cls.timeout', 30))
                ->get($this->base . '1/imageserviceinfo');
        } catch (\Throwable $e) {
            return ['ok' => false, 'imageUrl' => null, 'imageSizes' => [], 'error' => 'Could not reach CLS: ' . $e->getMessage()];
        }

        if ($res->status() === 401 || $res->status() === 403) {
            return ['ok' => false, 'imageUrl' => null, 'imageSizes' => [],
                    'error' => 'CLS rejected the key. This must be the API3 key — an API1 key will not work.'];
        }

        if (! $res->successful()) {
            return ['ok' => false, 'imageUrl' => null, 'imageSizes' => [],
                    'error' => 'CLS returned HTTP ' . $res->status() . '.'];
        }

        // The guide says JSON is recommended and both are supported. API1
        // claimed the same and served only XML, so this accepts either
        // rather than trusting the sentence a second time.
        $data = $res->json();
        if (! is_array($data)) {
            $data = $this->fromXml((string) $res->body());
        }

        $url = trim((string) ($data['imageUrl'] ?? ''));

        // "prodt, prodh, prods, prodm, p350x350m, prodl, prodxl"
        $sizesRaw = $data['imageSizes'] ?? '';
        $sizes = is_array($sizesRaw)
            ? array_values(array_filter(array_map('trim', $sizesRaw)))
            : array_values(array_filter(array_map('trim', explode(',', (string) $sizesRaw))));

        if ($url === '') {
            return ['ok' => false, 'imageUrl' => null, 'imageSizes' => [],
                    'error' => 'CLS answered but returned no imageUrl.'];
        }

        return ['ok' => true, 'imageUrl' => rtrim($url, '/'), 'imageSizes' => $sizes, 'error' => null];
    }

    /**
     * MARKER-QBP-CLS — assemble one image URL.
     *
     * {imageUrl}/{size}/{FILENAME}
     *
     * The product code must be UPPERCASE — the guide states it, and a lower
     * case name returns nothing rather than erroring, which is the sort of
     * silent miss that takes a week to notice.
     */
    public static function imageUrl(string $prefix, string $size, string $fileName): ?string
    {
        $prefix = rtrim(trim($prefix), '/');
        $size   = trim($size, "/ \t\n\r\0\x0B");
        $file   = trim($fileName);

        if ($prefix === '' || $size === '' || $file === '') {
            return null;
        }

        // Uppercase the code, leave the extension alone: AB1002-01.jpg
        if (str_contains($file, '.')) {
            $dot  = strrpos($file, '.');
            $file = strtoupper(substr($file, 0, $dot)) . strtolower(substr($file, $dot));
        } else {
            $file = strtoupper($file);
        }

        return $prefix . '/' . $size . '/' . $file;
    }

    /** CLS says both formats are supported; API1 said that too and lied. */
    private function fromXml(string $body): array
    {
        if (trim($body) === '') {
            return [];
        }
        $prev = libxml_use_internal_errors(true);
        $sx = simplexml_load_string($body, 'SimpleXMLElement', LIBXML_NOCDATA);
        libxml_clear_errors();
        libxml_use_internal_errors($prev);

        if ($sx === false) {
            return [];
        }
        return json_decode((string) json_encode($sx), true) ?: [];
    }
}
