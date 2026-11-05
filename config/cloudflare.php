<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Cloudflare edge address ranges
    |--------------------------------------------------------------------------
    | Traffic reaches the server through Cloudflare, so the address the server
    | sees is Cloudflare's, not the visitor's. Requests that come FROM one of
    | these ranges are trusted to say who the visitor is (X-Forwarded-For).
    | Anything else is taken at face value, so a request sent straight to the
    | server can't fake an address.
    |
    | Published at https://www.cloudflare.com/ips/ — they change rarely. Add a
    | new range without a deploy by putting it in CLOUDFLARE_EXTRA_IPS
    | (comma separated). If a range is missing, those visitors simply show
    | as a Cloudflare address, which is how it worked before.
    |
    | Set CLOUDFLARE_TRUST=false to switch this off.
    */
    'trust' => env('CLOUDFLARE_TRUST', true),

    'ips' => array_values(array_filter(array_merge([
        // IPv4
        '173.245.48.0/20', '103.21.244.0/22', '103.22.200.0/22', '103.31.4.0/22',
        '141.101.64.0/18', '108.162.192.0/18', '190.93.240.0/20', '188.114.96.0/20',
        '197.234.240.0/22', '198.41.128.0/17', '162.158.0.0/15', '104.16.0.0/13',
        '104.24.0.0/14', '172.64.0.0/13', '131.0.72.0/22',
        // IPv6
        '2400:cb00::/32', '2606:4700::/32', '2803:f800::/32', '2405:b500::/32',
        '2405:8100::/32', '2a06:98c0::/29', '2c0f:f248::/32',
    ], array_map('trim', explode(',', (string) env('CLOUDFLARE_EXTRA_IPS', '')))))),

];
