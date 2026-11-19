<?php

namespace App\Support;

use App\Models\PlatformDistributorCatalog;
use App\Models\Tenant\TenantInventoryItem;

/**
 * MARKER-PRODUCT-DETAILS: everything the item page and the online store
 * page show about a catalog product beyond its name and price: specs in
 * groups, geometry for every size, and the other sizes of the same model.
 * Each part is empty when the distributor didn't send it, and the pages
 * show a section only when it has something in it.
 */
class ProductDetails
{
    /**
     * @return array{specs: array, geometry: array, sizes: array, size: string, has: bool}
     */
    public static function for(TenantInventoryItem $item): array
    {
        $cat = $item->distributorCatalog;
        $empty = ['specs' => [], 'geometry' => ['sizes' => [], 'rows' => []], 'sizes' => [], 'size' => '', 'sizeKey' => '', 'fit' => null, 'story' => null, 'has' => false];
        if (! $cat) {
            return $empty;
        }
        $size = trim((string) $cat->size);
        $raw  = (array) ($cat->source_raw ?? []);
        $sizes = self::siblings($item, $cat);

        $out = [
            'specs'    => self::specs((array) ($cat->attributes ?? []), self::key($size)),
            'geometry' => self::geometry((array) ($raw['geometry_all'] ?? []), array_map([self::class, 'key'], array_column($sizes, 'size'))),
            'sizes'    => count($sizes) > 1 ? $sizes : [],
            'size'     => $size,
            'sizeKey'  => self::key($size),
            'fit'      => self::fit((array) ($raw['sizing'] ?? []), $size),
            'story'    => self::story($raw['story'] ?? null),
        ];
        $out['has'] = $out['specs'] || $out['geometry']['rows'] || $out['sizes'] || $out['fit'] || $out['story'];
        return $out;
    }


    /**
     * The brand's sizing chart for this model, with the rows this size fits.
     * [model, range ("5'6"–6'2""), rows[height_in, height_cm, inseam_in, inseam_cm, size, fits]].
     * Null when the brand sent no chart for the model.
     */
    public static function fit(array $sizing, string $size): ?array
    {
        if (empty($sizing['rows'])) {
            return null;
        }
        $key = self::key($size);
        $letters = ['xs' => 'xs', 's' => 'small', 'sm' => 'small', 'm' => 'medium', 'md' => 'medium', 'l' => 'large', 'lg' => 'large', 'xl' => 'xl', 'xxl' => 'xxl'];
        $me = strtolower($key);
        $rows = [];
        $fitting = [];
        foreach ($sizing['rows'] as $i => $r) {
            $fits = false;
            foreach (preg_split('/\s*,\s*/', (string) ($r['size'] ?? '')) as $tok) {
                $tok = strtolower(trim($tok));
                if (preg_match('/^(\d{2,3})(?:\s*-\s*(\d{2,3}))?\s*(?:cm)?$/', $tok, $m) && ctype_digit($key)) {
                    $a = (int) $m[1];
                    $b = isset($m[2]) && $m[2] !== '' ? (int) $m[2] : $a;
                    $fits = $fits || ((int) $key >= $a && (int) $key <= $b);
                } elseif ($tok !== '' && isset($letters[$me]) && $tok === $letters[$me]) {
                    $fits = true;
                }
            }
            $rows[] = $r + ['fits' => $fits];
            if ($fits) {
                $fitting[] = $i;
            }
        }
        $range = null;
        if ($fitting) {
            $first = preg_split('/\s*[-–]\s*/u', (string) $rows[$fitting[0]]['height_in']);
            $last  = preg_split('/\s*[-–]\s*/u', (string) $rows[end($fitting)]['height_in']);
            if (! empty($first[0]) && ! empty(end($last))) {
                $range = $first[0] . '–' . end($last);
            }
        }
        return ['model' => (string) ($sizing['model'] ?? ''), 'range' => $range, 'rows' => $rows];
    }

