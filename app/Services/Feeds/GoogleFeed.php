<?php

namespace App\Services\Feeds;

use App\Models\Tenant;
use App\Models\Tenant\TenantInventoryCategory;
use App\Models\Tenant\TenantInventoryItem;
use App\Services\Shipping\ItemShipSpec;
use App\Services\Tenant\OnlineItems;
use App\Services\Tenant\OrderService;
use App\Services\Tenant\OrderStock;
use App\Support\ItemSeo;
use App\Support\Seo;

/**
 * MARKER-GOOGLE-FEED: the product feed Google Merchant Center reads.
 * `evaluate()` is pure (facts in, verdict + Google fields out) so the rules are testable.
 */
class GoogleFeed
{
    public const NS = 'http://base.google.com/ns/1.0';

    /** A barcode Google will accept: 8, 12, 13 or 14 digits with a correct check digit. */
    public static function gtinValid(?string $raw): ?string
    {
        $d = preg_replace('/\D/', '', (string) $raw);
        if (! in_array(strlen($d), [8, 12, 13, 14], true)) return null;
        $sum = 0;
        $body = substr($d, 0, -1);
        foreach (array_reverse(str_split($body)) as $i => $ch) $sum += (int) $ch * ($i % 2 === 0 ? 3 : 1);
        return ((10 - $sum % 10) % 10) === (int) substr($d, -1) ? $d : null;
    }

    public static function money(int $cents, string $currency): string
    {
        return number_format($cents / 100, 2, '.', '') . ' ' . strtoupper($currency ?: 'USD');
    }

    /**
     * facts: id, name, brand, description, subtitle, seo_title, seo_description, link, images[], price_cents, currency,
     *   available (bool), upc, ean, mpn, color, size, google_category, product_type, weight_oz, ship_cents (?int),
     *   exclude (bool), shop
     * @return array{ok:bool,reasons:string[],warnings:string[],attrs:array}
     */
    public static function evaluate(array $f): array
    {
        $reasons = $warnings = [];
        if (! empty($f['exclude']))                                  $reasons[] = 'You left it out of the Google feed';
        if (($f['price_cents'] ?? 0) <= 0)                           $reasons[] = 'No price';
        $images = array_values(array_filter((array) ($f['images'] ?? [])));
        if (! $images)                                               $reasons[] = 'No photo';
        $title = ItemSeo::cut(ItemSeo::title(null, (string) ($f['name'] ?? ''), $f['brand'] ?? null), 150);
        if ($title === '')                                           $reasons[] = 'No name';

        $gtin = self::gtinValid($f['upc'] ?? null) ?? self::gtinValid($f['ean'] ?? null);
        $brand = trim((string) ($f['brand'] ?? ''));
        $mpn = trim((string) ($f['mpn'] ?? ''));
        if (! $gtin && trim((string) (($f['upc'] ?? '') . ($f['ean'] ?? ''))) !== '') $warnings[] = 'Barcode is not valid (check the digits)';
        if (! $gtin)                                                 $warnings[] = 'No barcode';
        if ($brand === '')                                           $warnings[] = 'No brand';
        if (trim((string) ($f['google_category'] ?? '')) === '')     $warnings[] = 'No Google category';
        $desc = ItemSeo::plain($f['description'] ?? '');
        if (mb_strlen($desc) < 30)                                   $warnings[] = 'Short or missing description';

        $a = [
            'id'          => (string) $f['id'],
            'title'       => $title,
            'description' => mb_substr($desc !== '' ? $desc : ItemSeo::description(null, null, $f['subtitle'] ?? null, (string) $f['name'], $brand ?: null, (string) ($f['shop'] ?? '')), 0, 5000),
            'link'        => $f['link'] ?? '',
            'image_link'  => $images[0] ?? '',
            'availability' => ! empty($f['available']) ? 'in_stock' : 'out_of_stock',
            'price'       => self::money((int) ($f['price_cents'] ?? 0), (string) ($f['currency'] ?? 'USD')),
            'condition'   => 'new',
        ];
        if ($brand !== '')  $a['brand'] = $brand;
        if ($gtin)          $a['gtin'] = $gtin;
        if ($mpn !== '')    $a['mpn'] = $mpn;
        if (! $gtin && ($brand === '' || $mpn === '')) $a['identifier_exists'] = 'no';
        if (trim((string) ($f['google_category'] ?? '')) !== '') $a['google_product_category'] = trim((string) $f['google_category']);
        if (trim((string) ($f['product_type'] ?? '')) !== '')    $a['product_type'] = trim((string) $f['product_type']);
        foreach (['color', 'size'] as $k) if (trim((string) ($f[$k] ?? '')) !== '') $a[$k] = trim((string) $f[$k]);
        if (count($images) > 1) $a['additional_image_link'] = array_slice($images, 1, 10);
        if (($f['weight_oz'] ?? null) !== null && $f['weight_oz'] > 0) $a['shipping_weight'] = round($f['weight_oz'] / 16, 2) . ' lb';
        if (($f['ship_cents'] ?? null) !== null) $a['shipping'] = ['country' => 'US', 'service' => 'Standard', 'price' => self::money((int) $f['ship_cents'], (string) ($f['currency'] ?? 'USD'))];

        return ['ok' => ! $reasons, 'reasons' => $reasons, 'warnings' => $warnings, 'attrs' => $a];
    }

