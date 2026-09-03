<?php
// MARKER-BILLING-ADDRESS

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->string('billing_address_line1', 191)->nullable()->after('billing_email');
            $table->string('billing_address_line2', 191)->nullable()->after('billing_address_line1');
            $table->string('billing_city', 96)->nullable()->after('billing_address_line2');
            $table->string('billing_state', 32)->nullable()->after('billing_city');
            $table->string('billing_postcode', 24)->nullable()->after('billing_state');
            $table->string('billing_country', 2)->default('US')->after('billing_postcode');
        });
    }

    public function down(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->dropColumn([
                'billing_address_line1', 'billing_address_line2', 'billing_city',
                'billing_state', 'billing_postcode', 'billing_country',
            ]);
        });
    }
};
