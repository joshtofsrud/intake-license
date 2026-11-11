<?php

namespace App\Services\Shipping;

use App\Models\PlatformSettings;
use App\Models\Tenant\TenantInventoryItem;

/**
 * MARKER-SHIPPING-RATES: the weight and size shipping uses for an item.
 *   shop's own figure  >  distributor's figure (converted)  >  nothing (the shop's default weight applies)
 * Distributor units are a master-admin setting per distributor, never hard-coded in a client.
 */
class ItemShipSpec
{
    public const DEFAULT_UNITS = ['weight' => 'lb', 'length' => 'in'];

    /** @return array{weight:string,length:string} units a distributor's feed uses */
    public static function unitsFor(?string $code, ?array $platform = null): array
    {
        $platform ??= (array) (PlatformSettings::current()->distribution_settings ?? []);
        $u = (array) ($platform['units'][strtolower((string) $code)] ?? []);
        return [
            'weight' => in_array($u['weight'] ?? null, Units::weightUnits(), true) ? $u['weight'] : self::DEFAULT_UNITS['weight'],
            'length' => in_array($u['length'] ?? null, Units::lengthUnits(), true) ? $u['length'] : self::DEFAULT_UNITS['length'],
        ];
    }

    /** @return array{oz:?float,l:?float,w:?float,h:?float,weight_from:string,size_from:string} */
    public static function for(TenantInventoryItem $item, ?array $platform = null): array
    {
        $cat = $item->relationLoaded('distributorCatalog') ? $item->distributorCatalog : $item->distributorCatalog()->first();
        $u   = $cat ? self::unitsFor($cat->distributor_code, $platform) : self::DEFAULT_UNITS;

        $oz = $item->shop_weight_oz !== null && (float) $item->shop_weight_oz > 0 ? (float) $item->shop_weight_oz : null;
        $wf = $oz !== null ? 'shop' : 'none';
        if ($oz === null && $cat) {
            $oz = Units::toOunces($cat->weight, $u['weight']);
            if ($oz !== null) $wf = 'catalog';
        }

        $d  = [$item->shop_length_in, $item->shop_width_in, $item->shop_height_in];
        $sf = 'none';
        if (min(array_map('floatval', $d)) > 0) {
            $dims = array_map('floatval', $d);
            $sf = 'shop';
        } else {
            $dims = $cat ? Units::dimensions($cat->dimensions, $u['length']) : null;
            if ($dims) $sf = 'catalog';
        }

        return ['oz' => $oz, 'l' => $dims[0] ?? null, 'w' => $dims[1] ?? null, 'h' => $dims[2] ?? null, 'weight_from' => $wf, 'size_from' => $sf];
    }
}
