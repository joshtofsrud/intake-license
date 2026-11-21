<?php

namespace App\Services\Pos;

use App\Models\Tenant\TenantInventoryItem;
use App\Support\Barcode;
use Illuminate\Support\Facades\DB;

/**
 * MARKER-CONNECTED-POS-SECTION: finds the Intake item a Connected POS product is.
 *
 * One index of the shop's items, built once and reused, so thousands of products
 * can be checked without a query each. The order is the order of evidence:
 *
 *   barcode      UPC or EAN, with or without leading zeros, in either column —
 *                and a barcode typed into an item's SKU box
 *   part         the same manufacturer part number (4+ characters); when both
 *                sides name a brand, the brands must agree
 *   sku          the same SKU. A SKU is a shop's own label, so this one is only a
 *                suggestion: it never links on its own.
 *
 * An item already linked to another Connected POS product is never offered again:
 * one Intake item, one Connected POS product. When the Connected POS has the same
 * product more than once (twins()), the shop picks which one the item follows.
 */
class PosMatcher
{
    public const STRONG = ['barcode', 'part'];

    /** @var array<string, list<string>> */
    private array $byBarcode = [];
    /** @var array<string, list<array{0:string,1:string}>> part => [[item id, brand]] */
    private array $byPart = [];
    /** @var array<string, list<string>> */
    private array $bySku = [];
    /** @var array<string, string> */
    private array $names = [];
    /** @var array<string, true> */
    private array $taken = [];

    public static function forTenant(string $tenantId): self
    {
        // every item's identifiers in memory: about 65 MB for a shop with 45,000 items, which
        // is too close to a web request's default 128 MB (the distributor importer does the same)
        if ((int) ini_get('memory_limit') > 0 && (int) ini_get('memory_limit') < 512) {
            @ini_set('memory_limit', '512M');
        }
        $m = new self();
        $rows = TenantInventoryItem::query()->where('tenant_id', $tenantId)->toBase()
            ->orderByDesc('is_active')->orderBy('created_at')
            ->get(['id', 'sku', 'name', 'catalog_upc', 'catalog_ean', 'catalog_mpn', 'shop_brand']);
        foreach ($rows as $r) {
            $m->learn((string) $r->id, (string) $r->name, $r->sku, $r->catalog_upc, $r->catalog_ean, $r->catalog_mpn, $r->shop_brand);
        }
        foreach (DB::table('tenant_pos_items')->where('tenant_id', $tenantId)->whereNotNull('inventory_item_id')->pluck('inventory_item_id') as $id) {
            $m->taken[(string) $id] = true;
        }

        return $m;
    }

    /** Add an item to the index (also used for items made during an import, so two rows never make two items). */
    public function learn(string $id, string $name, ?string $sku, ?string $upc, ?string $ean, ?string $mpn, ?string $brand): void
    {
        $this->names[$id] = $name;
        foreach (Barcode::keys($upc, $ean, $sku) as $k) {
            $this->byBarcode[$k][] = $id;
        }
        if (($p = self::squash($mpn)) !== '' && strlen($p) >= 4) {
            $this->byPart[$p][] = [$id, self::squash($brand)];
        }
        if (($s = self::sku($sku)) !== '') {
            $this->bySku[$s][] = $id;
        }
    }

    /** This item now has its Connected POS product. */
    public function take(string $itemId): void
    {
        $this->taken[$itemId] = true;
    }

    public function release(string $itemId): void
    {
        unset($this->taken[$itemId]);
    }

    public function name(string $itemId): ?string
    {
        return $this->names[$itemId] ?? null;
    }

    /**
     * @param  array{upc?:?string,ean?:?string,mpn?:?string,sku?:?string,brand?:?string} $p
     * @return array{id:string,how:string}|null
     */
    public function find(array $p): ?array
    {
        foreach (Barcode::keys($p['upc'] ?? null, $p['ean'] ?? null) as $k) {
            if ($id = $this->free($this->byBarcode[$k] ?? [])) {
                return ['id' => $id, 'how' => 'barcode'];
            }
        }

        $part = self::squash($p['mpn'] ?? null);
        if ($part !== '' && strlen($part) >= 4) {
            $brand = self::squash($p['brand'] ?? null);
            foreach ($this->byPart[$part] ?? [] as [$id, $itemBrand]) {
                if (isset($this->taken[$id])) {
                    continue;
                }
                if ($brand === '' || $itemBrand === '' || $brand === $itemBrand) {
                    return ['id' => $id, 'how' => 'part'];
                }
            }
        }

        $sku = self::sku($p['sku'] ?? null);
        if ($sku !== '' && ($id = $this->free($this->bySku[$sku] ?? []))) {
            return ['id' => $id, 'how' => 'sku'];
        }

        return null;
    }

    /**
     * MARKER-POS-TWINS: products that are in the Connected POS more than once — the same
     * barcode, or the same part number under the same brand. One Intake item can follow
     * only one of them, and which one is the shop's call (they may hold different stock),
     * so nothing here is linked or skipped on its own.
     *
     * @param  iterable<object> $products rows with id, upc, ean, mpn, brand
     * @return array<int, list<int>> product id => the ids of its twins (itself left out)
     */
    public static function twins(iterable $products): array
    {
        $byKey = [];
        foreach ($products as $p) {
            foreach (self::strongKeys((array) $p) as $k) {
                $byKey[$k][] = (int) $p->id;
            }
        }
        // union the products that share any key (A–B by barcode, B–C by part number → one group)
        $parent = [];
        $find = function (int $x) use (&$parent, &$find): int {
            $parent[$x] ??= $x;
            return $parent[$x] === $x ? $x : ($parent[$x] = $find($parent[$x]));
        };
        foreach ($byKey as $ids) {
            if (count($ids) < 2) {
                continue;
            }
            $root = $find($ids[0]);
            foreach ($ids as $id) {
                $parent[$find($id)] = $root;
            }
        }
        $groups = [];
        foreach (array_keys($parent) as $id) {
            $groups[$find($id)][] = $id;
        }
        $out = [];
        foreach ($groups as $ids) {
            foreach ($ids as $id) {
                $out[$id] = array_values(array_diff($ids, [$id]));
            }
        }

        return $out;
    }

    /** What makes two products plainly the same thing. A SKU alone never does. */
    public static function strongKeys(array $p): array
    {
        $keys = [];
        foreach (Barcode::keys($p['upc'] ?? null, $p['ean'] ?? null) as $k) {
            $keys[] = 'b:' . $k;
        }
        $part = self::squash($p['mpn'] ?? null);
        $brand = self::squash($p['brand'] ?? null);
        if ($part !== '' && strlen($part) >= 4 && $brand !== '') {
            $keys[] = 'p:' . $brand . '|' . $part;
        }

        return $keys;
    }

    /** The Intake item a product matches, whether or not it already has a Connected POS product. */
    public function findAny(array $p): ?array
    {
        $taken = $this->taken;
        $this->taken = [];
        try {
            return $this->find($p);
        } finally {
            $this->taken = $taken;
        }
    }

    /** @param list<string> $ids */
    private function free(array $ids): ?string
    {
        foreach ($ids as $id) {
            if (! isset($this->taken[$id])) {
                return $id;
            }
        }

        return null;
    }

    private static function squash(?string $v): string
    {
        return strtoupper(preg_replace('/[^A-Za-z0-9]/', '', (string) $v));
    }

    private static function sku(?string $v): string
    {
        return strtoupper(trim((string) $v));
    }
}
