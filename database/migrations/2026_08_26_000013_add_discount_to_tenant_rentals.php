<?php
// MARKER-RENTAL-DISCOUNT

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenant_rentals', function (Blueprint $table) {
            $table->unsignedInteger('discount_cents')->default(0)->after('subtotal_cents');
            $table->string('discount_code', 40)->nullable()->after('discount_cents');
            $table->uuid('discount_redemption_id')->nullable()->after('discount_code');
        });
    }

    public function down(): void
    {
        Schema::table('tenant_rentals', function (Blueprint $table) {
            $table->dropColumn(['discount_cents', 'discount_code', 'discount_redemption_id']);
        });
    }
};
