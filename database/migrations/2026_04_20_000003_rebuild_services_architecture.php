<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // ================================================================
        // PHASE 1A — Drop everything in FK-safe order (clean slate)
        // ================================================================
        Schema::disableForeignKeyConstraints();

        Schema::dropIfExists('tenant_appointment_charges');
        Schema::dropIfExists('tenant_appointment_notes');
        Schema::dropIfExists('tenant_appointment_responses');
        Schema::dropIfExists('tenant_appointment_addons');
        Schema::dropIfExists('tenant_appointment_items');
        Schema::dropIfExists('tenant_appointments');

        Schema::dropIfExists('tenant_item_addons');
        Schema::dropIfExists('tenant_item_tier_prices');
        Schema::dropIfExists('tenant_addons');
        Schema::dropIfExists('tenant_service_items');
        Schema::dropIfExists('tenant_service_tiers');
        Schema::dropIfExists('tenant_service_categories');

        Schema::enableForeignKeyConstraints();

        // ================================================================
        // PHASE 1B — Rebuild with the new architecture
        // ================================================================

        // ----------------------------------------------------------------
        // Categories (unchanged from before)
        // ----------------------------------------------------------------
        Schema::create('tenant_service_categories', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('slug', 100);
            $table->text('description')->nullable();
            $table->string('image_url')->nullable();
            $table->boolean('is_active')->default(true);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();

            $table->unique(['tenant_id', 'slug']);
        });

        // ----------------------------------------------------------------
        // Service items — flat, with prep/duration/cleanup bookend
        // ----------------------------------------------------------------
        Schema::create('tenant_service_items', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('category_id')->constrained('tenant_service_categories')->cascadeOnDelete();

            $table->string('name');
            $table->string('slug', 100);
            $table->text('description')->nullable();
            $table->string('image_url')->nullable();

            // Price in cents — ONE price per service, no tiers
            $table->unsignedInteger('price_cents')->default(0);

            // Bookend time (staff-only, not customer-facing)
            $table->unsignedSmallInteger('prep_before_minutes')->default(0);
            $table->unsignedSmallInteger('cleanup_after_minutes')->default(0);

            // Time slot mode — customer-facing duration
            $table->unsignedSmallInteger('duration_minutes')->default(60);

            // Drop-off mode — how many capacity slots this job uses (1-4)
            $table->unsignedTinyInteger('slot_weight')->default(1);

            $table->boolean('is_active')->default(true);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();

            $table->unique(['tenant_id', 'slug']);
            $table->index(['tenant_id', 'is_active']);
        });

        // ----------------------------------------------------------------
        // Add-ons library — tenant-wide catalog
        // ----------------------------------------------------------------
        Schema::create('tenant_addons', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained()->cascadeOnDelete();

            $table->string('name');
            $table->text('description')->nullable();

            // Library default price (in cents) and default duration
            $table->unsignedInteger('price_cents')->default(0);
            $table->unsignedSmallInteger('default_duration_minutes')->default(0);

            $table->boolean('is_active')->default(true);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();

            $table->index(['tenant_id', 'is_active']);
        });

        // ----------------------------------------------------------------
        // Service ↔ Add-on pivot with per-service overrides
        // override_* columns NULL = use library default
        // ----------------------------------------------------------------
        Schema::create('tenant_service_addons', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('service_item_id')
                  ->constrained('tenant_service_items')
                  ->cascadeOnDelete();
            $table->foreignUuid('addon_id')
                  ->constrained('tenant_addons')
                  ->cascadeOnDelete();

            // Per-service overrides (NULL = use library default)
            $table->unsignedSmallInteger('override_duration_minutes')->nullable();
            $table->unsignedInteger('override_price_cents')->nullable();

            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();

            $table->unique(['service_item_id', 'addon_id']);
        });

        // ================================================================
        // PHASE 1C — Rebuild appointments (clean slate, no tier refs)
        // ================================================================

        Schema::create('tenant_appointments', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('customer_id')->nullable()->constrained('tenant_customers')->nullOnDelete();

            $table->string('ra_number', 30)->index();

            // Customer snapshot
            $table->string('customer_first_name');
            $table->string('customer_last_name');
            $table->string('customer_email');
            $table->string('customer_phone', 32)->nullable();

            // Scheduling
            $table->date('appointment_date');
            $table->time('appointment_time')->nullable();
            $table->time('appointment_end_time')->nullable();
            $table->unsignedSmallInteger('total_duration_minutes')->default(0);
            $table->unsignedTinyInteger('slot_weight')->default(1);
            $table->boolean('slot_weight_overridden')->default(false);
            $table->unsignedTinyInteger('slot_weight_auto')->default(1);

            $table->string('receiving_method_snapshot')->nullable();
            $table->string('receiving_time_snapshot')->nullable();
            $table->string('tracking_number')->nullable();

            $table->enum('status', [
                'pending', 'confirmed', 'in_progress',
                'completed', 'shipped', 'closed',
                'cancelled', 'refunded',
            ])->default('pending')->index();

            $table->enum('payment_status', ['unpaid', 'partial', 'paid', 'refunded'])
                  ->default('unpaid')->index();
            $table->string('payment_method')->nullable();
            $table->string('stripe_payment_intent_id')->nullable();
            $table->string('paypal_order_id')->nullable();

            $table->unsignedInteger('subtotal_cents')->default(0);
            $table->unsignedInteger('tax_cents')->default(0);
            $table->unsignedInteger('total_cents')->default(0);
            $table->unsignedInteger('paid_cents')->default(0);

            $table->text('staff_notes')->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'ra_number']);
            $table->index(['tenant_id', 'appointment_date']);
            $table->index(['tenant_id', 'status']);
            $table->index('customer_email');
        });

        // Appointment line items — NO tier_id, NO tier_name_snapshot
        Schema::create('tenant_appointment_items', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('appointment_id')->constrained('tenant_appointments')->cascadeOnDelete();
            $table->foreignUuid('service_item_id')->nullable()
                  ->constrained('tenant_service_items')->nullOnDelete();

            $table->string('item_name_snapshot');
            $table->unsignedInteger('price_cents');
            $table->unsignedSmallInteger('duration_minutes_snapshot')->default(0);

            $table->timestamps();
        });

        // Appointment add-ons — snapshot the per-service override values
        Schema::create('tenant_appointment_addons', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('appointment_id')->constrained('tenant_appointments')->cascadeOnDelete();
            $table->foreignUuid('addon_id')->nullable()
                  ->constrained('tenant_addons')->nullOnDelete();

            $table->string('addon_name_snapshot');
            $table->unsignedInteger('price_cents');
            $table->unsignedSmallInteger('duration_minutes_snapshot')->default(0);

            $table->timestamps();
        });

        Schema::create('tenant_appointment_responses', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('appointment_id')->constrained('tenant_appointments')->cascadeOnDelete();
            $table->string('field_key_snapshot', 100);
            $table->string('field_label_snapshot');
            $table->text('response_value')->nullable();
            $table->timestamps();
        });

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
        // Irreversible — dev reset only. If you need to roll back,
        // restore from backup or re-run the original migrations fresh.
        throw new \RuntimeException(
            'This migration cannot be rolled back. It destructively rebuilt the service architecture. '
            . 'To revert, restore your database from a pre-migration backup.'
        );
    }
};
