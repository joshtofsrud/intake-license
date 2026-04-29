<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tenant_customer_memberships', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained()->cascadeOnDelete();

            $table->foreignUuid('customer_id')->constrained('tenant_customers')->cascadeOnDelete();

            $table->foreignUuid('product_id')
                ->constrained('tenant_class_membership_products')
                ->cascadeOnDelete();

            $table->enum('status', ['active', 'cancelled', 'expired', 'paused'])
                ->default('active');

            $table->date('current_period_start');
            $table->date('current_period_end');

            $table->unsignedSmallInteger('classes_used_this_period')->default(0);
            $table->string('stripe_subscription_id')->nullable();
            $table->json('metadata')->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->unique(['tenant_id', 'customer_id', 'status'], 'one_active_membership');
            $table->index(['tenant_id', 'customer_id']);
            $table->index(['tenant_id', 'status']);
            $table->index('stripe_subscription_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tenant_customer_memberships');
    }
};
