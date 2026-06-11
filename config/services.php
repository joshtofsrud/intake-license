<?php

return [
    'twilio' => [
        'driver' => env('TWILIO_DRIVER', 'null'),
        'sid'    => env('TWILIO_SID'),
        'token'  => env('TWILIO_TOKEN'),
        'from'   => env('TWILIO_FROM'),
    ],

    // MARKER-PATCH-224B
    'anthropic' => [
        'key' => env('ANTHROPIC_API_KEY'),
    ],

    // MARKER-PATCH-117 - Cloudflare for SaaS custom hostnames
    'cloudflare' => [
        // API token scoped to (SSL and Certificates: Edit, Zone: Read) on
        // the intake.works zone. Generated in Cloudflare dashboard.
        'api_token' => env('CLOUDFLARE_API_TOKEN'),

        // Zone ID for intake.works (found in zone Overview, right sidebar).
        'zone_id'   => env('CLOUDFLARE_ZONE_ID'),

        // The hostname Cloudflare proxies custom domains to. This is the
        // fallback origin configured in Cloudflare for SaaS. Tenants CNAME
        // their domains here.
        'fallback_origin' => env('CLOUDFLARE_FALLBACK_ORIGIN', 'link.intake.works'),

        // API base. Almost never changes. Override only for testing.
        'api_base' => env('CLOUDFLARE_API_BASE', 'https://api.cloudflare.com/client/v4'),

        // HTTP timeout in seconds. Cloudflare API is fast but DNS validation
        // on their end can take a moment.
        'http_timeout' => (int) env('CLOUDFLARE_HTTP_TIMEOUT', 15),

        // HMAC-SHA256 shared secret used to verify CF custom-hostname webhooks.
        // Configure in CF: Notifications → Add destination → Webhooks → secret.
        'webhook_secret' => env('CLOUDFLARE_WEBHOOK_SECRET'),
    ],
];
