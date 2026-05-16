<?php

namespace App\Http\Controllers\Tenant\Concerns;

use App\Services\FeatureAccessService;

/**
 * GuardsRetailAccess
 *
 * Controller-side mirror of RequireRetailCapability middleware. Used by
 * controllers (currently InventoryController) that want to assert the
 * 'retail' capability at method entry rather than route-group level.
 *
 * Both this trait and the middleware check the same FeatureAccessService
 * call — single source of truth for the gate logic. Register uses the
 * middleware (route-group, can't be forgotten); Inventory uses the trait
 * for now since its existing per-method pattern works fine.
 */
trait GuardsRetailAccess
{
    protected function assertRetailEnabled($tenant): void
    {
        if (!app(FeatureAccessService::class)->hasAddon($tenant, 'retail')) {
            abort(403, 'Retail operations require the Retail capability. Upgrade to Branded or Scale to access the register and inventory.');
        }
    }
}
