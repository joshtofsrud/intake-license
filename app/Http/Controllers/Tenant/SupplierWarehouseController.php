<?php

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * MARKER-SUPPLY: a shop's preferred distributor warehouses, starred from the
 * register's supplier stock panel. Stored on the tenant's settings as
 * preferred_warehouses = { "QBP": ["MN", …], "BTI": ["reno"] }. Only starred
 * warehouses count toward the stock chip for that distributor; a distributor
 * with none starred counts every warehouse.
 */
class SupplierWarehouseController extends Controller
{
    public function toggle(Request $request): JsonResponse
    {
        $data = $request->validate([
            'distributor' => 'required|string|max:12',
            'warehouse'   => 'required|string|max:60',
            'on'          => 'required|boolean',
        ]);
        $tenant = tenant();
        $code = strtoupper(trim($data['distributor']));
        $wh   = trim($data['warehouse']);

        $settings = (array) ($tenant->settings ?? []);
        $prefs = (array) ($settings['preferred_warehouses'] ?? []);
        $list = array_values(array_filter((array) ($prefs[$code] ?? []), fn ($w) => (string) $w !== $wh));
        if ($data['on']) { $list[] = $wh; }
        if ($list) { $prefs[$code] = $list; } else { unset($prefs[$code]); }
        $settings['preferred_warehouses'] = $prefs;
        $tenant->update(['settings' => $settings]);

        return response()->json(['ok' => true, 'preferred_warehouses' => (object) $prefs]);
    }
}
