<?php

namespace App\Http\Middleware;

use App\Support\SvgSanitizer;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Symfony\Component\HttpFoundation\Response;

/**
 * MARKER-SEC-UPLOADS: every uploaded SVG is cleaned before any controller
 * stores it (media library, logos, item images, campaign art, onboarding),
 * so no upload path can be missed. An SVG that can't be read is refused.
 */
class SanitizeUploads
{
    public function handle(Request $request, Closure $next): Response
    {
        $files = $request->allFiles();
        if ($files) {
            $refused = false;
            array_walk_recursive($files, function ($f) use (&$refused) {
                if (! $f instanceof UploadedFile || ! $f->isValid()) return;
                $mime = (string) $f->getMimeType();
                $isSvg = str_contains($mime, 'svg') || strtolower($f->getClientOriginalExtension()) === 'svg'
                    || ($mime === 'text/xml' || $mime === 'application/xml' || $mime === 'text/plain') && stripos((string) @file_get_contents($f->getRealPath(), false, null, 0, 2048), '<svg') !== false;
                if (! $isSvg) return;
                $clean = SvgSanitizer::clean((string) @file_get_contents($f->getRealPath()));
                if ($clean === null) { $refused = true; return; }
                file_put_contents($f->getRealPath(), $clean);
                clearstatcache(true, $f->getRealPath());
            });
            if ($refused) {
                $msg = 'That SVG file could not be read. Try exporting it again, or use a PNG.';
                // global middleware: no session yet, so no flash; a plain 422 page
                if ($request->expectsJson() || $request->ajax()) {
                    return response()->json(['ok' => false, 'message' => $msg, 'error' => $msg], 422);
                }
                abort(422, $msg);
            }
        }
        return $next($request);
    }
}
