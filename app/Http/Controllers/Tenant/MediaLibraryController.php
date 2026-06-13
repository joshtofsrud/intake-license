<?php
// MARKER-PATCH-258

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Models\Tenant\TenantMedia;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class MediaLibraryController extends Controller
{
    /** Folders surfaced as filters (matches UploadController::ALLOWED types). */
    private const FOLDERS = ['general', 'hero', 'gallery', 'logo', 'logo_light', 'favicon'];

    public function index(Request $request)
    {
        $tenant = tenant();
        $folder = $request->query('folder');
        $q      = trim((string) $request->query('q', ''));

        $media = TenantMedia::where('tenant_id', $tenant->id)
            ->active()
            ->folder(in_array($folder, self::FOLDERS, true) ? $folder : null)
            ->when($q !== '', function ($query) use ($q) {
                $query->where(function ($w) use ($q) {
                    $w->where('original_name', 'like', "%{$q}%")
                      ->orWhere('filename', 'like', "%{$q}%");
                });
            })
            ->orderByDesc('created_at')
            ->paginate(48)
            ->withQueryString();

        return view('tenant.media.index', [
            'media'   => $media,
            'folders' => self::FOLDERS,
            'folder'  => $folder,
            'q'       => $q,
        ]);
    }

    /** JSON feed for the page-builder picker (patch 259). */
    public function feed(Request $request)
    {
        $tenant = tenant();
        $rows = TenantMedia::where('tenant_id', $tenant->id)
            ->active()
            ->where('mime_type', 'like', 'image/%')
            ->orderByDesc('created_at')
            ->limit(200)
            ->get(['id', 'url', 'original_name', 'filename', 'width', 'height', 'folder']);

        return response()->json(['ok' => true, 'media' => $rows]);
    }

    /** Soft-delete: hide from library, keep the file so live pages don't 404. */
    public function archive(Request $request, string $id)
    {
        $media = TenantMedia::where('tenant_id', tenant()->id)->findOrFail($id);
        $media->update(['archived_at' => now()]);

        return response()->json(['ok' => true]);
    }
}
