<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\License;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * GET /api/v1/download/{license_key}
 *
 * Serves the plugin ZIP to premium licensees who have a valid signed
 * token from the update endpoint. Token expires after the current hour
 * changes (roughly 30-60 min window).
 */
class DownloadController extends Controller
{
    public function __invoke(Request $request, string $licenseKey): BinaryFileResponse
    {
        $token    = $request->query('token');
        $expected = hash_hmac(
            'sha256',
            $licenseKey . '|' . now()->format('YmdH'),
            config('app.key')
        );

        if (! hash_equals($expected, (string) $token)) {
            abort(403, 'Invalid or expired download token.');
        }

        $license = License::where('license_key', $licenseKey)->first();

        if (! $license || ! $license->isValid()) {
            abort(403, 'License is not active.');
        }

        $zipPath = config('intake.plugin_zip_path');

        if (! $zipPath || ! file_exists($zipPath)) {
            abort(503, 'Plugin package is not available. Please contact support.');
        }

        return response()->download($zipPath, 'intake.zip', [
            'Content-Type'        => 'application/zip',
            'Content-Disposition' => 'attachment; filename="intake.zip"',
        ]);
    }
}
