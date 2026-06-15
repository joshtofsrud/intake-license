<?php
// MARKER-PATCH-HLC1 — per-distributor adapter config. Distributor sync is
// gated by the bike_distributor_sync add-on (Scale floor); this file just
// holds endpoint/transport settings. Tenant credentials live encrypted on
// tenant_distributor_catalog_subscriptions, never here.

return [

    'hlc' => [
        'name'           => 'HLC',
        'base_url'       => env('HLC_API_BASE', 'https://api.hlc.bike'),
        'version'        => env('HLC_API_VERSION', 'v4.1'),
        'default_region' => env('HLC_API_REGION', 'us'),
        'language'       => env('HLC_API_LANGUAGE', 'en'),
        // Auth header form. HLC docs show two; the catalog endpoints can
        // be picky. distributors:hlc-test probes the right one.
        'auth_style'     => env('HLC_AUTH_STYLE', 'authorization_apikey'),
        'timeout'        => (int) env('HLC_API_TIMEOUT', 20),
        'retries'        => (int) env('HLC_API_RETRIES', 2),
        'retry_sleep_ms' => (int) env('HLC_API_RETRY_SLEEP', 400),
        'page_size'      => (int) env('HLC_API_PAGE_SIZE', 100),
    ],

];
