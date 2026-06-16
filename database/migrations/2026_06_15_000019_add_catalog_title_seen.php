<?php
// MARKER-PATCH-HLC19

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenant_inventory_items', function (Blueprint $table) {
            // The catalog display_name this item last reconciled against. Null on
            // pre-existing items -> they flag once so the composed title can be
            // adopted. Updated only when the tenant adopts or dismisses a change.
            $table->string('catalog_title_seen', 255)->nullable()->after('catalog_upc');
        });
    }

    public function down(): void
    {
        Schema::table('tenant_inventory_items', function (Blueprint $table) {
            $table->dropColumn('catalog_title_seen');
        });
    }
};
