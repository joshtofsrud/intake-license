<?php

namespace App\Models\Tenant;

/**
 * Staff search vocabulary — words from EVERY active item, not just the ones
 * shown online. The online store's list (TenantSearchTerm) only knows online
 * items, so it could never correct a word for something sold only in the
 * shop. Same shape and same correct() as the store's list.
 */
class TenantStaffSearchTerm extends TenantSearchTerm
{
    protected $table = 'tenant_staff_search_terms';
}
