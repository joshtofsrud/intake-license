<?php

namespace App\Observers;

use App\Models\Tenant;
use App\Models\Tenant\TenantLocation;
use Illuminate\Support\Facades\Log;

class TenantObserver
{
    /**
     * When a new Tenant is created, seed a default "Main" location.
     *
     * Idempotent — if any location already exists for the tenant, no-ops.
     * The TenantLocationObserver will then auto-grant access to any
     * existing owner users on this tenant (typically none yet at this point;
     * the owner gets created right after the tenant during signup, and
     * TenantUserObserver fires its own grantAllLocations() at that time).
     */
    public function created(Tenant $tenant): void
    {
        $hasLocation = TenantLocation::query()
            ->where('tenant_id', $tenant->id)
            ->exists();

        if ($hasLocation) {
            return;
        }

        try {
            TenantLocation::create([
                'tenant_id'  => $tenant->id,
                'name'       => 'Main',
                'slug'       => 'main',
                'is_default' => true,
                'is_active'  => true,
            ]);
        } catch (\Throwable $e) {
            Log::warning('TenantObserver: failed to seed default location', [
                'tenant_id' => $tenant->id,
                'error'     => $e->getMessage(),
            ]);
        }
    }
}
