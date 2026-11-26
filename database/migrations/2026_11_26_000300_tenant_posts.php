<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// MARKER-POSTS: dated posts a shop writes (trail conditions, news, events),
// grouped into feeds, shown by the Posts section and at /posts. Additive only.
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('tenant_posts')) return;
        Schema::create('tenant_posts', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('feed', 60)->default('News');
            $table->string('title', 200);
            $table->string('slug', 220);
            $table->text('body')->nullable();
            $table->string('image_url', 500)->nullable();
            $table->timestamp('published_at')->nullable();
            $table->uuid('created_by')->nullable();
            $table->timestamps();
            $table->unique(['tenant_id', 'slug']);
            $table->index(['tenant_id', 'feed', 'published_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tenant_posts');
    }
};
