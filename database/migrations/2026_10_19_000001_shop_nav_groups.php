<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * MARKER-SHOP-MENU-GROUPS: a shop menu link can sit in a dropdown group,
 * with an optional icon and one-line description. The groups themselves live
 * in the tenant's settings (shop_menu.groups). Additive only.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenant_nav_items', function (Blueprint $t) {
            if (! Schema::hasColumn('tenant_nav_items', 'group_key'))   $t->string('group_key', 20)->nullable();
            if (! Schema::hasColumn('tenant_nav_items', 'description')) $t->string('description', 80)->nullable();
            if (! Schema::hasColumn('tenant_nav_items', 'icon'))        $t->string('icon', 20)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('tenant_nav_items', function (Blueprint $t) {
            foreach (['group_key', 'description', 'icon'] as $c) {
                if (Schema::hasColumn('tenant_nav_items', $c)) $t->dropColumn($c);
            }
        });
    }
};
