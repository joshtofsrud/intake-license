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
 * with none starred counts every warehouse. The same setting is the
 * "Count in my stock" switch in each distributor box (PreferredWarehouses).
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
        $prefs = \App\Support\PreferredWarehouses::star(tenant(), strtoupper(trim($data['distributor'])), trim($data['warehouse']), (bool) $data['on']);

        return response()->json(['ok' => true, 'preferred_warehouses' => (object) $prefs]);
    }
}
