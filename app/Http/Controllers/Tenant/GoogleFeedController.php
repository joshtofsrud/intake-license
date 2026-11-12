<?php

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Services\Feeds\GoogleFeed;
use App\Support\Seo;

/** MARKER-GOOGLE-FEED: the public feed Google Merchant Center fetches. The long random link is the key. */
class GoogleFeedController extends Controller
{
    public function feed(string $token)
    {
        $tenant = tenant();
        $c = GoogleFeed::config($tenant);
        abort_unless($c['on'] && $c['token'] !== '' && hash_equals($c['token'], $token) && Seo::storefrontOn($tenant), 404);

        return response()->stream(function () use ($tenant) {
            GoogleFeed::stream($tenant, function (string $chunk) { echo $chunk; });
        }, 200, [
            'Content-Type'  => 'application/xml; charset=UTF-8',
            'Cache-Control' => 'public, max-age=900',
            'X-Accel-Buffering' => 'no',
        ]);
    }
}
