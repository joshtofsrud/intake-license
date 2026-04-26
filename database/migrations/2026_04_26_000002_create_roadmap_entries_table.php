<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Public roadmap — what's coming, grouped by status.
 * Platform-level. Edited via master admin Filament UI.
 * Rendered on intake.works/roadmap and inside the tenant admin.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('roadmap_entries', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->string('status', 32);              // 'shipped' | 'in_progress' | 'next_up' | 'considering'
            $t->string('title', 191);
            $t->string('category', 32)->nullable();
            $t->text('body');
            $t->string('rough_timeframe', 64)->nullable(); // 'this week', 'Q2', 'when X' — public-friendly
            $t->integer('display_order')->default(0);      // manual sort within a status
            $t->boolean('is_published')->default(false);
            $t->timestamps();

            $t->index(['is_published', 'status', 'display_order']);
        });
    }

    public function down(): void { Schema::dropIfExists('roadmap_entries'); }
};
