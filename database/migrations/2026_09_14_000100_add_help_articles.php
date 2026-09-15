<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * MARKER-HELP-ADMIN — help articles are pages, so they get the builder free.
 *
 * Additive only: new columns default to the existing behaviour (kind 'page'),
 * so every row already in tenant_pages keeps working untouched while the old
 * release is briefly still serving during a deploy.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('help_categories', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->string('name');
            $t->unsignedSmallInteger('sort')->default(0);
            $t->timestamps();
        });

        Schema::table('tenant_pages', function (Blueprint $t) {
            // 'page' = a normal site or marketing page. 'howto' = a help article.
            $t->string('kind', 16)->default('page')->index();

            $t->uuid('help_category_id')->nullable()->index();

            // The screen that this article explains. A help button on that
            // screen opens the article carrying its key.
            $t->string('help_key', 64)->nullable()->index();

            // Gate. Null tier means every plan.
            $t->string('help_min_tier', 24)->nullable();
            $t->json('help_addons')->nullable();

            // 'lock' = show the title and name the add-on. 'hide' = invisible.
            $t->string('help_locked_mode', 8)->default('lock');

            $t->unsignedSmallInteger('help_sort')->default(0);
        });
    }

    public function down(): void
    {
        Schema::table('tenant_pages', function (Blueprint $t) {
            $t->dropColumn([
                'kind', 'help_category_id', 'help_key',
                'help_min_tier', 'help_addons', 'help_locked_mode', 'help_sort',
            ]);
        });

        Schema::dropIfExists('help_categories');
    }
};
