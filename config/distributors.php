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
        'timeout'        => (int) env('HLC_API_TIMEOUT', 120),
        'retries'        => (int) env('HLC_API_RETRIES', 2),
        'retry_sleep_ms' => (int) env('HLC_API_RETRY_SLEEP', 400),
        'page_size'      => (int) env('HLC_API_PAGE_SIZE', 8000),
    ],

    // MARKER-BTI-ADAPTER — bulk download over HTTP Basic, not a query API.
    // Tenant credentials still live encrypted on the subscription row; only
    // transport settings belong here.
    // MARKER-QBP-ADAPTER — host only. The KEY is not here: it belongs on
    // platform_distributor_connections, entered in master admin like every
    // other distributor's.
    'qbp' => [
        'base_url' => env('QBP_BASE_URL', 'https://api1.qbp.com/api/'),
        'timeout'  => (int) env('QBP_TIMEOUT', 60),
    ],

    // MARKER-QBP-CLS — API3. Different host and different key from API1;
    // the header name happens to be the same, which is a good way to spend an
    // afternoon confused.
    'qbp_cls' => [
        'base_url' => env('QBP_CLS_BASE_URL', 'https://cls.qbp.com/api3/'),
        'timeout'  => (int) env('QBP_CLS_TIMEOUT', 30),
        // Default display size from the documented list:
        // prodt, prodh, prods, prodm, p350x350m, prodl, prodxl
        'image_size' => env('QBP_CLS_IMAGE_SIZE', 'p350x350m'),
    ],

    'bti' => [
        'name'           => 'BTI',
        'base_url'       => env('BTI_BASE', 'https://www.bti-usa.com'),
        // Where relative image_path values hang off.
        'image_base'     => env('BTI_IMAGE_BASE', 'https://www.bti-usa.com/images'),
        'timeout'        => (int) env('BTI_TIMEOUT', 600),   // 43 MB download
        'retries'        => (int) env('BTI_RETRIES', 2),
        'retry_sleep_ms' => (int) env('BTI_RETRY_SLEEP', 1000),
        'page_size'      => (int) env('BTI_PAGE_SIZE', 2000),
        // MARKER-SYNC-PAGE-SIZE — how many PRODUCTS one tier-1 pull may
        // return. Distinct from page_size above, which is the feed reader's
        // chunk hint. The feed is ~7,750 product groups; 25000 is headroom,
        // not a target — BtiClient reads a local cached file, so a larger
        // window costs nothing.
        'sync_page_size' => (int) env('BTI_SYNC_PAGE_SIZE', 25000),
        // MARKER-CACHE-PER-RUN — a feed is now reused only within a single
        // sync run, never across runs, so this is no longer consulted. Kept
        // so an existing BTI_CACHE_HOURS in .env doesn't look meaningful.
        'cache_hours'    => (int) env('BTI_CACHE_HOURS', 6),
    ],

];
