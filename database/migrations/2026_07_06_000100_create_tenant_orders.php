<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// MARKER-PATCH-560 — Online Retail Wave 1: orders. Cart and order are ONE
// row with a status lifecycle (cart -> pending_payment -> paid ->
// fulfilling -> fulfilled -> completed; cancelled/abandoned terminal),
// mirroring how sales carry draft/quote states. Stock is never touched by
// carts — SaleService decrements on `paid` when the sale is created.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tenant_orders', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('order_number', 24)->nullable();   // null while a cart; O-YYYYMMDD-### on placement
            $table->string('token', 64)->unique();            // guest cart retrieval
            $table->string('status', 24)->default('cart');
            $table->uuid('customer_id')->nullable();          // null until identified at checkout

            // contact snapshot (order survives customer edits)
            $table->string('contact_first_name', 80)->nullable();
            $table->string('contact_last_name', 80)->nullable();
            $table->string('contact_email', 160)->nullable();
            $table->string('contact_phone', 40)->nullable();

            // fulfillment
            $table->string('fulfillment_type', 20)->nullable(); // pickup | local_delivery | ship
            $table->json('fulfillment_address')->nullable();
            $table->text('fulfillment_notes')->nullable();
            $table->boolean('wants_install')->default(false);

            $table->uuid('location_id')->nullable();          // selling location
            $table->uuid('sale_id')->nullable();              // TenantSale created on payment

            // payment
            $table->string('payment_status', 24)->default('unpaid');
            $table->string('stripe_payment_intent_id', 64)->nullable();
            $table->string('card_brand', 20)->nullable();
            $table->string('card_last4', 4)->nullable();
            $table->timestamp('paid_at')->nullable();

            // money
            $table->integer('subtotal_cents')->default(0);
            $table->integer('discount_cents')->default(0);
            $table->integer('tax_cents')->default(0);
            $table->integer('shipping_cents')->default(0);
            $table->integer('total_cents')->default(0);

            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'status'], 'to_tenant_status');
            $table->index(['tenant_id', 'customer_id'], 'to_tenant_customer');
            $table->index(['tenant_id', 'order_number'], 'to_tenant_number');
            $table->index('stripe_payment_intent_id', 'to_pi');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tenant_orders');
    }
};
