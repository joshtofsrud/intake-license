<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * patch-99: add 'color' and 'size' as item-identity fields on
 * tenant_inventory_items. They're plain nullable strings — each
 * size/color combination is a separate item with its own SKU and
 * stock. No variant hierarchy (decided 2026-05-17).
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('tenant_inventory_items', function (Blueprint $table) {
            $table->string('color', 60)->nullable()->after('description');
            $table->string('size', 60)->nullable()->after('color');
        });
    }

    public function down(): void
    {
        Schema::table('tenant_inventory_items', function (Blueprint $table) {
            $table->dropColumn(['color', 'size']);
        });
    }
};
