<?php
// MARKER-PATCH-257

namespace App\Console\Commands;

use App\Models\Tenant;
use App\Models\Tenant\TenantMedia;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

/**
 * media:backfill — registers existing on-disk uploads (pre-257, when
 * uploads were fire-and-forget) into tenant_media so the library isn't
 * empty on launch. Idempotent: skips any path already tracked. Safe to
 * re-run.
 */
class BackfillTenantMedia extends Command
{
    protected $signature = 'media:backfill {--tenant= : limit to one tenant id}';
    protected $description = 'Register existing on-disk tenant uploads into tenant_media.';

    public function handle(): int
    {
        $disk = Storage::disk('public');
        $tenants = $this->option('tenant')
            ? Tenant::where('id', $this->option('tenant'))->get()
            : Tenant::all();

        $imageExt = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'avif', 'svg', 'ico']; // MARKER-LOGOBAR-POLISH
        $created = 0;

        foreach ($tenants as $tenant) {
            $base = "tenants/{$tenant->id}";
            if (!$disk->exists($base)) {
                continue;
            }
            foreach ($disk->allFiles($base) as $path) {
                $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
                if (!in_array($ext, $imageExt, true)) {
                    continue;
                }
                $exists = TenantMedia::where('tenant_id', $tenant->id)
                    ->where('path', $path)->exists();
                if ($exists) {
                    continue;
                }

                // folder = the segment right after tenants/{id}/
                $parts  = explode('/', $path);
                $folder = $parts[2] ?? 'general';

                $width = $height = null;
                if (!in_array($ext, ['svg', 'ico'], true)) {
                    try {
                        $dims = @getimagesize($disk->path($path));
                        if ($dims) { $width = $dims[0] ?: null; $height = $dims[1] ?: null; }
                    } catch (\Throwable $e) { /* skip dims */ }
                }

                TenantMedia::create([
                    'tenant_id'     => $tenant->id,
                    'filename'      => basename($path),
                    'original_name' => basename($path),
                    'path'          => $path,
                    'url'           => asset('storage/' . $path),
                    'folder'        => $folder,
                    'mime_type'     => $ext === 'svg' ? 'image/svg+xml' : ($disk->mimeType($path) ?: null),
                    'bytes'         => $disk->size($path) ?: 0,
                    'width'         => $width,
                    'height'        => $height,
                    'created_at'    => $disk->lastModified($path) ? date('Y-m-d H:i:s', $disk->lastModified($path)) : now(),
                ]);
                $created++;
            }
        }

        $this->info("Backfill complete — {$created} media rows created.");
        return self::SUCCESS;
    }
}