    public static function itemXml(array $a): string
    {
        $x = "    <item>\n";
        foreach ($a as $k => $v) {
            if ($k === 'additional_image_link') {
                foreach ($v as $u) $x .= '      <g:additional_image_link>' . self::e($u) . "</g:additional_image_link>\n";
            } elseif ($k === 'shipping') {
                $x .= "      <g:shipping>\n";
                foreach ($v as $kk => $vv) $x .= "        <g:{$kk}>" . self::e($vv) . "</g:{$kk}>\n";
                $x .= "      </g:shipping>\n";
            } else {
                $x .= "      <g:{$k}>" . self::e((string) $v) . "</g:{$k}>\n";
            }
        }
        return $x . "    </item>\n";
    }

    public static function head(Tenant $t): string
    {
        $base = rtrim($t->publicUrl(), '/');
        return '<?xml version="1.0" encoding="UTF-8"?>' . "\n"
            . '<rss version="2.0" xmlns:g="' . self::NS . '">' . "\n  <channel>\n"
            . '    <title>' . self::e($t->name) . "</title>\n    <link>" . self::e($base . '/shop') . "</link>\n"
            . '    <description>' . self::e($t->name . ' products') . "</description>\n";
    }

    public static function foot(): string { return "  </channel>\n</rss>\n"; }

