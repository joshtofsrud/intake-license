#!/usr/bin/env bash
# apply-qbp-cls-images.sh
# MARKER-QBP-CLS — QBP images, built the way the licence requires.
#
# From the CLS developer guide, all measured facts rather than guesses:
#
#   Base            https://cls.qbp.com/api3/
#   Auth            X-QBPAPI-KEY — SAME header name as API1, DIFFERENT key
#   Image service   GET /1/imageserviceinfo -> { imageUrl, imageSizes }
#   URL shape       {imageUrl}/{size}/{FILENAME}
#   e.g. https://images.qbp.com/imageservice/image/<serviceId>/p350x350m/AB1002.jpg
#
# THE LICENCE DECIDES THE ARCHITECTURE, and it rules out what I proposed twice
# in this session:
#
#   "License terms require the use of these URLs as the only mechanism for
#    displaying product images. Do not download the actual image files or
#    serve them via any other means."
#
# So: no fetching, no caching to disk, no proxying. Hotlink, or nothing. Every
# earlier plan of mine to "fetch lazily and cache locally" would have breached
# the agreement Josh signed.
#
# AND IMAGES ARE PER TENANT, not shared. The imageUrl contains an Image
# Service ID unique to each QBP account, so one retailer's URL cannot serve
# another's pages. That is why only the FILE NAMES live in the shared catalog
# and the URL is assembled per tenant at render time — which is, by luck
# rather than design, exactly how the data already sits.
#
# Product code must be UPPERCASE in the path; the guide is explicit.
set -e

cat <<'PHPEOF' > app/Services/Distributors/QbpClsClient.php
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
PHPEOF
echo "created app/Services/Distributors/QbpClsClient.php"

python3 <<'PY'
import io

# ---------------------------------------------------------------- config
p = 'config/distributors.php'
s = io.open(p, encoding='utf-8').read()

assert 'qbp_cls' not in s, 'already applied'

old = """    'bti' => ["""
assert s.count(old) == 1, 'C1 bti anchor'
s = s.replace(old, """    // MARKER-QBP-CLS — API3. Different host and different key from API1;
    // the header name happens to be the same, which is a good way to spend an
    // afternoon confused.
    'qbp_cls' => [
        'base_url' => env('QBP_CLS_BASE_URL', 'https://cls.qbp.com/api3/'),
        'timeout'  => (int) env('QBP_CLS_TIMEOUT', 30),
        // Default display size from the documented list:
        // prodt, prodh, prods, prodm, p350x350m, prodl, prodxl
        'image_size' => env('QBP_CLS_IMAGE_SIZE', 'p350x350m'),
    ],

    'bti' => [""")

io.open(p, 'w', encoding='utf-8').write(s)
print('patched', p)
PY

cat <<'PHPEOF' > database/migrations/2026_08_05_000100_add_cls_image_service_to_subscriptions.php
<?php

// MARKER-QBP-CLS

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The CLS image URL prefix belongs to ONE QBP account — it embeds that
 * retailer's Image Service ID. It therefore lives on the tenant's
 * subscription, never on the shared catalog, and one tenant's prefix must
 * never render on another's pages.
 *
 * Stored rather than fetched per request: the guide says this data changes
 * rarely, and refreshing it on every page view would be a call per image.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenant_distributor_catalog_subscriptions', function (Blueprint $t) {
            $t->string('cls_image_url', 255)->nullable()->after('credentials_encrypted');
            $t->json('cls_image_sizes')->nullable()->after('cls_image_url');
            $t->timestamp('cls_checked_at')->nullable()->after('cls_image_sizes');
        });
    }

    public function down(): void
    {
        Schema::table('tenant_distributor_catalog_subscriptions', function (Blueprint $t) {
            $t->dropColumn(['cls_image_url', 'cls_image_sizes', 'cls_checked_at']);
        });
    }
};
PHPEOF
echo "created database/migrations/2026_08_05_000100_add_cls_image_service_to_subscriptions.php"

echo
echo "--- licence constraint stated where the code is written ---"
grep -n "only mechanism\|Nothing here fetches" app/Services/Distributors/QbpClsClient.php | head -3

echo
echo "--- nothing downloads an image ---"
python3 - <<'PY'
import io, re
s = io.open('app/Services/Distributors/QbpClsClient.php', encoding='utf-8').read()
bad = [k for k in ['file_get_contents', 'Storage::', '->put(', 'copy(', 'fopen'] if k in s]
print('  download/store calls:', bad or 'none')
assert not bad, 'the licence forbids serving images by any other means'
print('  builds URLs only    : True')
PY

echo
echo "--- URL construction against the guide's own example ---"
python3 - <<'PY'
def image_url(prefix, size, filename):
    prefix = prefix.rstrip('/').strip()
    size = size.strip('/ ')
    f = filename.strip()
    if not prefix or not size or not f: return None
    if '.' in f:
        i = f.rindex('.')
        f = f[:i].upper() + f[i:].lower()
    else:
        f = f.upper()
    return f'{prefix}/{size}/{f}'

pre = 'https://images.qbp.com/imageservice/image/YOURIMAGESERVICEID'
want = pre + '/p350x350m/AB1002.jpg'
for given in ['AB1002.jpg', 'ab1002.jpg', 'Ab1002.JPG']:
    got = image_url(pre, 'p350x350m', given)
    print('  %-12s -> %s  %s' % (given, got, 'OK' if got == want else '*** WRONG ***'))
    assert got == want
print('  trailing slash on prefix tolerated:', image_url(pre + '/', 'p350x350m', 'AB1002.jpg') == want)
PY

echo
echo "--- imageSizes parses from the documented string ---"
python3 -c "
raw = 'prodt, prodh, prods, prodm, p350x350m, prodl, prodxl'
sizes = [s.strip() for s in raw.split(',') if s.strip()]
print('  sizes:', len(sizes), '->', ', '.join(sizes))
assert 'p350x350m' in sizes and len(sizes) == 7
"

echo
echo "--- balance ---"
python3 - <<'PY'
import io
for p in ['app/Services/Distributors/QbpClsClient.php',
          'config/distributors.php',
          'database/migrations/2026_08_05_000100_add_cls_image_service_to_subscriptions.php']:
    s = io.open(p, encoding='utf-8').read()
    i, n, d, par, brk = 0, len(s), 0, 0, 0
    while i < n:
        c = s[i]
        if c == '#' or (c == '/' and i+1 < n and s[i+1] == '/'):
            while i < n and s[i] != '\n': i += 1
        elif c == '/' and i+1 < n and s[i+1] == '*':
            i += 2
            while i+1 < n and not (s[i] == '*' and s[i+1] == '/'): i += 1
            i += 2
        elif c in '"\'':
            q = c; i += 1
            while i < n and s[i] != q:
                if s[i] == '\\': i += 1
                i += 1
            i += 1
        else:
            if c == '{': d += 1
            elif c == '}': d -= 1
            elif c == '(': par += 1
            elif c == ')': par -= 1
            elif c == '[': brk += 1
            elif c == ']': brk -= 1
            i += 1
    print('%-58s braces %d parens %d brackets %d' % (p.split('/')[-1], d, par, brk))
PY

echo
echo "apply-qbp-cls-images: OK"
