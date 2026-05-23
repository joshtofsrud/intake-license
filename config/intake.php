<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Current plugin version served to licensees
    |--------------------------------------------------------------------------
    */
    'plugin_version' => env('PLUGIN_VERSION', '1.0.0-alpha.16'),

    /*
    |--------------------------------------------------------------------------
    | Absolute path to the distributable plugin ZIP on the server.
    |--------------------------------------------------------------------------
    */
    'plugin_zip_path' => env('PLUGIN_ZIP_PATH'),

    /*
    |--------------------------------------------------------------------------
    | Platform domain configuration
    |--------------------------------------------------------------------------
    | The root domain and any reserved subdomains that belong to the platform
    | rather than to tenants. The tenant resolver skips these.
    */
    'domain' => env('APP_DOMAIN', 'intake.works'),

    'reserved_subdomains' => [
        'www',
        'app',
        'license',
        'api',
        'admin',
        'mail',
        'smtp',
        'ftp',
        'static',
        'assets',
        'cdn',
        'status',
        'health',
        'support',
    ],

    /*
    |--------------------------------------------------------------------------
    | Tenant subdomain pattern
    |--------------------------------------------------------------------------
    | Slugs must match this regex. Enforced at signup.
    */
    'subdomain_pattern' => '/^[a-z0-9][a-z0-9\-]{1,61}[a-z0-9]$/',

    /*
    |--------------------------------------------------------------------------
    | Onboarding fee (in cents) — shown during checkout
    |--------------------------------------------------------------------------
    */
    'onboarding_fee_cents' => env('ONBOARDING_FEE_CENTS', 19900), // $199

    /*
    |--------------------------------------------------------------------------
    | Plan pricing (monthly, in cents)
    |--------------------------------------------------------------------------
    */
    'plan_prices' => [
        'starter'  => env('PLAN_PRICE_STARTER',  2900),   // $29/mo
        'branded'  => env('PLAN_PRICE_BRANDED',  7900),   // $79/mo
        'scale'    => env('PLAN_PRICE_SCALE',   19900),   // $199/mo
        'custom'   => env('PLAN_PRICE_CUSTOM',      0),   // master-admin-assigned, variable
    ],

    /*
    |--------------------------------------------------------------------------
    | Image library storage quotas (in bytes)
    |--------------------------------------------------------------------------
    | Per-tier total storage caps for campaign images. Per-file cap applies
    | to every tier. Enforced on upload.
    */
    'image_quotas' => [
        'per_file_bytes' => env('IMAGE_PER_FILE_BYTES', 5 * 1024 * 1024),    // 5 MB
        'tiers' => [
            'starter' => env('IMAGE_QUOTA_STARTER', 100 * 1024 * 1024),      // 100 MB
            'branded' => env('IMAGE_QUOTA_BRANDED', 500 * 1024 * 1024),      // 500 MB
            'scale'   => env('IMAGE_QUOTA_SCALE',   2 * 1024 * 1024 * 1024), // 2 GB
            'custom'  => env('IMAGE_QUOTA_CUSTOM',  2 * 1024 * 1024 * 1024), // 2 GB (default, can override per-tenant)
        ],
        'allowed_mime' => [
            'image/jpeg',
            'image/png',
            'image/gif',
            'image/webp',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Auth refactor — PIN tier policy (chunk 5)
    |--------------------------------------------------------------------------
    | Constants the PIN tier reads. Tunable per-tenant later via the
    | sign-in security admin screen (chunk 8); for now these are the
    | platform-wide defaults.
    */
    'auth' => [
        // PIN entry failures ladder. Index = failure count (0-based).
        // Value = cooldown seconds before the next attempt is allowed.
        // After the last entry in the array, the card soft-locks until
        // owner unlock or email reset.
        'pin_cooldown_ladder' => [0, 0, 5, 30, 0],

        // Total failures across the device before the WHOLE device
        // requires email + password re-auth. Hard rule from spec §4.
        'pin_device_lockout_threshold' => 5,

        // Window (minutes) over which the device-lockout count is
        // measured. Failures older than this are forgotten.
        'pin_device_lockout_window_min' => 10,

        // Sliding-window device trust expiry (days).
        'device_trust_expiry_days' => 90,

        // Idle lock — Layer 3 of the auth refactor (chunk 6).
        // last_pin_activity_at older than this triggers the lock overlay.
        // Tunable per-tenant later via the sign-in security admin (chunk 8).
        'pin_idle_threshold_sec' => 120,

        // Client heartbeat interval. Should be well below the idle
        // threshold; we picked half. 60s pings against a 120s threshold
        // means at most one missed heartbeat before the lock fires.
        'pin_heartbeat_interval_sec' => 60,

        // Sensitive-action gates (chunk 7). Keys are action names; values
        // are sticky-window seconds. A value of 0 means "always prompt"
        // — every action requires a fresh PIN.
        //
        // To add a new gated action: add the key here, then have the
        // controller call PinGateService::requirePin($request, $key) and
        // return 403 { error: 'pin_required', action: $key } when true.
        'pin_action_sticky_sec' => [
            'switch_location' => 0,  // always prompt — user choice
            // Future actions go here. Examples:
            //   'refund'           => 0,        // always prompt
            //   'void_sale'        => 0,        // always prompt
            //   'override_oversold' => 300,     // 5 min sticky
            //   'manager_override' => 0,        // always prompt
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Custom domain limits per plan tier (MARKER-PATCH-120)
    |--------------------------------------------------------------------------
    | Maximum domains a tenant can register per their plan tier.
    | null = unlimited.
    | Used by DomainController to gate creation.
    */
    'domain_limits' => [
        'starter' => 1,
        'branded' => 3,
        'scale'   => 3,
        'custom'  => null,
    ],

];
