<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * theme_settings — platform-wide theme token overrides.
 *
 * One row per (theme × token). The published_value is what tenants see;
 * draft_value is the master admin's pending edit. publish() copies draft
 * to published. revert_to_default() nulls both (returns to seeded value).
 *
 * Tenants never touch this table directly — it's master-admin-only.
 * Tenant-customized accent_color still lives on tenants.accent_color
 * and overrides --ia-accent via inline style in app.blade.php.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('theme_settings', function (Blueprint $t) {
            $t->id();
            $t->string('theme', 8);              // 'b' or 'c'
            $t->string('token_key', 64);         // 'ia-bg', 'ia-side-bg', etc. (without --)
            $t->string('published_value', 255);  // active value, never null after seed
            $t->string('draft_value', 255)->nullable();
            $t->unsignedBigInteger('updated_by_user_id')->nullable();
            $t->timestamp('published_at')->nullable();
            $t->timestamps();

            $t->unique(['theme', 'token_key']);
            $t->index('theme');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('theme_settings');
    }
};
