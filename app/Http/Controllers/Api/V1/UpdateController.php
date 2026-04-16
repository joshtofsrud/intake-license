<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Activation;
use App\Models\License;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

/**
 * GET /api/v1/update
 *
 * WordPress calls this URL (set via Update URI in the plugin header)
 * to check whether an update is available. The request comes from WP
 * core — not the plugin itself — so we can't require a license key here.
 *
 * For premium sites that have activated, we return a download URL that
 * includes a signed token so only valid licensees can pull the ZIP.
 *
 * For free installs we return the same version info but with a public
 * download URL (or no URL if you want to gate it).
 *
 * WordPress expects a specific JSON shape — see:
 * https://developer.wordpress.org/reference/hooks/update_plugins_transient/
 */
class UpdateController extends Controller
{
    // Bump this whenever you ship a new plugin release
    const CURRENT_VERSION = '1.0.0-alpha.16';
    const REQUIRES_WP     = '6.0';
    const REQUIRES_PHP    = '7.4';
    const TESTED_UP_TO    = '6.7';

    public function __invoke(Request $request): JsonResponse
    {
        $licenseKey  = $request->query('license_key');
        $siteUrl     = $request->query('site_url');
        $pluginSlug  = $request->query('slug', 'intake');

        // WordPress only cares about the plugin it's checking
        if ($pluginSlug !== 'intake') {
            return response()->json(['error' => 'Unknown plugin slug.'], 404);
        }

        $downloadUrl = $this->resolveDownloadUrl($licenseKey, $siteUrl);

        // Shape WordPress expects
        $payload = [
            'id'            => 'intake/intake.php',
            'slug'          => 'intake',
            'plugin'        => 'intake/intake.php',
            'new_version'   => self::CURRENT_VERSION,
            'url'           => 'https://intake.works',
            'package'       => $downloadUrl,
            'requires'      => self::REQUIRES_WP,
            'requires_php'  => self::REQUIRES_PHP,
            'tested'        => self::TESTED_UP_TO,
            'icons'         => [],
            'banners'       => [],
            'sections'      => [
                'description' => 'Service bookings and work order management for shops.',
                'changelog'   => $this->changelog(),
            ],
        ];

        return response()->json($payload);
    }

    /**
     * Return a download URL appropriate for this request.
     *
     * Premium licensees get a short-lived signed URL.
     * Free installs get null — no auto-update for free tier.
     */
    private function resolveDownloadUrl(?string $licenseKey, ?string $siteUrl): ?string
    {
        if (! $licenseKey || ! $siteUrl) {
            return null;
        }

        $license = Cache::remember(
            'update_license_' . md5($licenseKey),
            now()->addMinutes(10),
            fn () => License::where('license_key', $licenseKey)->first()
        );

        if (! $license || ! $license->isValid()) {
            return null;
        }

        // Generate a signed token valid for 30 minutes
        $token = hash_hmac(
            'sha256',
            $licenseKey . '|' . now()->format('YmdH'),
            config('app.key')
        );

        return url('/api/v1/download/' . urlencode($licenseKey) . '?token=' . $token);
    }

    private function changelog(): string
    {
        return <<<HTML
        <h4>1.0.0-alpha.16</h4>
        <ul>
            <li>Payments tab: Stripe + PayPal provider cards</li>
            <li>Booking form v2 redesign with CSS token system</li>
            <li>Booking form customizer live preview</li>
            <li>Capacity editor WYSIWYG</li>
        </ul>
        HTML;
    }
}
