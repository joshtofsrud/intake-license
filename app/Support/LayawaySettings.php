<?php

namespace App\Support;

use App\Models\Tenant;

/**
 * MARKER-LAYAWAY — the shop's policy, with Intake's defaults underneath.
 *
 * Lives in tenants.settings['layaway'], the same place every other tenant
 * preference lives. Read through here so a missing key never becomes a null
 * in arithmetic.
 */
class LayawaySettings
{
    public const DEFAULTS = [
        'min_first_pct'      => 20,        // % of total due to open
        'term_days'          => 90,
        'frequency'          => 'biweekly',
        'grace_days'         => 10,
        'cancel_refund'      => 'less_fee', // less_fee | full | store_credit
        'restock_fee_pct'    => 10,        // % of the HELD line total
        'out_of_stock'       => 'special_order', // special_order | ask
        'arrival_notify'     => 'both',    // both | email | none
    ];

    public static function for(Tenant $tenant): array
    {
        return array_merge(self::DEFAULTS, (array) ($tenant->settings['layaway'] ?? []));
    }

    public static function save(Tenant $tenant, array $values): void
    {
        $settings = $tenant->settings ?? [];
        $settings['layaway'] = array_merge(self::for($tenant), array_intersect_key($values, self::DEFAULTS));
        $tenant->update(['settings' => $settings]);
    }
}
