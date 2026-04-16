<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Activation;
use App\Models\License;
use App\Models\LicenseEvent;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

/**
 * GET /api/v1/check
 *
 * Called by the plugin on every WP admin page load (with heavy caching
 * on the plugin side — typically once per hour). Returns the current
 * validity and feature flags so the plugin knows what to enable/disable.
 *
 * Response is cached server-side for 5 minutes per key+site combo to
 * protect against hammering.
 */
class CheckController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $request->validate([
            'license_key'    => ['required', 'string', 'max:64'],
            'site_url'       => ['required', 'url', 'max:500'],
            'plugin_version' => ['nullable', 'string', 'max:20'],
            'wp_version'     => ['nullable', 'string', 'max:20'],
        ]);

        $siteUrl = Activation::normaliseUrl($request->input('site_url'));
        $key     = trim($request->input('license_key'));

        $cacheKey = 'license_check_' . md5($key . '|' . $siteUrl);

        $result = Cache::remember($cacheKey, now()->addMinutes(5), function () use (
            $key, $siteUrl, $request
        ) {
            $license = License::where('license_key', $key)->first();

            if (! $license) {
                return [
                    'valid'   => false,
                    'code'    => 'license_not_found',
                    'message' => 'License key not found.',
                ];
            }

            // Refresh last_seen on the activation row (fire-and-forget)
            $license->activations()
                ->where('site_url', $siteUrl)
                ->update([
                    'last_seen_at'   => now(),
                    'plugin_version' => $request->input('plugin_version'),
                    'wp_version'     => $request->input('wp_version'),
                ]);

            if (! $license->isValid()) {
                return [
                    'valid'   => false,
                    'code'    => 'license_' . $license->status,
                    'message' => 'License is ' . $license->status . '.',
                ];
            }

            return [
                'valid'         => true,
                'tier'          => $license->tier,
                'status'        => $license->status,
                'site_limit'    => $license->site_limit,
                'sites_used'    => $license->activeActivationCount(),
                'feature_flags' => [
                    'updates'     => $license->hasFeature('updates'),
                    'saas_access' => $license->saas_access || $license->hasFeature('saas_access'),
                    'white_label' => $license->hasFeature('white_label'),
                ],
                'expires_at'    => $license->expires_at?->toIso8601String(),
            ];
        });

        return response()->json(array_merge(['success' => true], $result));
    }
}
