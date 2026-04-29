<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tenant_customer_packs', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained()->cascadeOnDelete();

            $table->foreignUuid('customer_id')->constrained('tenant_customers')->cascadeOnDelete();

            $table->foreignUuid('product_id')
                ->constrained('tenant_class_pack_products')
                ->cascadeOnDelete();

            $table->unsignedSmallInteger('credits_total');
            $table->unsignedSmallInteger('credits_remaining');
            $table->date('expires_at');

            $table->enum('status', ['active', 'exhausted', 'expired'])->default('active');

            $table->string('stripe_payment_intent_id')->nullable();
            $table->json('metadata')->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->index(['tenant_id', 'customer_id', 'status', 'expires_at'],
                'pack_consumption_order');
            $table->index(['tenant_id', 'customer_id']);
            $table->index('stripe_payment_intent_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tenant_customer_packs');
    }
};
