<?php

namespace App\Http\Controllers\Platform;

use App\Http\Controllers\Controller;
use App\Jobs\SiteGoogleSyncJob;
use App\Services\SiteHealth\GoogleSiteData;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Throwable;

/**
 * MARKER-SITE-HEALTH: the single return point for every shop's Google sign-in (app host).
 * The nonce in `state` is the only thing trusted from the browser; it maps to the
 * shop, staff member and return page saved when Connect was pressed.
 */
class GoogleOAuthCallbackController extends Controller
{
    public function __invoke(Request $request, GoogleSiteData $google)
    {
        $pending = $google->claim((string) $request->query('state', ''));
        if (! $pending) {
            return response()->view('platform.google-connect-expired', [], 410);
        }
        $back = fn (string $key, string $msg) => redirect()->away($pending['return'] . '&' . http_build_query([$key => $msg]));

        if ($request->query('error')) {
            return $back('google_error', 'The sign-in was not approved.');
        }
        try {
            $conn = $google->complete($pending, (string) $request->query('code', ''));
            SiteGoogleSyncJob::dispatch($conn->tenant_id);
        } catch (Throwable $e) {
            if (! $e instanceof \RuntimeException) {
                \App\Support\JobFailureReporter::report(self::class, 'Google sign-in for Site health failed', $e, [], $pending['tenant_id']);
            }

            return $back('google_error', $e instanceof \RuntimeException ? Str::limit($e->getMessage(), 200) : 'The sign-in could not be finished. Try Connect again.');
        }

        return $back('google', 'connected');
    }
}
