<?php

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class UploadController extends Controller
{
    private const MAX_SIZE_KB = 5120; // 5MB
    private const ALLOWED = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'svg', 'ico'];

    public function store(Request $request)
    {
        $request->validate([
            'file' => ['required', 'file', 'max:' . self::MAX_SIZE_KB, 'mimes:' . implode(',', self::ALLOWED)],
            'type' => ['nullable', 'string', 'in:logo,favicon,hero,gallery,general'],
        ]);

        $tenant = tenant();
        $file   = $request->file('file');
        $type   = $request->input('type', 'general');

        // Build path: tenants/{tenant_id}/{type}/{filename}
        $ext      = $file->getClientOriginalExtension() ?: 'jpg';
        $filename = Str::slug(pathinfo($file->getClientOriginalName(), PATHINFO_FILENAME));
        $filename = $filename . '-' . Str::random(6) . '.' . $ext;
        $path     = "tenants/{$tenant->id}/{$type}";

        $stored = $file->storeAs($path, $filename, 'public');

        if (!$stored) {
            return response()->json(['ok' => false, 'message' => 'Upload failed.'], 500);
        }

        $url = asset('storage/' . $stored);

        // If this is a logo or favicon, update the tenant record directly
        if ($type === 'logo') {
            $tenant->update(['logo_url' => $url]);
        } elseif ($type === 'favicon') {
            $tenant->update(['favicon_url' => $url]);
        }

        return response()->json([
            'ok'       => true,
            'url'      => $url,
            'filename' => $filename,
            'path'     => $stored,
        ]);
    }
}
