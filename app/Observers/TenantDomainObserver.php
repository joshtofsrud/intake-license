<?php

namespace App\Observers;

use App\Models\Tenant\TenantDomain;

/**
 * TenantDomainObserver
 *
 * Enforces: exactly one primary domain per tenant.
 *
 * MySQL doesn't support partial unique indexes (PostgreSQL does), so we
 * enforce this at the application layer. When a domain is set to
 * is_primary=true, other domains for the same tenant get demoted.
 */
class TenantDomainObserver
{
    public function saving(TenantDomain $domain): void
    {
        if (!$domain->is_primary) {
            return;
        }

        // If we're setting this one as primary, demote any other primary
        // domains for the same tenant.
        TenantDomain::query()
            ->where('tenant_id', $domain->tenant_id)
            ->when($domain->exists, fn($q) => $q->where('id', '!=', $domain->id))
            ->where('is_primary', true)
            ->update(['is_primary' => false]);
    }
}
