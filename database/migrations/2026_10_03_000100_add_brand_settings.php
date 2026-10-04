<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * MARKER-BRAND — Intake's own brand assets, set from master admin › Brand,
 * plus a per-page share image for builder pages.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('site_settings', function (Blueprint $t) {
            if (! Schema::hasColumn('site_settings', 'brand')) {
                $t->json('brand')->nullable();          // slot => storage path on the public disk
            }
            if (! Schema::hasColumn('site_settings', 'brand_history')) {
                $t->json('brand_history')->nullable();  // up to 5 earlier sets, newest first
            }
        });
        Schema::table('tenant_pages', function (Blueprint $t) {
            if (! Schema::hasColumn('tenant_pages', 'og_image_url')) {
                $t->string('og_image_url', 500)->nullable()->after('meta_description');
            }
        });
    }

    public function down(): void
    {
        Schema::table('site_settings', function (Blueprint $t) {
            $t->dropColumn(['brand', 'brand_history']);
        });
        Schema::table('tenant_pages', function (Blueprint $t) {
            $t->dropColumn('og_image_url');
        });
    }
};
