<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('tenant_sales', function (Blueprint $table) {
            $table->uuid('id')->primary();

            // Tenant scope
            $table->foreignUuid('tenant_id')->constrained('tenants')->onDelete('restrict');

            // Identifier — S-YYYYMMDD-### per tenant, set by SaleService
            $table->string('sale_number', 20);
            $table->date('sale_date');

            /*
             * Status lifecycle (mirrors tenant_appointments):
             *   pending → confirmed → in_progress → completed → closed
             *   Any state → cancelled
             * Note: 'refunded' is tracked separately on payment_status.
             */
            $table->enum('status', [
                'pending', 'confirmed', 'in_progress',
                'completed', 'shipped', 'closed', 'cancelled',
            ])->default('pending');

            // Payment (separate from lifecycle)
            $table->enum('payment_status', ['unpaid', 'partial', 'paid', 'refunded'])
                  ->default('unpaid');

            // Customer / staff / appointment linkage
            $table->foreignUuid('customer_id')->nullable()->constrained('tenant_customers')->onDelete('restrict');
            $table->foreignUuid('assigned_staff_id')->nullable()->constrained('tenant_users')->onDelete('restrict');
            $table->foreignUuid('appointment_id')->nullable()->constrained('tenant_appointments')->onDelete('set null');
            $table->foreignUuid('rang_up_by_user_id')->constrained('tenant_users')->onDelete('restrict');

            // Refund self-reference (set on refund rows)
            $table->uuid('refund_of_sale_id')->nullable();

            // Notes
            $table->text('notes')->nullable();

            // Money — all in cents, integer math only, non-negative
            $table->unsignedInteger('subtotal_cents')->default(0);
            $table->unsignedInteger('discount_cents')->default(0);
            $table->unsignedInteger('tax_cents')->default(0);
            $table->unsignedInteger('surcharge_cents')->default(0);
            $table->unsignedInteger('tip_cents')->default(0);
            $table->unsignedInteger('total_cents')->default(0);

            // Settlement
            $table->timestamp('paid_at')->nullable();
            $table->string('payment_method')->nullable();
            // values: cash | card | check | store_credit | mark_paid | split | stripe | paypal
            $table->string('payment_reference')->nullable();
            // values: stripe PI ID, paypal order ID, check number, etc.

            // Register reservation (deferred multi-register)
            $table->string('register_id', 50)->nullable();

            $table->timestamps();

            // Indexes (explicit short names per §2.4 P19)
            $table->index(['tenant_id', 'sale_date'], 'tenant_sales_tenant_date_idx');
            $table->index(['tenant_id', 'status'], 'tenant_sales_tenant_status_idx');
            $table->index(['tenant_id', 'payment_status'], 'tenant_sales_tenant_pay_idx');
            $table->index(['tenant_id', 'sale_number'], 'tenant_sales_tenant_number_idx');
            $table->index('customer_id', 'tenant_sales_customer_idx');
            $table->index('appointment_id', 'tenant_sales_appointment_idx');
            $table->index('refund_of_sale_id', 'tenant_sales_refund_of_idx');
            $table->index('paid_at', 'tenant_sales_paid_at_idx');
        });

        // Self-referential FK added after table creation
        Schema::table('tenant_sales', function (Blueprint $table) {
            $table->foreign('refund_of_sale_id', 'tenant_sales_refund_fk')
                  ->references('id')->on('tenant_sales')
                  ->onDelete('restrict');
        });
    }

    public function down(): void
    {
        Schema::table('tenant_sales', function (Blueprint $table) {
            $table->dropForeign('tenant_sales_refund_fk');
        });
        Schema::dropIfExists('tenant_sales');
    }
};
