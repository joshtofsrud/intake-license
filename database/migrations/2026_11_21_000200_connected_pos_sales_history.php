<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * MARKER-POS-SALES-HISTORY: past sales from a shop's Connected POS.
 *
 * They live in tables of their own, never in tenant_sales, so they cannot reach stock,
 * the cash drawer, tax or the payment ledger: nothing that reads Intake's own sales
 * can see them by accident. The screens that are meant to show them (a customer's
 * activity, Buying, the "Connected POS sales" report, Recovery) read these tables
 * on purpose and say where the numbers came from.
 *
 * A sale keeps the Connected POS's own ids for its customer and products. Which Intake
 * customer and item those are is looked up when a screen reads them, so a customer or
 * item brought in later picks up its history without the sales being read again.
 *
 * Additive throughout.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('tenant_pos_sales')) {
            Schema::create('tenant_pos_sales', function (Blueprint $t) {
                $t->id();
                $t->uuid('tenant_id');
                $t->string('external_id', 100);
                $t->string('number', 60)->nullable();           // the receipt / invoice number there
                $t->dateTime('sold_at');                        // UTC
                $t->date('sale_date');                          // the shop's own calendar day
                $t->string('external_outlet_id', 100)->nullable();
                $t->string('external_customer_id', 100)->nullable();
                $t->integer('subtotal_cents')->default(0);      // before tax; negative for a return
                $t->integer('tax_cents')->default(0);
                $t->integer('total_cents')->default(0);
                $t->timestamps();
                $t->unique(['tenant_id', 'external_id'], 'tps_sale_unique');
                $t->index(['tenant_id', 'sale_date'], 'tps_sale_date');
                $t->index(['tenant_id', 'external_customer_id', 'sold_at'], 'tps_sale_customer');
            });
        }

        if (! Schema::hasTable('tenant_pos_sale_lines')) {
            Schema::create('tenant_pos_sale_lines', function (Blueprint $t) {
                $t->id();
                $t->uuid('tenant_id');
                $t->unsignedBigInteger('pos_sale_id');
                $t->string('external_product_id', 100)->nullable();
                $t->string('name', 255)->nullable();            // as the Connected POS had it when read
                $t->string('sku', 100)->nullable();
                $t->decimal('quantity', 12, 3)->default(0);     // negative on a return
                $t->integer('unit_price_cents')->default(0);    // before tax
                $t->integer('total_cents')->default(0);         // the line, before tax, after its discount
                $t->integer('discount_cents')->default(0);
                $t->integer('tax_cents')->default(0);
                $t->integer('unit_cost_cents')->nullable();
                $t->index('pos_sale_id', 'tpsl_sale');
                $t->index(['tenant_id', 'external_product_id'], 'tpsl_product');
            });
        }

        Schema::table('tenant_pos_connections', function (Blueprint $t) {
            if (! Schema::hasColumn('tenant_pos_connections', 'sales_in')) {
                $t->boolean('sales_in')->default(false);  // keep new sales coming in (2 Way Data only)
                $t->json('sales_options')->nullable();    // how far back; sales with no customer
                $t->json('sales_state')->nullable();      // the run, for live progress and Stop/Resume
            }
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tenant_pos_sale_lines');
        Schema::dropIfExists('tenant_pos_sales');
    }
};
