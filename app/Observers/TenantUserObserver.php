<?php
namespace App\Observers;

use App\Models\Tenant\TenantLocation;
use App\Models\Tenant\TenantResource;
use App\Models\Tenant\TenantUser;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class TenantUserObserver
{
    /**
     * When a new TenantUser is created with role 'owner',
     * seed a default calendar resource for their tenant.
     *
     * Idempotent — if any resource already exists for the tenant,
     * this no-ops. Wrapped to guarantee tenant signup never fails
     * because of a downstream seed error.
     */
    public function created(TenantUser $user): void
    {
        if (!$user->isOwner()) {
            return;
        }

        $hasResources = TenantResource::query()
            ->where('tenant_id', $user->tenant_id)
            ->exists();

        if ($hasResources) {
            return;
        }

        try {
            TenantResource::create([
                'tenant_id'     => $user->tenant_id,
                'name'          => $user->name,
                'subtitle'      => null,
                'color_hex'     => '#59D3E6',
                'type'          => 'staff',
                'staff_user_id' => $user->id,
                'sort_order'    => 0,
                'is_active'     => true,
            ]);
        } catch (\Throwable $e) {
            Log::warning('TenantUserObserver: failed to seed default resource', [
                'tenant_id' => $user->tenant_id,
                'user_id'   => $user->id,
                'error'     => $e->getMessage(),
            ]);
        }

        $this->grantAllLocations($user);
    }

    /**
     * Grant the new owner access to every existing location on their tenant.
     * Idempotent — if any grant already exists, no-ops on duplicates via
     * unique compound index. Direct DB::insert because attach() requires
     * UUID generation that HasUuids doesn't fire on pivot tables.
     */
    protected function grantAllLocations(TenantUser $user): void
    {
        try {
            $locations = TenantLocation::where('tenant_id', $user->tenant_id)
                ->where('is_active', true)
                ->pluck('id');

            if ($locations->isEmpty()) {
                return;
            }

            $now = now();
            $rows = $locations->map(fn ($locationId) => [
                'id'             => (string) Str::uuid(),
                'tenant_id'      => $user->tenant_id,
                'tenant_user_id' => $user->id,
                'location_id'    => $locationId,
                'is_active'      => true,
                'created_at'     => $now,
                'updated_at'     => $now,
            ])->all();

            DB::table('tenant_user_locations')->insertOrIgnore($rows);
        } catch (\Throwable $e) {
            Log::warning('TenantUserObserver: failed to grant locations', [
                'tenant_id' => $user->tenant_id,
                'user_id'   => $user->id,
                'error'     => $e->getMessage(),
            ]);
        }
    }
}
