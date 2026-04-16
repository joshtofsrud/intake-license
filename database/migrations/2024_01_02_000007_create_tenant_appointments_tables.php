<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // ----------------------------------------------------------------
        // Appointments (booking header)
        // ----------------------------------------------------------------
        Schema::create('tenant_appointments', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('customer_id')->nullable()->constrained('tenant_customers')->nullOnDelete();

            // RA number — tenant-scoped, auto-incremented prefix
            $table->string('ra_number', 30)->index();

            // Customer snapshot (denormalised for historical accuracy)
            $table->string('customer_first_name');
            $table->string('customer_last_name');
            $table->string('customer_email');
            $table->string('customer_phone', 32)->nullable();

            // Scheduling
            $table->date('appointment_date');
            $table->string('receiving_method_snapshot')->nullable();
            $table->string('receiving_time_snapshot')->nullable();
            $table->string('tracking_number')->nullable();

            /*
             * Status lifecycle:
             *   pending → confirmed → in_progress → completed → closed
             *   Any state → cancelled | refunded
             */
            $table->enum('status', [
                'pending', 'confirmed', 'in_progress',
                'completed', 'shipped', 'closed',
                'cancelled', 'refunded',
            ])->default('pending')->index();

            // Payment
            $table->enum('payment_status', ['unpaid', 'partial', 'paid', 'refunded'])
                  ->default('unpaid')->index();
            $table->string('payment_method')->nullable();   // stripe | paypal | cash | other
            $table->string('stripe_payment_intent_id')->nullable();
            $table->string('paypal_order_id')->nullable();

            // Financials (cents)
            $table->unsignedInteger('subtotal_cents')->default(0);
            $table->unsignedInteger('tax_cents')->default(0);
            $table->unsignedInteger('total_cents')->default(0);
            $table->unsignedInteger('paid_cents')->default(0);

            // Internal notes / special instructions
            $table->text('staff_notes')->nullable();

            $table->timestamps();

            $table->unique(['tenant_id', 'ra_number']);
            $table->index(['tenant_id', 'appointment_date']);
            $table->index(['tenant_id', 'status']);
            $table->index('customer_email');
        });

        // ----------------------------------------------------------------
        // Appointment line items
        // ----------------------------------------------------------------
        Schema::create('tenant_appointment_items', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('appointment_id')->constrained('tenant_appointments')->cascadeOnDelete();
            $table->foreignUuid('item_id')->nullable()->constrained('tenant_service_items')->nullOnDelete();
            $table->foreignUuid('tier_id')->nullable()->constrained('tenant_service_tiers')->nullOnDelete();

            // Snapshots for historical accuracy
            $table->string('item_name_snapshot');
            $table->string('tier_name_snapshot');
            $table->unsignedInteger('price_cents');

            $table->timestamps();
        });

        // ----------------------------------------------------------------
        // Appointment add-ons
        // ----------------------------------------------------------------
        Schema::create('tenant_appointment_addons', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('appointment_id')->constrained('tenant_appointments')->cascadeOnDelete();
            $table->foreignUuid('addon_id')->nullable()->constrained('tenant_addons')->nullOnDelete();
            $table->string('addon_name_snapshot');
            $table->unsignedInteger('price_cents');
            $table->timestamps();
        });

        // ----------------------------------------------------------------
        // Appointment form responses
        // ----------------------------------------------------------------
        Schema::create('tenant_appointment_responses', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('appointment_id')->constrained('tenant_appointments')->cascadeOnDelete();
            $table->string('field_key_snapshot', 100);
            $table->string('field_label_snapshot');
            $table->text('response_value')->nullable();
            $table->timestamps();
        });

        // ----------------------------------------------------------------
        // Appointment notes (timestamped log)
        // ----------------------------------------------------------------
        Schema::create('tenant_appointment_notes', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('appointment_id')->constrained('tenant_appointments')->cascadeOnDelete();
            $table->foreignUuid('user_id')->nullable()->constrained('tenant_users')->nullOnDelete();
            $table->enum('note_type', ['staff', 'system', 'customer'])->default('staff');
            $table->boolean('is_customer_visible')->default(false);
            $table->text('note_content');
            $table->timestamp('created_at')->useCurrent();

            $table->index(['appointment_id', 'created_at']);
        });

        // ----------------------------------------------------------------
        // Appointment charges (ad-hoc after-booking line items)
        // ----------------------------------------------------------------
        Schema::create('tenant_appointment_charges', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('appointment_id')->constrained('tenant_appointments')->cascadeOnDelete();
            $table->string('description');
            $table->unsignedInteger('amount_cents');
            $table->boolean('is_paid')->default(false);
            $table->timestamp('created_at')->useCurrent();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tenant_appointment_charges');
        Schema::dropIfExists('tenant_appointment_notes');
        Schema::dropIfExists('tenant_appointment_responses');
        Schema::dropIfExists('tenant_appointment_addons');
        Schema::dropIfExists('tenant_appointment_items');
        Schema::dropIfExists('tenant_appointments');
    }
};
