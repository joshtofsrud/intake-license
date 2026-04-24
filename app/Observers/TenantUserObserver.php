<?php
namespace App\Observers;

use App\Models\Tenant\TenantResource;
use App\Models\Tenant\TenantUser;
use Illuminate\Support\Facades\Log;

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
    }
}
