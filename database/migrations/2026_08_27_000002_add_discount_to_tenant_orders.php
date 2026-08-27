<?php
// MARKER-SHOP-DISCOUNT

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenant_orders', function (Blueprint $table) {
            // discount_cents already exists on this table and is already
            // subtracted by CartService::recompute(); these record WHICH code.
            $table->string('discount_code', 40)->nullable()->after('discount_cents');
            $table->uuid('discount_redemption_id')->nullable()->after('discount_code');
        });
    }

    public function down(): void
    {
        Schema::table('tenant_orders', function (Blueprint $table) {
            $table->dropColumn(['discount_code', 'discount_redemption_id']);
        });
    }
};
