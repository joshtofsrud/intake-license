<?php

namespace App\Support;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

/**
 * MARKER-ANNOTATED-SHOT: draws numbered lime markers onto a screenshot.
 *
 * Email clients can't position anything on top of an image, so the markers are
 * baked into a copy of the picture. Copies are cached on the public disk under
 * a hash of the source URL and the marker positions, so the composer preview
 * and every send reuse the same file, and moving a marker makes a new one.
 *
 * Markers are [['x' => 0-100, 'y' => 0-100, ...], ...] in percent of the image.
 */
class AnnotatedShot
{
    public const DIR = 'platform-letters/annotated';

    public static function url(string $src, array $markers): ?string
    {
        $pts = [];
        foreach ($markers as $mk) {
            $x = (float) ($mk['x'] ?? -1); $y = (float) ($mk['y'] ?? -1);
            if ($x >= 0 && $x <= 100 && $y >= 0 && $y <= 100) { $pts[] = [$x, $y]; }
        }
        if (! $pts || ! function_exists('imagecreatefromstring')) {
            return null;
        }

        $key  = sha1($src . '|' . json_encode($pts));
        $path = self::DIR . '/' . $key . '.png';
        $disk = Storage::disk('public');
        if ($disk->exists($path)) {
            return url('/storage/' . $path);
        }

        $bytes = self::fetch($src);
        $im = $bytes ? @imagecreatefromstring($bytes) : false;
        if (! $im) {
            return null;
        }
        imagealphablending($im, true);
        $w = imagesx($im); $h = imagesy($im);
        $r = (int) max(14, round($w * 0.024));          // marker radius scales with the picture

        $lime = imagecolorallocate($im, 0xBE, 0xF2, 0x64);
        $halo = imagecolorallocatealpha($im, 0xBE, 0xF2, 0x64, 80);
        $ink  = imagecolorallocate($im, 0x10, 0x14, 0x0A);
        $font = self::font();

        foreach ($pts as $i => [$px, $py]) {
            $cx = (int) round($w * $px / 100); $cy = (int) round($h * $py / 100);
            imagefilledellipse($im, $cx, $cy, (int) ($r * 2 + $r * 0.9), (int) ($r * 2 + $r * 0.9), $halo);
            imagefilledellipse($im, $cx, $cy, $r * 2, $r * 2, $lime);
            $label = (string) ($i + 1);
            if ($font) {
                $size = $r * 0.95;
                $box = imagettfbbox($size, 0, $font, $label);
                $tw = abs($box[2] - $box[0]); $th = abs($box[7] - $box[1]);
                imagettftext($im, $size, 0, (int) round($cx - $tw / 2 - $box[0]), (int) round($cy + $th / 2), $ink, $font, $label);
            } else {
                imagestring($im, 5, $cx - 4 * strlen($label), $cy - 8, $label, $ink);
            }
        }

        ob_start();
        imagepng($im, null, 6);
        $png = ob_get_clean();
        imagedestroy($im);
        $disk->put($path, $png);

        return url('/storage/' . $path);
    }

    /** The source image: from our own public disk when it's ours, else over HTTP. */
    private static function fetch(string $src): ?string
    {
        $local = url('/storage/');
        if (str_starts_with($src, $local)) {
            $rel = ltrim(substr($src, strlen($local)), '/');
            return Storage::disk('public')->exists($rel) ? Storage::disk('public')->get($rel) : null;
        }
        if (! preg_match('#^https?://#i', $src)) {
            return null;
        }
        try {
            $res = Http::timeout(10)->get($src);
            return $res->successful() ? $res->body() : null;
        } catch (\Throwable $e) {
            return null;
        }
    }

    /** A bold TTF for the numbers; dompdf ships DejaVu with the app. */
    private static function font(): ?string
    {
        foreach ([resource_path('fonts/Inter-Bold.ttf'), base_path('vendor/dompdf/dompdf/lib/fonts/DejaVuSans-Bold.ttf')] as $f) {
            if (is_file($f) && function_exists('imagettftext')) { return $f; }
        }
        return null;
    }
}
