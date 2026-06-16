<?php
// MARKER-PATCH-HLC16

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('catalog_title_settings', function (Blueprint $table) {
            $table->id();
            $table->string('distributor_code', 32)->default('*'); // '*' = global default
            $table->string('title_template', 255)->default('{brand} {model} {size} {color}');
            $table->string('subtitle_template', 255)->default('{mpn}');
            $table->json('color_attribute_priority')->nullable(); // ["Color","Primary Color"]
            $table->boolean('is_active')->default(true);
            $table->string('notes', 255)->nullable();
            $table->timestamps();
            $table->unique('distributor_code', 'cts_dist_unique');
        });

        Schema::create('catalog_title_patterns', function (Blueprint $table) {
            $table->id();
            $table->string('distributor_code', 32)->default('*');
            $table->string('label', 64);
            $table->string('pattern', 255);          // regex body (no delimiters)
            $table->integer('sort_order')->default(0); // lower = higher priority
            $table->boolean('is_active')->default(true);
            $table->string('notes', 255)->nullable();
            $table->timestamps();
            $table->index(['distributor_code', 'is_active', 'sort_order'], 'ctp_lookup');
        });

        Schema::table('platform_distributor_catalogs', function (Blueprint $table) {
            $table->string('display_name', 255)->nullable()->after('name');
            $table->string('display_subtitle', 128)->nullable()->after('display_name');
        });
    }

    public function down(): void
    {
        Schema::table('platform_distributor_catalogs', function (Blueprint $table) {
            $table->dropColumn(['display_name', 'display_subtitle']);
        });
        Schema::dropIfExists('catalog_title_patterns');
        Schema::dropIfExists('catalog_title_settings');
    }
};
