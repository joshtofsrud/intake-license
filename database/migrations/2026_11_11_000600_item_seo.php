<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// MARKER-ITEM-SEO: what Google and link previews show for an item, when the shop wants to word it itself.
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('tenant_inventory_items') && ! Schema::hasColumn('tenant_inventory_items', 'seo_title')) {
            Schema::table('tenant_inventory_items', function (Blueprint $t) {
                $t->string('seo_title', 120)->nullable();
                $t->string('seo_description', 300)->nullable();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('tenant_inventory_items', 'seo_title')) {
            Schema::table('tenant_inventory_items', fn (Blueprint $t) => $t->dropColumn(['seo_title', 'seo_description']));
        }
    }
};
