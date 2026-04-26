<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Public changelog — what shipped, by date.
 * Platform-level (not tenant-scoped). Edited via master admin Filament UI.
 * Rendered on intake.works/changelog and inside the tenant admin.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('changelog_entries', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->date('shipped_on');                // when it shipped
            $t->string('title', 191);              // short title shown as the entry header
            $t->string('category', 32)->nullable();// 'Calendar' | 'Booking' | 'Stripe' | 'Bugfix' | etc.
            $t->text('body');                      // markdown-ish body, plain text for now
            $t->boolean('is_published')->default(false);
            $t->boolean('is_highlighted')->default(false); // pin to top, lime accent
            $t->timestamps();

            $t->index(['is_published', 'shipped_on']);
        });
    }

    public function down(): void { Schema::dropIfExists('changelog_entries'); }
};
