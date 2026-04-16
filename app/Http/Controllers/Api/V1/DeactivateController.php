<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Activation;
use App\Models\License;
use App\Models\LicenseEvent;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * POST /api/v1/deactivate
 *
 * Called when a user removes the plugin or explicitly deactivates
 * the license from the WP admin. Frees up the activation slot.
 */
class DeactivateController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $request->validate([
            'license_key' => ['required', 'string', 'max:64'],
            'site_url'    => ['required', 'url', 'max:500'],
        ]);

        $siteUrl = Activation::normaliseUrl($request->input('site_url'));
        $key     = trim($request->input('license_key'));

        $license = License::where('license_key', $key)->first();

        if (! $license) {
            // Return success anyway — idempotent deactivation
            return response()->json(['success' => true, 'message' => 'ok']);
        }

        $deleted = $license->activations()
            ->where('site_url', $siteUrl)
            ->delete();

        if ($deleted) {
            LicenseEvent::log($license, 'deactivated', [
                'site_url'   => $siteUrl,
                'ip_address' => $request->ip(),
            ]);
        }

        return response()->json([
            'success'    => true,
            'sites_used' => $license->activeActivationCount(),
        ]);
    }
}