    /** The brand's story section: [title, blocks] where a block is text, a heading, or one to three captioned photos. */
    public static function story($story): ?array
    {
        if (! is_array($story) || empty($story['blocks'])) {
            return null;
        }
        $blocks = [];
        foreach ($story['blocks'] as $b) {
            if (! is_array($b)) {
                continue;
            }
            if (($b['t'] ?? '') === 'imgs') {
                $items = array_values(array_filter((array) ($b['items'] ?? []), fn ($i) => is_array($i) && str_starts_with((string) ($i['src'] ?? ''), 'https://')));
                if ($items) {
                    $blocks[] = ['t' => 'imgs', 'items' => $items];
                }
            } elseif (in_array($b['t'] ?? '', ['p', 'h'], true) && trim((string) ($b['text'] ?? '')) !== '') {
                $blocks[] = ['t' => $b['t'], 'text' => trim((string) $b['text'])];
            }
        }
        return $blocks ? ['title' => trim((string) ($story['title'] ?? '')), 'blocks' => $blocks] : null;
    }

    /** "54cm" and "54" are the same size: geometry tables say 54, the catalog says 54cm. */
    public static function key(string $size): string
    {
        return trim((string) preg_replace('/\s*cm$/i', '', trim($size)));
    }

    /** Spec rows [label, value, all], grouped when there are enough of them. */
    public static function specs(array $attributes, string $size): array
    {
        $hide = array_map('strtolower', (array) config('product_details.hide', []));
        $rows = [];
        foreach ($attributes as $a) {
            if (! is_array($a) || ! isset($a['Name'], $a['Value'])) {
                continue;
            }
            $name = trim((string) $a['Name']);
            $val  = trim((string) $a['Value']);
            if ($name === '' || $val === '' || in_array(strtolower($name), $hide, true)) {
                continue;
            }
            [$shown, $all] = self::forSize($val, $size);
            $rows[] = ['label' => $name, 'value' => $shown, 'all' => $all];
        }
        if (count($rows) < (int) config('product_details.min_for_groups', 8)) {
            return $rows ? [['group' => null, 'rows' => $rows]] : [];
        }
        $groups = [];
        $other  = (string) config('product_details.other_group', 'Other');
        foreach ($rows as $r) {
            $g = $other;
            foreach ((array) config('product_details.groups', []) as $label => $words) {
                foreach ($words as $w) {
                    if (str_contains(strtolower($r['label']), $w)) {
                        $g = $label;
                        break 2;
                    }
                }
            }
            $groups[$g][] = $r;
        }
        $ordered = [];
        foreach (array_merge(array_keys((array) config('product_details.groups', [])), [$other]) as $g) {
            if (! empty($groups[$g])) {
                $ordered[] = ['group' => $g, 'rows' => $groups[$g]];
            }
        }
        return $ordered;
    }

    /**
     * A part some brands list once per frame size, like
     * "Kona 31.8mm / Rise 6º / Length 48-52 60mm / 54-56 70mm / 58 80mm".
     * For size 54 this gives ["Kona 31.8mm, Rise 6º · Length 70mm", "<the full text>"].
     * Anything that doesn't read that way comes back as it is, with no second text.
     *
     * @return array{0: string, 1: ?string}
     */
    public static function forSize(string $value, string $size): array
    {
        if (! preg_match('/^\d{2,3}$/', $size) || ! str_contains($value, ' / ')) {
            return [$value, null];
        }
        $n = (int) $size;
        $base = [];
        $hit = null;
        $label = null;
        $matched = 0;
        foreach (preg_split('~\s+/\s+~', $value) as $seg) {
            if (preg_match('~^(?:(?<l>[A-Za-z][A-Za-z ]*?)\s+)?(?<a>\d{2,3})(?:\s*-\s*(?<b>\d{2,3}))?\s+(?<v>\S.*)$~u', $seg, $m)) {
                $matched++;
                $label = ($m['l'] ?? '') !== '' ? $m['l'] : $label;
                $a = (int) $m['a'];
                $b = ($m['b'] ?? '') !== '' ? (int) $m['b'] : $a;
                if ($n >= $a && $n <= $b) {
                    $hit = trim(($label ? $label . ' ' : '') . $m['v']);
                }
            } else {
                $base[] = $seg;
            }
        }
        if ($matched < 2 || $hit === null) {
            return [$value, null];
        }
        return [trim(implode(', ', $base) . ' · ' . $hit, ' ·'), $value];
    }

