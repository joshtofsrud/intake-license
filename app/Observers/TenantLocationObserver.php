<?php

namespace App\Observers;

use App\Models\Tenant\TenantLocation;
use App\Models\Tenant\TenantUser;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class TenantLocationObserver
{
    /**
     * When a new TenantLocation is created, grant all existing owners
     * on the same tenant access to it.
     *
     * Idempotent and try/catch-wrapped. Uses direct DB::insertOrIgnore
     * because tenant_user_locations.id is UUID and HasUuids doesn't fire
     * on raw pivot writes.
     */
    public function created(TenantLocation $location): void
    {
        try {
            $owners = TenantUser::where('tenant_id', $location->tenant_id)
                ->where('role', 'owner')
                ->where('is_active', true)
                ->pluck('id');

            if ($owners->isEmpty()) {
                return;
            }

            $now = now();
            $rows = $owners->map(fn ($userId) => [
                'id'             => (string) Str::uuid(),
                'tenant_id'      => $location->tenant_id,
                'tenant_user_id' => $userId,
                'location_id'    => $location->id,
                'is_active'      => true,
                'created_at'     => $now,
                'updated_at'     => $now,
            ])->all();

            DB::table('tenant_user_locations')->insertOrIgnore($rows);
        } catch (\Throwable $e) {
            Log::warning('TenantLocationObserver: failed to grant owners', [
                'tenant_id'   => $location->tenant_id,
                'location_id' => $location->id,
                'error'       => $e->getMessage(),
            ]);
        }
    }
}
