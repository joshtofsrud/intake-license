<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// MARKER-GOOGLE-FEED: Google product category per category / item, and a per-item "leave out of the feed".
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('tenant_inventory_categories') && ! Schema::hasColumn('tenant_inventory_categories', 'google_category')) {
            Schema::table('tenant_inventory_categories', fn (Blueprint $t) => $t->string('google_category', 300)->nullable());
        }
        if (Schema::hasTable('tenant_inventory_items') && ! Schema::hasColumn('tenant_inventory_items', 'feed_exclude')) {
            Schema::table('tenant_inventory_items', function (Blueprint $t) {
                $t->boolean('feed_exclude')->default(false);
                $t->string('google_category', 300)->nullable();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('tenant_inventory_categories', 'google_category')) {
            Schema::table('tenant_inventory_categories', fn (Blueprint $t) => $t->dropColumn('google_category'));
        }
        if (Schema::hasColumn('tenant_inventory_items', 'feed_exclude')) {
            Schema::table('tenant_inventory_items', fn (Blueprint $t) => $t->dropColumn(['feed_exclude', 'google_category']));
        }
    }
};
