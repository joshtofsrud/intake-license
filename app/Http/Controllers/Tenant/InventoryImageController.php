<?php

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Models\Tenant\TenantInventoryItem;
use App\Models\Tenant\TenantInventoryItemImage;
use App\Models\Tenant\TenantMedia;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;

/**
 * MARKER-ITEM-IMAGES — a shop's own photos for an inventory item.
 *
 * Uploads become TenantMedia rows in the 'items' folder, so the library, the
 * byte accounting and the plan quota all work without a second pipeline. The
 * same limits the campaign uploader enforces are enforced here, from the same
 * config, so a shop cannot discover two different maximums.
 */
class InventoryImageController extends Controller
{
    public function upload(Request $request, string $itemId): JsonResponse
    {
        $tenant = tenant();
        $item   = TenantInventoryItem::where('tenant_id', $tenant->id)->findOrFail($itemId);

        if (! $request->hasFile('image')) {
            // MARKER-UPLOAD-LIMITS applies here too: an empty $_FILES with a
            // large body means PHP discarded the file before Laravel ran.
            $posted = (int) ($request->server('CONTENT_LENGTH') ?: 0);
            $iniMax = min(self::iniBytes(ini_get('upload_max_filesize')),
                          self::iniBytes(ini_get('post_max_size')));

            if ($posted > 0 && $iniMax > 0 && $posted >= $iniMax) {
                return response()->json([
                    'error' => 'This server is refusing anything over '
                        . round($iniMax / 1024 / 1024, 1) . ' MB. Use a smaller image.',
                ], 422);
            }

            return response()->json(['error' => 'No file provided.'], 422);
        }

        $file = $request->file('image');

        if (! $file->isValid()) {
            return response()->json(['error' => 'Upload failed. Try again.'], 422);
        }

        $perFile = (int) config('intake.image_quotas.per_file_bytes');
        if ($perFile > 0 && $file->getSize() > $perFile) {
            return response()->json([
                'error' => 'That image is ' . round($file->getSize() / 1024 / 1024, 1)
                    . ' MB. The limit is ' . round($perFile / 1024 / 1024, 1) . ' MB.',
            ], 422);
        }

        $allowed = (array) config('intake.image_quotas.allowed_mime', []);
        if ($allowed && ! in_array($file->getMimeType(), $allowed, true)) {
            return response()->json([
                'error' => 'Unsupported file type. Use JPEG, PNG, GIF or WebP.',
            ], 422);
        }

        $tierKey   = $tenant->plan_tier ?? 'starter';
        $tierLimit = (int) config("intake.image_quotas.tiers.{$tierKey}", 0);
        $used      = (int) TenantMedia::where('tenant_id', $tenant->id)
            ->whereNull('archived_at')->sum('bytes');

        if ($tierLimit > 0 && ($used + $file->getSize()) > $tierLimit) {
            return response()->json([
                'error' => 'Storage full — using ' . round($used / 1024 / 1024, 1)
                    . ' MB of ' . round($tierLimit / 1024 / 1024) . ' MB.',
            ], 422);
        }

        $path = $file->store("tenants/{$tenant->id}/items", 'public');

        $width = $height = null;
        try {
            $size = @getimagesize($file->getRealPath());
            if (is_array($size)) {
                [$width, $height] = $size;
            }
        } catch (\Throwable $e) {
            // dimensions are a nicety, never a reason to fail an upload
        }

        $media = TenantMedia::create([
            'tenant_id'     => $tenant->id,
            'filename'      => basename($path),
            'original_name' => $file->getClientOriginalName(),
            'path'          => $path,
            'url'           => Storage::disk('public')->url($path),
            'folder'        => 'items',
            'mime_type'     => $file->getMimeType(),
            'bytes'         => $file->getSize(),
            'width'         => $width,
            'height'        => $height,
            'uploaded_by'   => Auth::guard('tenant')->id(),
        ]);

        $next = (int) TenantInventoryItemImage::where('inventory_item_id', $item->id)
            ->max('sort_order');

        $join = TenantInventoryItemImage::create([
            'tenant_id'         => $tenant->id,
            'inventory_item_id' => $item->id,
            'media_id'          => $media->id,
            'sort_order'        => $next + 1,
        ]);

        return response()->json([
            'join_id' => $join->id,
            'url'     => $media->url,
        ]);
    }

    /** Detach a photo from this item. The media row itself stays in the library. */
    public function detach(Request $request, string $itemId, string $joinId): JsonResponse
    {
        $tenant = tenant();

        TenantInventoryItemImage::where('tenant_id', $tenant->id)
            ->where('inventory_item_id', $itemId)
            ->whereKey($joinId)
            ->delete();

        return response()->json(['ok' => true]);
    }

    /** Reorder the shop's own photos. Ids not sent are left where they are. */
    public function reorder(Request $request, string $itemId): JsonResponse
    {
        $tenant = tenant();
        $ids    = (array) $request->input('ids', []);

        foreach (array_values($ids) as $i => $joinId) {
            TenantInventoryItemImage::where('tenant_id', $tenant->id)
                ->where('inventory_item_id', $itemId)
                ->whereKey($joinId)
                ->update(['sort_order' => $i + 1]);
        }

        return response()->json(['ok' => true]);
    }

    /**
     * Switch a distributor image off, or back on. Stored as a URL list on the
     * item; nothing in the catalog is touched, and a replaced image reappears.
     */
    public function toggleCatalogImage(Request $request, string $itemId): JsonResponse
    {
        $tenant = tenant();
        $item   = TenantInventoryItem::where('tenant_id', $tenant->id)->findOrFail($itemId);

        $url = (string) $request->input('url', '');
        if ($url === '') {
            return response()->json(['error' => 'No image given.'], 422);
        }

        $hidden = (array) ($item->hidden_catalog_images ?? []);

        if (in_array($url, $hidden, true)) {
            $hidden = array_values(array_diff($hidden, [$url]));
            $nowHidden = false;
        } else {
            $hidden[] = $url;
            $nowHidden = true;
        }

        $item->forceFill(['hidden_catalog_images' => $hidden])->save();

        return response()->json(['hidden' => $nowHidden]);
    }

    private static function iniBytes(?string $value): int
    {
        $value = trim((string) $value);
        if ($value === '') {
            return 0;
        }

        $unit   = strtolower(substr($value, -1));
        $number = (int) $value;

        return match ($unit) {
            'g'     => $number * 1024 * 1024 * 1024,
            'm'     => $number * 1024 * 1024,
            'k'     => $number * 1024,
            default => ctype_digit($value) ? (int) $value : 0,
        };
    }
}
