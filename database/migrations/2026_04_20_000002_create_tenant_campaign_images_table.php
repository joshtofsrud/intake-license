<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tenant_campaign_images', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained()->cascadeOnDelete();

            $table->string('filename');           // display name (e.g. "spring-sale.jpg")
            $table->string('path');                // storage path relative to disk root
            $table->string('url');                 // public URL
            $table->string('mime_type', 100);
            $table->unsignedInteger('bytes');      // file size in bytes
            $table->unsignedInteger('width')->nullable();
            $table->unsignedInteger('height')->nullable();

            $table->foreignUuid('uploaded_by')->nullable()->constrained('tenant_users')->nullOnDelete();
            $table->timestamp('created_at')->useCurrent();

            $table->index('tenant_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tenant_campaign_images');
    }
};
