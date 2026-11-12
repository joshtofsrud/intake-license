<?php

namespace App\Support;

/**
 * MARKER-ITEM-SEO: how one item presents itself to search engines and to link previews.
 * Pure functions, so the rules are testable without a database.
 *
 *   address     /shop/{readable-name}-{id}   (the id at the end is what finds the item;
 *                                              the words are for people and search)
 *   title       "Brand Name"  (the layout adds " — Shop name")
 *   description the shop's own wording, else the item description, else a plain sentence
 */
class ItemSeo
{
    public const UUID = '[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}';

    public static function slug(?string $name, int $max = 60): string
    {
        $s = strtolower(trim((string) $name));
        $s = preg_replace('/[^a-z0-9]+/', '-', iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $s) ?: $s);
        $s = trim((string) $s, '-');
        if (strlen($s) > $max) $s = trim(substr($s, 0, $max), '-');
        return $s;
    }

    /** The last part of the address: "name-words-<id>", or just the id when the name has no usable words. */
    public static function param(string $id, ?string $name): string
    {
        $slug = self::slug($name);
        return $slug === '' ? $id : $slug . '-' . $id;
    }

    public static function path(string $id, ?string $name): string
    {
        return '/shop/' . self::param($id, $name);
    }

    /** The item id inside an address part, whether or not the words in front of it are right. */
    public static function idFromParam(string $param): ?string
    {
        return preg_match('/(' . self::UUID . ')$/', $param, $m) ? strtolower($m[1]) : null;
    }

    public static function title(?string $seoTitle, string $name, ?string $brand): string
    {
        $seoTitle = trim((string) $seoTitle);
        if ($seoTitle !== '') return $seoTitle;
        $brand = trim((string) $brand);
        if ($brand !== '' && stripos($name, $brand) !== 0) return $brand . ' ' . $name;
        return $name;
    }

    public static function description(?string $seoDesc, ?string $itemDesc, ?string $subtitle, string $name, ?string $brand, string $shop): string
    {
        $seoDesc = self::plain($seoDesc);
        if ($seoDesc !== '') return self::cut($seoDesc, 300);

        $d = self::plain($itemDesc);
        if ($d === '') $d = self::plain($subtitle);
        if ($d !== '') return self::cut($d, 155);

        $by = trim((string) $brand) !== '' && stripos($name, (string) $brand) !== 0 ? ' by ' . trim((string) $brand) : '';
        return self::cut("{$name}{$by}, available from {$shop}.", 155);
    }

    public static function plain(?string $s): string
    {
        $s = html_entity_decode(strip_tags((string) $s), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        return trim((string) preg_replace('/\s+/u', ' ', $s));
    }

    /** Cut at a word, with an ellipsis, never mid-word. */
    public static function cut(string $s, int $max): string
    {
        if (mb_strlen($s) <= $max) return $s;
        $c = mb_substr($s, 0, $max - 1);
        $sp = mb_strrpos($c, ' ');
        if ($sp !== false && $sp > $max * 0.6) $c = mb_substr($c, 0, $sp);
        return rtrim($c, " ,;:.-") . '…';
    }

    /**
     * Schema.org markup for one item, as a list of blocks (Product, then breadcrumbs), from plain facts:
     *   name, description, images[], sku, mpn, upc, ean, brand, color, size, price_cents, currency,
     *   in_stock, url, shop, shop_url, crumbs[ [name, url], ... ]
     */
    /** @return array[] */
    public static function jsonld(array $f): array
    {
        $product = ['@type' => 'Product', 'name' => $f['name'], 'url' => $f['url']];
        if (! empty($f['description'])) $product['description'] = $f['description'];
        $imgs = array_values(array_filter((array) ($f['images'] ?? [])));
        if ($imgs) $product['image'] = count($imgs) === 1 ? $imgs[0] : $imgs;
        if (! empty($f['brand'])) $product['brand'] = ['@type' => 'Brand', 'name' => $f['brand']];
        foreach (['sku' => 'sku', 'mpn' => 'mpn', 'color' => 'color', 'size' => 'size'] as $k => $prop) {
            if (trim((string) ($f[$k] ?? '')) !== '') $product[$prop] = (string) $f[$k];
        }
        // a barcode goes in the property that matches its length; anything else is left out rather than guessed
        $upc = preg_replace('/\D/', '', (string) ($f['upc'] ?? ''));
        $ean = preg_replace('/\D/', '', (string) ($f['ean'] ?? ''));
        foreach ([$upc, $ean] as $code) {
            $prop = match (strlen($code)) { 8 => 'gtin8', 12 => 'gtin12', 13 => 'gtin13', 14 => 'gtin14', default => null };
            if ($prop && ! isset($product[$prop])) $product[$prop] = $code;
        }
        if (isset($f['price_cents']) && $f['price_cents'] !== null) {
            $product['offers'] = [
                '@type'           => 'Offer',
                'price'           => number_format($f['price_cents'] / 100, 2, '.', ''),
                'priceCurrency'   => strtoupper((string) ($f['currency'] ?: 'USD')),
                'availability'    => ! empty($f['in_stock']) ? 'https://schema.org/InStock' : 'https://schema.org/OutOfStock',
                'itemCondition'   => 'https://schema.org/NewCondition',
                'url'             => $f['url'],
                'seller'          => ['@type' => 'Organization', 'name' => $f['shop']],
            ];
        }

        // MARKER-ITEM-SEO-BLOCKS: two separate blocks, Product first, so any reader (Google, our own
        // nightly check) finds the Product as a block's top-level type
        $blocks = [['@context' => 'https://schema.org'] + $product];
        $crumbs = array_values((array) ($f['crumbs'] ?? []));
        if (count($crumbs) >= 2) {
            $blocks[] = [
                '@context' => 'https://schema.org',
                '@type' => 'BreadcrumbList',
                'itemListElement' => array_map(fn ($c, $i) => [
                    '@type' => 'ListItem', 'position' => $i + 1, 'name' => $c[0], 'item' => $c[1],
                ], $crumbs, array_keys($crumbs)),
            ];
        }

        return $blocks;
    }

    /** UTM tags on a link a shopper shares, so the shop can see what sharing brings in. */
    public static function shareUrl(string $url, string $channel): string
    {
        return $url . (str_contains($url, '?') ? '&' : '?') . http_build_query(['utm_source' => 'share', 'utm_medium' => $channel]);
    }
}
