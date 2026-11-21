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
 * one Intake item, one Connected POS product.
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
     * The item this product plainly is (barcode or part number) when that item already
     * has a Connected POS product of its own — the same thing entered twice in the POS.
     */
    public function findTaken(array $p): ?string
    {
        foreach (Barcode::keys($p['upc'] ?? null, $p['ean'] ?? null) as $k) {
            foreach ($this->byBarcode[$k] ?? [] as $id) {
                if (isset($this->taken[$id])) {
                    return $id;
                }
            }
        }
        $part = self::squash($p['mpn'] ?? null);
        if ($part !== '' && strlen($part) >= 4) {
            $brand = self::squash($p['brand'] ?? null);
            foreach ($this->byPart[$part] ?? [] as [$id, $itemBrand]) {
                if (isset($this->taken[$id]) && $brand !== '' && $brand === $itemBrand) {
                    return $id;
                }
            }
        }

        return null;
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
