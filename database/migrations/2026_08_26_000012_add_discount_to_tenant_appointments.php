<?php
// MARKER-APPT-DISCOUNT

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenant_appointments', function (Blueprint $table) {
            // Whole-appointment discount in cents. Defaults to 0, so every
            // existing appointment keeps the total it already has.
            $table->unsignedInteger('discount_cents')->default(0)->after('subtotal_cents');
            $table->string('discount_code', 40)->nullable()->after('discount_cents');
            $table->uuid('discount_redemption_id')->nullable()->after('discount_code');
        });
    }

    public function down(): void
    {
        Schema::table('tenant_appointments', function (Blueprint $table) {
            $table->dropColumn(['discount_cents', 'discount_code', 'discount_redemption_id']);
        });
    }
};
