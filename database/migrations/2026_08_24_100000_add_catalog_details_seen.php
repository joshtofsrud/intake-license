<?php
// MARKER-DETAILS-WATCH — baseline of the catalog's color/size/description as
// last seen by this item, so the details watch flags changes, not backlog.

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenant_inventory_items', function (Blueprint $table) {
            $table->json('catalog_details_seen')->nullable()->after('catalog_title_seen');
        });
    }

    public function down(): void
    {
        Schema::table('tenant_inventory_items', function (Blueprint $table) {
            $table->dropColumn('catalog_details_seen');
        });
    }
};
