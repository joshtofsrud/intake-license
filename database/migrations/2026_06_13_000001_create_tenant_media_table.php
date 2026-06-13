<?php
// MARKER-PATCH-257

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * tenant_media — the browsable record behind every tenant image/file upload.
 *
 * Uploads were fire-and-forget; this is what makes a library possible. One
 * row per stored file. Archive instead of hard-delete so a page still
 * referencing a "removed" image doesn't 404 from under the tenant — the
 * library hides archived rows, the file stays on disk until a real purge.
 *
 * Dimensions/size are denormalized so the grid renders without touching
 * the filesystem.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tenant_media', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();

            $table->string('filename', 255);            // stored name (slug-random.ext)
            $table->string('original_name', 255)->nullable();
            $table->string('path', 512);                // disk path, e.g. tenants/{id}/hero/x.webp
            $table->string('url', 768);                 // public asset URL
            $table->string('folder', 60)->default('general'); // logo/hero/gallery/general...
            $table->string('mime_type', 120)->nullable();
            $table->unsignedBigInteger('bytes')->default(0);
            $table->unsignedInteger('width')->nullable();
            $table->unsignedInteger('height')->nullable();

            $table->uuid('uploaded_by')->nullable();
            $table->timestamp('archived_at')->nullable();
            $table->timestamps();

            // Library grid: a tenant's active media, newest first.
            $table->index(['tenant_id', 'archived_at', 'created_at'], 'tm_library');
            // De-dupe / backfill existence checks by stored path.
            $table->index(['tenant_id', 'path'], 'tm_path');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tenant_media');
    }
};
