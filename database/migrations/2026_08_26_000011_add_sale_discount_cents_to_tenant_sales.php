<?php
// MARKER-SALE-DISCOUNT

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenant_sales', function (Blueprint $table) {
            // A discount applied to the WHOLE sale, in cents. Separate from
            // discount_cents, which is the derived sum of item discounts and
            // is rewritten by recalculate() on every save. Defaulting to 0
            // means every existing sale keeps exactly the total it has.
            $table->unsignedInteger('sale_discount_cents')->default(0)->after('discount_cents');

            // When the discount came from a code, the redemption that recorded
            // it. Nullable: manual whole-sale discounts have no code.
            $table->uuid('discount_redemption_id')->nullable()->after('sale_discount_cents');
        });
    }

    public function down(): void
    {
        Schema::table('tenant_sales', function (Blueprint $table) {
            $table->dropColumn(['sale_discount_cents', 'discount_redemption_id']);
        });
    }
};
