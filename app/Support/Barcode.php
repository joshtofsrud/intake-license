<?php
// MARKER-BARCODE-IDENTITY

namespace App\Support;

use App\Models\Tenant\TenantInventoryItem;
use Illuminate\Support\Facades\DB;

/**
 * A barcode is a barcode, whichever column it sits in.
 *
 * A 12-digit UPC-A is the 13-digit EAN with a leading zero, and imports and
 * staff put one where the other belongs — a UPC in the EAN column, an EAN in
 * the UPC column, a barcode typed into the SKU box. Identity therefore
 * compares ONE pool of barcodes per item, normalised:
 *   - digits only (spaces and dashes allowed in the input, nothing else —
 *     a SKU like "TB73305200" is not a barcode just because it has digits);
 *   - at least 8 digits (EAN-8), and never all zeros;
 *   - 12 digits read as 13 (leading zero added);
 *   - a zero-padded 14-digit GTIN read as 13.
 * Used by the distributor importer, CSV import, Add item and the duplicate
 * finder, so none of them can disagree about what "the same barcode" means.
 */
class Barcode
{
    public static function normalize(?string $value): ?string
    {
        $v = str_replace([' ', '-'], '', trim((string) $value));
        if ($v === '' || ! ctype_digit($v) || strlen($v) < 8 || strlen($v) > 14 || trim($v, '0') === '') {
            return null;
        }
        if (strlen($v) === 12) {
            $v = '0' . $v;
        }
        if (strlen($v) === 14 && $v[0] === '0') {
            $v = substr($v, 1);
        }

        return $v;
    }

    /** Normalised keys for any number of raw values, de-duplicated. */
    public static function keys(...$values): array
    {
        $out = [];
        foreach ($values as $v) {
            if ($k = self::normalize(is_scalar($v) ? (string) $v : null)) {
                $out[$k] = true;
            }
        }

        return array_keys($out);
    }

    /** The raw spellings a normalised key can be stored under. */
    public static function spellings(string $key): array
    {
        $s = [$key, '0' . $key];
        if (strlen($key) === 13 && $key[0] === '0') {
            $s[] = substr($key, 1);
        }

        return array_values(array_unique($s));
    }

    /**
     * Items in this shop carrying any of these barcodes — in the UPC, EAN or
     * SKU column, or as an alias left behind by a merge. Returns normalised
     * key => item. Deleted items are ignored.
     */
    public static function itemsFor(string $tenantId, array $keys, ?string $exceptItemId = null): array
    {
        $keys = array_values(array_unique(array_filter($keys)));
        if (! $keys) {
            return [];
        }
        $spell = array_merge(...array_map([self::class, 'spellings'], $keys));

        $items = TenantInventoryItem::where('tenant_id', $tenantId)
            ->when($exceptItemId, fn ($q) => $q->where('id', '!=', $exceptItemId))
            ->where(fn ($q) => $q->whereIn('catalog_upc', $spell)->orWhereIn('catalog_ean', $spell)->orWhereIn('sku', $spell))
            ->get();

        $out = [];
        foreach ($items as $item) {
            foreach (self::keys($item->catalog_upc, $item->catalog_ean, $item->sku) as $k) {
                if (in_array($k, $keys, true) && ! isset($out[$k])) {
                    $out[$k] = $item;
                }
            }
        }

        // Codes an item kept from a merge still identify it.
        $missing = array_values(array_diff($keys, array_keys($out)));
        if ($missing) {
            $aliasSpell = array_merge(...array_map([self::class, 'spellings'], $missing));
            $aliases = DB::table('tenant_inventory_item_aliases')
                ->where('tenant_id', $tenantId)->whereIn('code', $aliasSpell)
                ->when($exceptItemId, fn ($q) => $q->where('inventory_item_id', '!=', $exceptItemId))
                ->get(['code', 'inventory_item_id']);
            if ($aliases->isNotEmpty()) {
                $byId = TenantInventoryItem::where('tenant_id', $tenantId)
                    ->whereIn('id', $aliases->pluck('inventory_item_id')->unique())->get()->keyBy('id');
                foreach ($aliases as $a) {
                    $k = self::normalize($a->code);
                    if ($k && ! isset($out[$k]) && isset($byId[$a->inventory_item_id])) {
                        $out[$k] = $byId[$a->inventory_item_id];
                    }
                }
            }
        }

        return $out;
    }
}