    /** @return array{sizes: array<int,string>, rows: array<int, array{label: string, unit: string, values: array}>} */
    public static function geometry(array $geo, array $order = []): array
    {
        if (! $geo) {
            return ['sizes' => [], 'rows' => []];
        }
        $sizes = [];
        foreach ($geo as $bySize) {
            foreach (array_keys((array) $bySize) as $s) {
                $sizes[(string) $s] = true;
            }
        }
        $sizes = array_keys($sizes);
        if ($order) {
            usort($sizes, fn ($a, $b) => (array_search($a, $order, true) === false ? 99 : array_search($a, $order, true))
                <=> (array_search($b, $order, true) === false ? 99 : array_search($b, $order, true)));
        }
        $names = (array) config('product_details.geometry_labels', []);
        $rows = [];
        foreach ($geo as $label => $bySize) {
            $key = strtolower(trim((string) $label));
            $rows[] = [
                'label'  => $names[$key] ?? ucfirst(strtolower((string) $label)),
                'unit'   => str_contains($key, 'angle') ? '°' : 'mm',
                'values' => (array) $bySize,
            ];
        }
        return ['sizes' => $sizes, 'rows' => $rows];
    }

    /**
     * The other sizes of this model, in size order, with what this shop has
     * of each: [size, catalog_id, item_id|null, stock|null, current].
     */
    public static function siblings(TenantInventoryItem $item, PlatformDistributorCatalog $cat): array
    {
        if (! $cat->distributor_product_no) {
            return [];
        }
        $rows = PlatformDistributorCatalog::query()
            ->where('distributor_code', $cat->distributor_code)
            ->where('distributor_product_no', $cat->distributor_product_no)
            ->where('is_active', true)
            ->get(['id', 'size', 'distributor_variant_no']);
        if ($rows->count() < 2) {
            return [];
        }
        $ids = $rows->pluck('id')->all();
        $mine = TenantInventoryItem::query()->where('tenant_id', $item->tenant_id)
            ->where(function ($w) use ($ids) {
                $w->whereIn('distributor_catalog_id', $ids)
                  ->orWhereHas('vendors', fn ($v) => $v->whereIn('tenant_inventory_item_vendors.distributor_catalog_id', $ids));
            })->get(['id', 'distributor_catalog_id', 'computed_stock_count', 'is_active']);
        $byCat = [];
        foreach ($mine as $m) {
            $byCat[(string) $m->distributor_catalog_id] = $m;
        }
        $out = [];
        foreach ($rows as $r) {
            $m = $byCat[(string) $r->id] ?? ($r->id === $cat->id ? $item : null);
            $out[] = [
                'size'       => trim((string) $r->size),
                'catalog_id' => (string) $r->id,
                'item_id'    => $m ? (string) $m->id : null,
                'stock'      => $m ? (int) $m->computed_stock_count : null,
                'current'    => (string) $r->id === (string) $cat->id,
            ];
        }
        $order = (array) config('product_details.size_order', []);
        $rank = function (string $s) use ($order) {
            if (preg_match('/^\d+(\.\d+)?/', $s, $m)) {
                return [0, (float) $m[0]];
            }
            $i = array_search(strtoupper($s), $order, true);
            return [1, $i === false ? 99 : $i];
        };
        usort($out, fn ($a, $b) => $rank($a['size']) <=> $rank($b['size']));
        return $out;
    }
}
