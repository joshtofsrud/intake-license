<?php
// per-distributor adapter config. Distributor sync is
// gated by the bike_distributor_sync add-on (Scale floor); this file just
// holds endpoint/transport settings. Tenant credentials live encrypted on
// tenant_distributor_catalog_subscriptions, never here.

return [

    // minimum share of a catalog title that must change
    // (0..1, on case/whitespace-normalized strings) before a title_changed
    // attention flag opens. Smaller edits silently advance the seen baseline.
    'title_change_min_ratio' => (float) env('INTAKE_TITLE_CHANGE_MIN_RATIO', 0.15),

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

    // bulk download over HTTP Basic, not a query API.
    // Tenant credentials still live encrypted on the subscription row; only
    // transport settings belong here.
    // host only. The KEY is not here: it belongs on
    // platform_distributor_connections, entered in master admin like every
    // other distributor's.
    'qbp' => [
        'base_url' => env('QBP_BASE_URL', 'https://api1.qbp.com/api/'),
        'timeout'  => (int) env('QBP_TIMEOUT', 60),
    ],

    // API3. Different host and different key from API1;
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
        // product photos live under /images/pictures,
        // not /images. Confirmed against a live BTI page:
        //   https://bti-usa.com/images/pictures/ma/ma3512a.jpg
        // BTI sends image_paths as "/1k/1k506009.jpg", which appends to this.
        'image_base'     => env('BTI_IMAGE_BASE', 'https://bti-usa.com/images/pictures'),
        'timeout'        => (int) env('BTI_TIMEOUT', 600),   // 43 MB download
        'retries'        => (int) env('BTI_RETRIES', 2),
        'retry_sleep_ms' => (int) env('BTI_RETRY_SLEEP', 1000),
        'page_size'      => (int) env('BTI_PAGE_SIZE', 2000),
        // how many PRODUCTS one tier-1 pull may
        // return. Distinct from page_size above, which is the feed reader's
        // chunk hint. The feed is ~7,750 product groups; 25000 is headroom,
        // not a target — BtiClient reads a local cached file, so a larger
        // window costs nothing.
        'sync_page_size' => (int) env('BTI_SYNC_PAGE_SIZE', 25000),
        // a feed is now reused only within a single
        // sync run, never across runs, so this is no longer consulted. Kept
        // so an existing BTI_CACHE_HOURS in .env doesn't look meaningful.
        'cache_hours'    => (int) env('BTI_CACHE_HOURS', 6),
    ],

    // SFTP spreadsheet feed. The login is NOT here: it is
    // entered once in master admin (Distributors) and used for every shop.
    // Pin the server's host key (SHA-256 fingerprint) so nobody can stand in
    // for it. Needs the openssh-client package (sftp, ssh-keyscan).
    'shimano' => [
        'name'            => 'Shimano',
        'host'            => env('SHIMANO_HOST', 'ftpsna.shimano.com'),
        'port'            => (int) env('SHIMANO_PORT', 22),
        'dir'             => env('SHIMANO_DIR', 'US'),
        'host_key_sha256' => env('SHIMANO_HOST_KEY_SHA256', 'hJseMiQvY/RhwiEdbXWLyJ54pncExGm+qeXeXGSOG88'),
        'host_key_md5'    => env('SHIMANO_HOST_KEY_MD5'),
        // Shimano's public host key itself (not a secret). OpenSSH checks the
        // server against exactly this key on every connection. Shimano's
        // server refuses ssh-keyscan, so the key has to ship rather than be
        // fetched. It must match the SHA-256 pin above or nothing connects.
        'host_key'        => env('SHIMANO_HOST_KEY', 'ssh-rsa AAAAB3NzaC1yc2EAAAADAQABAAABAQCv0Gv9gb8QHZddOKpahRFOYNuNmzVer3lHfhXtpPE/cA7/5jrfkMoHTef+DgbmPOCJ8BpZap+UtZzCZTwGDfEaF7bqYk2RqJaJq49+StdbQo5RsGhg3KBUHPyg5sO0+iS48z56MTwWyPaO7XTkxamkC2TFWsLssYhnrzS5sngy+kSYHwX9RABYqA/KZA6JUQhXueLvyizGFiPl908rBV7RwxGqSZ24abu4cNzqtXc5mYG/Aup25Sjm85Hw3JmpBezZRoIKzdhgS5w2JNiKIi5QjwMzQ2gCeg8biEQZX6OaTSadVbiY69DcMgOyJ+MM0Cb3dgNIfPBhDXKC1TJYGn2n'),
        'insecure'        => (bool) env('SHIMANO_INSECURE', false),
        'timeout'         => (int) env('SHIMANO_TIMEOUT', 600),
        // the flattened feed is reused this long by stock/price refreshes;
        // a catalog sync always fetches a fresh copy.
        'cache_minutes'   => (int) env('SHIMANO_CACHE_MINUTES', 30),
        // after a failed connection nothing contacts Shimano for this long;
        // their server blocks an address that keeps retrying.
        'backoff_minutes' => (int) env('SHIMANO_BACKOFF_MINUTES', 30),
        // the whole US feed is a few thousand products
        'sync_page_size'  => (int) env('SHIMANO_SYNC_PAGE_SIZE', 50000),
    ],

];
