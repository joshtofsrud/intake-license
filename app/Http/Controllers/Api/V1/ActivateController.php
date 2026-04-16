<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Activation;
use App\Models\License;
use App\Models\LicenseEvent;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * POST /api/v1/activate
 *
 * Called when a premium user enters their license key in the plugin.
 * Validates the key, checks site limit, creates the activation row,
 * and returns the feature flags the plugin should respect.
 */
class ActivateController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $request->validate([
            'license_key'    => ['required', 'string', 'max:64'],
            'site_url'       => ['required', 'url', 'max:500'],
            'site_name'      => ['nullable', 'string', 'max:255'],
            'wp_version'     => ['nullable', 'string', 'max:20'],
            'plugin_version' => ['nullable', 'string', 'max:20'],
        ]);

        $siteUrl = Activation::normaliseUrl($request->input('site_url'));
        $key     = trim($request->input('license_key'));

        // Find the license
        $license = License::with('activations')
            ->where('license_key', $key)
            ->first();

        if (! $license) {
            return $this->error('license_not_found', 'License key not found.', 404);
        }

        if (! $license->isValid()) {
            return $this->error(
                'license_' . $license->status,
                'This license is ' . $license->status . ' and cannot be used.',
                403
            );
        }

        // Check if this site is already activated on this license
        $existing = $license->activations()->where('site_url', $siteUrl)->first();

        if (! $existing) {
            // New site — check site limit
            if (! $license->canActivate()) {
                return $this->error(
                    'site_limit_reached',
                    'This license has reached its maximum number of activated sites ('
                        . $license->site_limit . ').',
                    403
                );
            }

            Activation::create([
                'license_id'     => $license->id,
                'site_url'       => $siteUrl,
                'site_name'      => $request->input('site_name'),
                'wp_version'     => $request->input('wp_version'),
                'plugin_version' => $request->input('plugin_version'),
                'type'           => 'premium',
                'ip_address'     => $request->ip(),
                'last_seen_at'   => now(),
                'activated_at'   => now(),
            ]);

            LicenseEvent::log($license, 'activated', [
                'site_url'       => $siteUrl,
                'ip_address'     => $request->ip(),
                'plugin_version' => $request->input('plugin_version'),
                'wp_version'     => $request->input('wp_version'),
            ]);
        } else {
            // Already activated — just refresh the last_seen timestamp
            $existing->update([
                'last_seen_at'   => now(),
                'wp_version'     => $request->input('wp_version') ?? $existing->wp_version,
                'plugin_version' => $request->input('plugin_version') ?? $existing->plugin_version,
            ]);
        }

        return response()->json([
            'success'       => true,
            'license_key'   => $license->license_key,
            'tier'          => $license->tier,
            'status'        => $license->status,
            'site_limit'    => $license->site_limit,
            'sites_used'    => $license->activeActivationCount(),
            'feature_flags' => $this->resolveFeatureFlags($license),
            'expires_at'    => $license->expires_at?->toIso8601String(),
        ]);
    }

    private function resolveFeatureFlags(License $license): array
    {
        return [
            'updates'     => $license->hasFeature('updates'),
            'saas_access' => $license->saas_access || $license->hasFeature('saas_access'),
            'white_label' => $license->hasFeature('white_label'),
        ];
    }

    private function error(string $code, string $message, int $status): JsonResponse
    {
        return response()->json([
            'success' => false,
            'code'    => $code,
            'message' => $message,
        ], $status);
    }
}
