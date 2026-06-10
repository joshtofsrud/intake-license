<?php
// MARKER-PATCH-219B — rentals adopt the sales-as-money model.

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenant_sales', function (Blueprint $t) {
            $t->foreignUuid('rental_id')->nullable()
              ->after('appointment_id')
              ->constrained('tenant_rentals')->nullOnDelete();
            $t->index('rental_id', 'tenant_sales_rental_idx');
        });

        // One ledger platform-wide: rental money lives in
        // tenant_sale_payments through linked sales. This table only ever
        // held dogfood test rows (patch-219 shipped 2026-06-10).
        Schema::dropIfExists('tenant_rental_payments');
    }

    public function down(): void
    {
        Schema::create('tenant_rental_payments', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('tenant_id')->constrained('tenants')->onDelete('restrict');
            $t->foreignUuid('rental_id')->constrained('tenant_rentals')->onDelete('restrict');
            $t->integer('amount_cents');
            $t->string('kind', 24);
            $t->string('source', 16);
            $t->string('method', 24)->nullable();
            $t->string('external_reference')->nullable();
            $t->foreignUuid('recorded_by_user_id')->nullable()->constrained('tenant_users')->nullOnDelete();
            $t->dateTime('recorded_at');
            $t->text('notes')->nullable();
            $t->timestamps();
            $t->index(['tenant_id', 'recorded_at'], 'trp_tenant_recorded');
            $t->index('rental_id', 'trp_rental');
        });

        Schema::table('tenant_sales', function (Blueprint $t) {
            $t->dropForeign(['rental_id']);
            $t->dropIndex('tenant_sales_rental_idx');
            $t->dropColumn('rental_id');
        });
    }
};