    private static function e(string $s): string
    {
        // XML 1.0 forbids most control characters; strip them rather than break the whole feed
        $s = preg_replace('/[^\x{9}\x{A}\x{D}\x{20}-\x{D7FF}\x{E000}-\x{FFFD}\x{10000}-\x{10FFFF}]/u', '', $s) ?? '';
        return htmlspecialchars($s, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    }

    // ---------------------------------------------------------------- settings

    public static function config(Tenant $t): array
    {
        $s = (array) (($t->settings ?? [])['storefront'] ?? []);
        return ['on' => (bool) ($s['feed_on'] ?? false), 'token' => (string) ($s['feed_token'] ?? '')];
    }

    public static function url(Tenant $t): ?string
    {
        $c = self::config($t);
        return $c['token'] !== '' ? rtrim($t->publicUrl(), '/') . '/feeds/google-' . $c['token'] . '.xml' : null;
    }

    // ----------------------------------------------------------------- the feed

    /** Walk every online item once, handing each item's verdict to $each(item, verdict). */
    public static function walk(Tenant $t, callable $each): void
    {
        $base = rtrim($t->publicUrl(), '/');
        $oc = OrderService::forTenant($t)->config();
        $flat = $oc['ship_enabled'] && \App\Services\Shipping\ShippingRates::config($t)['mode'] === 'flat';
        $stock = OrderStock::forTenant($t);

        $cats = TenantInventoryCategory::where('tenant_id', $t->id)->get(['id', 'parent_id', 'name', 'google_category'])->keyBy('id');
        $chain = function (?string $id) use ($cats): array {
            $out = []; $seen = 0;
            while ($id && isset($cats[$id]) && $seen++ < 8) { array_unshift($out, $cats[$id]); $id = $cats[$id]->parent_id; }
            return $out;
        };

        OnlineItems::query($t)->with('distributorCatalog')->orderBy('id')->chunkById(300, function ($items) use ($each, $t, $base, $flat, $oc, $stock, $chain) {
            $backed = $stock->supplierBacked($items->pluck('id')->all());
            foreach ($items as $i) {
                $path = $chain($i->category_id);
                $gcat = trim((string) $i->google_category);
                if ($gcat === '') foreach (array_reverse($path) as $c) { if (trim((string) $c->google_category) !== '') { $gcat = trim($c->google_category); break; } }
                $onShelf = $i->availableCount() > 0;
                $spec = ItemShipSpec::for($i);
                $ship = null;
                if ($flat) $ship = (! $onShelf && isset($backed[$i->id]) && $oc['ship_fee_supplier_cents'] !== null) ? $oc['ship_fee_supplier_cents'] : $oc['ship_fee_cents'];

                $verdict = self::evaluate([
                    'id' => $i->id, 'name' => $i->name, 'brand' => $i->shop_brand ?: $i->distributorCatalog?->manufacturer,
                    'description' => $i->description, 'subtitle' => $i->display_subtitle,
                    'link' => $base . $i->shopPath(),
                    'images' => collect($i->displayImages(11))->reject(fn ($x) => $x['hidden'] ?? false)->pluck('url')
                        ->map(fn ($u) => preg_match('#^https?://#i', (string) $u) ? $u : $base . '/' . ltrim((string) $u, '/'))->all(),
                    'price_cents' => $i->effectiveSellPriceCents(), 'currency' => $t->currency ?: 'USD',
                    'available' => $onShelf || isset($backed[$i->id]),
                    'upc' => $i->catalog_upc, 'ean' => $i->catalog_ean, 'mpn' => $i->catalog_mpn,
                    'color' => $i->color, 'size' => $i->size, 'google_category' => $gcat,
                    'product_type' => implode(' > ', array_map(fn ($c) => $c->name, $path)),
                    'weight_oz' => $spec['oz'], 'ship_cents' => $ship, 'exclude' => (bool) $i->feed_exclude, 'shop' => $t->name,
                ]);
                $each($i, $verdict);
            }
        });
    }

    /** Stream the XML out through $write. */
    public static function stream(Tenant $t, callable $write): void
    {
        $write(self::head($t));
        self::walk($t, function ($item, $v) use ($write) {
            if ($v['ok']) $write(self::itemXml($v['attrs']));
        });
        $write(self::foot());
    }

    /** What is in the feed, what is not and why, and what Google will nag about. */
    public static function report(Tenant $t): array
    {
        $r = ['total' => 0, 'in_feed' => 0, 'excluded' => [], 'warnings' => []];
        self::walk($t, function ($item, $v) use (&$r) {
            $r['total']++;
            $row = ['id' => $item->id, 'name' => $item->name];
            if ($v['ok']) $r['in_feed']++;
            foreach ($v['reasons'] as $why) {
                $r['excluded'][$why]['count'] = ($r['excluded'][$why]['count'] ?? 0) + 1;
                if (count($r['excluded'][$why]['items'] ?? []) < 5) $r['excluded'][$why]['items'][] = $row;
            }
            if ($v['ok']) foreach ($v['warnings'] as $w) {
                $r['warnings'][$w]['count'] = ($r['warnings'][$w]['count'] ?? 0) + 1;
                if (count($r['warnings'][$w]['items'] ?? []) < 5) $r['warnings'][$w]['items'][] = $row;
            }
        });
        return $r;
    }
}
