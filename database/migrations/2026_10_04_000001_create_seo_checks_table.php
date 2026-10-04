<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// MARKER-SEO-SIGNALS — results of the nightly seo:check, one row per site per run.
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('seo_checks')) return;

        Schema::create('seo_checks', function (Blueprint $t) {
            $t->id();
            $t->string('host');
            $t->uuid('tenant_id')->nullable()->index();
            $t->string('expect', 16);          // 'indexed' or 'hidden'
            $t->string('status', 8);           // 'ok' or 'fail'
            $t->json('problems')->nullable();
            $t->unsignedInteger('urls_checked')->default(0);
            $t->timestamp('checked_at')->index();
            $t->timestamps();
            $t->index(['host', 'checked_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('seo_checks');
    }
};
