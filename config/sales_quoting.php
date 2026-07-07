<?php
// MARKER-CAMPAIGNS-QUOTE — Quote pricing for the sales pipeline.
// EDIT THESE to your real tiers/add-on prices. Add-on keys deliberately mirror
// tenant addon slugs (online_store, pos, ...) so a won quote can later drive
// provisioning/FeatureAccessService gating without a mapping table.

return [
    'tiers' => [
        'core'     => ['label' => 'Core',     'monthly' => 249],
        'standard' => ['label' => 'Standard', 'monthly' => 329],
        'advanced' => ['label' => 'Advanced', 'monthly' => 389],
    ],

    'addons' => [
        'pos'             => ['label' => 'POS register',         'monthly' => 60],
        'online_store'    => ['label' => 'Online store',         'monthly' => 50],
        'rentals'         => ['label' => 'Rentals',              'monthly' => 40],
        'pickup_delivery' => ['label' => 'Pickup & delivery',    'monthly' => 40],
        'marketing'       => ['label' => 'Marketing & recovery', 'monthly' => 30],
    ],

    // Reference only for now — commission engine comes with the agencies build.
    'commission_year1' => 0.25,
];
