<?php
// MARKER-PRICE-SEED — which list price a distributor's new items start at.

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('tenant_distributor_catalog_subscriptions', 'price_seed')) {
            return;
        }
        Schema::table('tenant_distributor_catalog_subscriptions', function (Blueprint $t) {
            // 'map' for everyone already connected, so no shop's prices move on
            // deploy. Subscriptions created from now on start at 'msrp'
            // (DistributorController::connection).
            $t->string('price_seed', 8)->default('map')->after('data_priority');
        });
    }

    public function down(): void
    {
        Schema::table('tenant_distributor_catalog_subscriptions', function (Blueprint $t) {
            $t->dropColumn('price_seed');
        });
    }
};
