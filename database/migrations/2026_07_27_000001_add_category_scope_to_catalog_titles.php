<?php

// MARKER-TITLE-CATEGORY-SCOPE

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('catalog_title_settings', function (Blueprint $t) {
            // '' = any category. Empty string rather than null so the unique
            // index below actually prevents duplicates.
            $t->string('category_key', 191)->default('')->after('distributor_code');
            // Attribute names to try for {size}, in order, before falling back
            // to the regex patterns. e.g. ["Labeled Size"] on tires.
            $t->json('size_attribute_priority')->nullable()->after('color_attribute_priority');
        });

        Schema::table('catalog_title_settings', function (Blueprint $t) {
            $t->dropUnique('cts_dist_unique');
            $t->unique(['distributor_code', 'category_key'], 'cts_dist_cat_unique');
        });

        Schema::table('catalog_title_patterns', function (Blueprint $t) {
            $t->string('category_key', 191)->default('')->after('distributor_code');
            $t->index(['distributor_code', 'category_key'], 'ctp_dist_cat_idx');
        });

        // Seed the tire rule off whatever HLC already uses, so search and
        // color behaviour carry over and only the title line differs.
        $hlc = DB::table('catalog_title_settings')
            ->where('distributor_code', 'HLC')
            ->where('category_key', '')
            ->first();

        $exists = DB::table('catalog_title_settings')
            ->where('distributor_code', 'HLC')
            ->where('category_key', 'Tires')
            ->exists();

        if ($hlc && ! $exists) {
            DB::table('catalog_title_settings')->insert([
                'distributor_code'         => 'HLC',
                'category_key'             => 'Tires',
                'title_template'           => '{brand} {model} {size} {attr:Tire Compound} {attr:Tire Technology}',
                'subtitle_template'        => $hlc->subtitle_template,
                'search_template'          => $hlc->search_template,
                'color_attribute_priority' => $hlc->color_attribute_priority,
                'size_attribute_priority'  => json_encode(['Labeled Size']),
                'is_active'                => 1,
                'notes'                    => 'Tires: size from the Labeled Size attribute, not the description.',
                'created_at'               => now(),
                'updated_at'               => now(),
            ]);
        }
    }

    public function down(): void
    {
        DB::table('catalog_title_settings')
            ->where('distributor_code', 'HLC')
            ->where('category_key', 'Tires')
            ->delete();

        Schema::table('catalog_title_patterns', function (Blueprint $t) {
            $t->dropIndex('ctp_dist_cat_idx');
            $t->dropColumn('category_key');
        });

        Schema::table('catalog_title_settings', function (Blueprint $t) {
            $t->dropUnique('cts_dist_cat_unique');
            $t->dropColumn(['category_key', 'size_attribute_priority']);
        });

        Schema::table('catalog_title_settings', function (Blueprint $t) {
            $t->unique('distributor_code', 'cts_dist_unique');
        });
    }
};
