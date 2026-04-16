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
        'basic'    => env('PLAN_PRICE_BASIC',    2900),   // $29/mo
        'branded'  => env('PLAN_PRICE_BRANDED',  7900),   // $79/mo
        'custom'   => env('PLAN_PRICE_CUSTOM',  19900),   // $199/mo
    ],

];
