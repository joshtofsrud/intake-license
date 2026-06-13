<?php

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\Tenant\TenantMedia;
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
            'type' => ['nullable', 'string', 'in:logo,logo_light,favicon,hero,gallery,general'],
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

        // MARKER-PATCH-257 — record the upload so it's browsable/reusable.
        // Dimensions for raster images only (svg/ico have no meaningful px).
        $width = $height = null;
        if (!in_array($ext, ['svg', 'ico'], true)) {
            try {
                $dims = @getimagesize($file->getRealPath());
                if ($dims) { $width = $dims[0] ?: null; $height = $dims[1] ?: null; }
            } catch (\Throwable $e) { /* dims are a nicety, never block the upload */ }
        }
        $media = TenantMedia::create([
            'tenant_id'     => $tenant->id,
            'filename'      => $filename,
            'original_name' => $file->getClientOriginalName(),
            'path'          => $stored,
            'url'           => $url,
            'folder'        => $type,
            'mime_type'     => $file->getClientMimeType(),
            'bytes'         => $file->getSize() ?: 0,
            'width'         => $width,
            'height'        => $height,
            'uploaded_by'   => auth('tenant')->id(),
        ]);

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
            'media_id' => $media->id, // MARKER-PATCH-257 — picker reference
        ]);
    }
}
