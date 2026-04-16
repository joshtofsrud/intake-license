<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Activation;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * POST /api/v1/ping
 *
 * Called by free installs on activation and periodically (weekly).
 * No license key required. We upsert the activation row and return
 * a simple acknowledgement.
 */
class PingController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $request->validate([
            'site_url'       => ['required', 'url', 'max:500'],
            'site_name'      => ['nullable', 'string', 'max:255'],
            'wp_version'     => ['nullable', 'string', 'max:20'],
            'plugin_version' => ['nullable', 'string', 'max:20'],
        ]);

        $siteUrl = Activation::normaliseUrl($request->input('site_url'));

        Activation::updateOrCreate(
            [
                'license_id' => null,
                'site_url'   => $siteUrl,
            ],
            [
                'site_name'      => $request->input('site_name'),
                'wp_version'     => $request->input('wp_version'),
                'plugin_version' => $request->input('plugin_version'),
                'type'           => 'free',
                'ip_address'     => $request->ip(),
                'last_seen_at'   => now(),
            ]
        );

        return response()->json([
            'success' => true,
            'message' => 'ok',
        ]);
    }
}
