<?php
// MARKER-BILLING-TAX-ROOM

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenant_charge_runs', function (Blueprint $table) {
            $table->unsignedInteger('subtotal_cents')->nullable()->after('amount_cents');
            $table->unsignedInteger('tax_cents')->default(0)->after('subtotal_cents');
            $table->string('tax_jurisdiction', 64)->nullable()->after('tax_cents');
            // 5 decimal places: some jurisdictions combine to rates like 0.08825
            $table->decimal('tax_rate', 7, 5)->nullable()->after('tax_jurisdiction');
        });

        // Existing runs were untaxed, so subtotal == amount. Backfilled rather
        // than left null, so nothing has to special-case an empty column.
        DB::statement('UPDATE tenant_charge_runs SET subtotal_cents = amount_cents WHERE subtotal_cents IS NULL');
    }

    public function down(): void
    {
        Schema::table('tenant_charge_runs', function (Blueprint $table) {
            $table->dropColumn(['subtotal_cents', 'tax_cents', 'tax_jurisdiction', 'tax_rate']);
        });
    }
};
