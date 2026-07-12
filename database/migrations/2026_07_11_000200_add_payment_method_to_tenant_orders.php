<?php
// MARKER-PATCH-631 — online orders can be placed with manual payment methods
// (Venmo, Cash App, custom); record which one so confirmation instructions
// and admin mark-paid know the method.

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenant_orders', function (Blueprint $table) {
            $table->string('payment_method', 40)->nullable()->after('payment_status');
        });
    }

    public function down(): void
    {
        Schema::table('tenant_orders', function (Blueprint $table) {
            $table->dropColumn('payment_method');
        });
    }
};

