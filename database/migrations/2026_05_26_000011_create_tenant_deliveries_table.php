<?php
// MARKER-PATCH-152A

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * tenant_deliveries — internal pickup/dropoff schedule.
 *
 * Not customer-facing. Tenant schedules these privately based on
 * phone calls, texts, or work-order follow-up. Customer is notified
 * on create via existing email + SMS infrastructure (152-c).
 *
 * Always linked to a customer. Optionally linked to a work order
 * or appointment for context. Address defaults from the customer
 * record but stored on the delivery row so it can be overridden
 * per-trip without mutating the customer profile.
 *
 * Status pipeline: scheduled -> completed | cancelled
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tenant_deliveries', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained()->cascadeOnDelete();

            $table->enum('type', ['pickup', 'dropoff']);
            $table->enum('status', ['scheduled', 'completed', 'cancelled'])->default('scheduled');

            $table->dateTime('scheduled_at');
            $table->unsignedSmallInteger('window_minutes')->default(30);

            $table->string('address', 500)->nullable();

            $table->foreignUuid('customer_id')->constrained('tenant_customers')->cascadeOnDelete();
            $table->foreignUuid('work_order_id')->nullable();
            $table->foreignUuid('appointment_id')->nullable();
            $table->foreignUuid('delivery_resource_id')->nullable()
                  ->constrained('tenant_delivery_resources')->nullOnDelete();

            $table->text('notes')->nullable();

            $table->timestamp('notified_at')->nullable();
            $table->string('notification_channels', 32)->nullable();

            $table->timestamp('completed_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();

            $table->timestamps();

            $table->index(['tenant_id', 'scheduled_at'], 'td_tenant_when');
            $table->index(['tenant_id', 'customer_id', 'scheduled_at'], 'td_tenant_cust_when');
            $table->index(['tenant_id', 'delivery_resource_id', 'scheduled_at'], 'td_tenant_res_when');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tenant_deliveries');
    }
};