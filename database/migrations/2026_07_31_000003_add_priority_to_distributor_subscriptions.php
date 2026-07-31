<?php

// MARKER-DIST-MULTI — per-tenant data priority. Lower wins.

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenant_distributor_catalog_subscriptions', function (Blueprint $t) {
            // Which feed's title/attributes/description win on a product two
            // distributors both carry. Purchasing is a separate question and
            // is not decided here.
            $t->unsignedTinyInteger('data_priority')->default(50)->after('is_active');
        });
    }

    public function down(): void
    {
        Schema::table('tenant_distributor_catalog_subscriptions', function (Blueprint $t) {
            $t->dropColumn('data_priority');
        });
    }
};
