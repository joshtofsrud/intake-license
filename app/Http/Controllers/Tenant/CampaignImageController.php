<?php

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Models\Tenant\TenantCampaignImage;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class CampaignImageController extends Controller
{
    /**
     * List all images in the tenant's library.
     * Supports pagination + search for future Settings > Storage page.
     */
    public function index(Request $request, string $subdomain)
    {
        $tenant = tenant();

        $limit  = min((int) $request->input('limit', 50), 200);
        $offset = max((int) $request->input('offset', 0), 0);
        $search = trim((string) $request->input('search', ''));

        $query = TenantCampaignImage::where('tenant_id', $tenant->id)
            ->orderByDesc('created_at');

        if ($search !== '') {
            $query->where('filename', 'like', '%' . $search . '%');
        }

        $total  = (clone $query)->count();
        $images = $query->skip($offset)->take($limit)->get();

        return response()->json([
            'images' => $images->map(fn($img) => [
                'id'       => $img->id,
                'url'      => $img->url,
                'filename' => $img->filename,
                'bytes'    => $img->bytes,
                'width'    => $img->width,
                'height'   => $img->height,
                'created'  => $img->created_at?->toIso8601String(),
            ]),
            'total'  => $total,
            'limit'  => $limit,
            'offset' => $offset,
        ]);
    }

    /**
     * Upload a new image to the library.
     */
    public function upload(Request $request, string $subdomain)
    {
        $tenant = tenant();

        if (! $request->hasFile('image')) {
            return response()->json(['error' => 'No file provided.'], 422);
        }

        $file = $request->file('image');
        if (! $file->isValid()) {
            return response()->json(['error' => 'Upload failed. Try again.'], 422);
        }

        $perFileLimit = (int) config('intake.image_quotas.per_file_bytes');
        if ($file->getSize() > $perFileLimit) {
            $mb = round($perFileLimit / 1024 / 1024, 1);
            return response()->json([
                'error' => "File is too large. Max {$mb} MB per image.",
            ], 422);
        }

        $allowed = (array) config('intake.image_quotas.allowed_mime', []);
        if (! in_array($file->getMimeType(), $allowed, true)) {
            return response()->json([
                'error' => 'Unsupported file type. Use JPEG, PNG, GIF, or WebP.',
            ], 422);
        }

        // Quota check — based on tenant's plan tier
        $tierKey = $tenant->plan_tier ?? 'basic';
        $tierLimit = (int) config("intake.image_quotas.tiers.{$tierKey}", 0);
        $used = self::usedBytes($tenant->id);
        if ($tierLimit > 0 && ($used + $file->getSize()) > $tierLimit) {
            $limitMb = round($tierLimit / 1024 / 1024);
            $usedMb  = round($used / 1024 / 1024, 1);
            return response()->json([
                'error' => "Storage full. Using {$usedMb} MB of {$limitMb} MB. Delete unused images or upgrade your plan.",
            ], 422);
        }

        // Store file
        $path = $file->store("tenants/{$tenant->id}/campaign-images", 'public');
        $url  = Storage::disk('public')->url($path);

        // Read dimensions (best-effort; silently skip if imagegetimagesize fails)
        $width  = null;
        $height = null;
        try {
            $size = @getimagesize($file->getRealPath());
            if (is_array($size)) {
                $width  = $size[0];
                $height = $size[1];
            }
        } catch (\Throwable $e) {
            // ignore
        }

        $image = TenantCampaignImage::create([
            'tenant_id'   => $tenant->id,
            'filename'    => $file->getClientOriginalName(),
            'path'        => $path,
            'url'         => $url,
            'mime_type'   => $file->getMimeType(),
            'bytes'       => $file->getSize(),
            'width'       => $width,
            'height'      => $height,
            'uploaded_by' => auth('tenant')->id(),
            'created_at'  => now(),
        ]);

        return response()->json([
            'id'       => $image->id,
            'url'      => $image->url,
            'filename' => $image->filename,
            'bytes'    => $image->bytes,
            'width'    => $image->width,
            'height'   => $image->height,
        ]);
    }

    /**
     * Delete an image. Removes the file from storage + DB row.
     */
    public function destroy(Request $request, string $subdomain, string $id)
    {
        $tenant = tenant();

        $image = TenantCampaignImage::where('tenant_id', $tenant->id)
            ->where('id', $id)
            ->firstOrFail();

        try {
            Storage::disk('public')->delete($image->path);
        } catch (\Throwable $e) {
            // proceed with DB delete even if file delete fails
        }

        $image->delete();

        return response()->json(['ok' => true]);
    }

    /**
     * Return usage stats for the tenant.
     * Used by the picker header and future Settings > Storage page.
     */
    public function usage(Request $request, string $subdomain)
    {
        $tenant = tenant();

        $used  = self::usedBytes($tenant->id);
        $tierKey = $tenant->plan_tier ?? 'basic';
        $limit = (int) config("intake.image_quotas.tiers.{$tierKey}", 0);
        $count = TenantCampaignImage::where('tenant_id', $tenant->id)->count();

        return response()->json([
            'bytes_used'  => $used,
            'bytes_limit' => $limit,
            'percentage'  => $limit > 0 ? round($used / $limit * 100, 1) : 0,
            'file_count'  => $count,
            'per_file_bytes' => (int) config('intake.image_quotas.per_file_bytes'),
            'tier'        => $tierKey,
        ]);
    }

    /**
     * Total bytes used by this tenant's image library.
     */
    private static function usedBytes(string $tenantId): int
    {
        return (int) TenantCampaignImage::where('tenant_id', $tenantId)->sum('bytes');
    }
}
