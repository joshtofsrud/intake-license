<?php
// MARKER-BG-IMPORT: big shop lists arrive in 1 MB pieces (so no server body-size limit
// gets in the way), then a .gz or .zip is unpacked to a plain CSV.

namespace App\Http\Controllers;

use App\Support\AdminAccess;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class ShopListUploadController extends Controller
{
    public const MAX_BYTES = 400 * 1024 * 1024; // unpacked

    public function chunk(Request $request)
    {
        $user = Auth::guard('web')->user();
        abort_unless($user && AdminAccess::allows($user, 'crm'), 403);
        $v = $request->validate([
            'upload_id' => ['required', 'regex:/^[a-f0-9]{24}$/'],
            'index'     => ['required', 'integer', 'min:0'],
            'total'     => ['required', 'integer', 'min:1', 'max:1000'],
            'name'      => ['required', 'string', 'max:191'],
            'chunk'     => ['required', 'file', 'max:4096'],
        ]);
        $dir = storage_path('app/shop-imports');
        if (! is_dir($dir)) @mkdir($dir, 0775, true);
        $part = "$dir/up-{$v['upload_id']}.part";
        $next = "$dir/up-{$v['upload_id']}.next";
        $want = $v['index'] === 0 ? 0 : (int) @file_get_contents($next);
        if ((int) $v['index'] !== $want) return response()->json(['ok' => false, 'error' => 'Pieces arrived out of order. Pick the file again.'], 409);
        if ($v['index'] === 0) @unlink($part);
        $in = fopen($request->file('chunk')->getRealPath(), 'rb');
        $out = fopen($part, 'ab');
        stream_copy_to_stream($in, $out);
        fclose($in); fclose($out);
        file_put_contents($next, (string) ($v['index'] + 1));
        if (filesize($part) > self::MAX_BYTES) { @unlink($part); @unlink($next); return response()->json(['ok' => false, 'error' => 'That file is too big (400 MB at most).'], 413); }
        if ($v['index'] + 1 < $v['total']) return response()->json(['ok' => true]);

        @unlink($next);
        $csv = "$dir/up-{$v['upload_id']}.csv";
        $name = strtolower($v['name']);
        try {
            if (str_ends_with($name, '.gz')) {
                $gz = gzopen($part, 'rb'); $o = fopen($csv, 'wb'); $n = 0;
                while (! gzeof($gz)) { $buf = gzread($gz, 1 << 20); if ($buf === false) break; $n += strlen($buf); if ($n > self::MAX_BYTES) throw new \RuntimeException('That file is too big once unpacked (400 MB at most).'); fwrite($o, $buf); }
                gzclose($gz); fclose($o);
            } elseif (str_ends_with($name, '.zip')) {
                $zip = new \ZipArchive();
                if ($zip->open($part) !== true) throw new \RuntimeException('That zip could not be opened.');
                $entry = null;
                for ($i = 0; $i < $zip->numFiles; $i++) { $n = $zip->getNameIndex($i); if (str_ends_with(strtolower($n), '.csv') && ! str_starts_with($n, '__MACOSX')) { $entry = $n; break; } }
                if ($entry === null) throw new \RuntimeException('There is no .csv file inside that zip.');
                if (($zip->statName($entry)['size'] ?? 0) > self::MAX_BYTES) throw new \RuntimeException('That file is too big once unpacked (400 MB at most).');
                $s = $zip->getStream($entry); $o = fopen($csv, 'wb'); stream_copy_to_stream($s, $o); fclose($s); fclose($o); $zip->close();
            } else {
                rename($part, $csv);
            }
        } catch (\Throwable $e) {
            @unlink($csv); @unlink($part);
            return response()->json(['ok' => false, 'error' => $e->getMessage()], 422);
        }
        @unlink($part);
        return response()->json(['ok' => true, 'done' => true, 'size' => filesize($csv)]);
    }
}
