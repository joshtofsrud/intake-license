<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tenant_class_registrations', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained()->cascadeOnDelete();

            $table->foreignUuid('class_session_id')
                ->constrained('tenant_class_sessions')
                ->cascadeOnDelete();

            $table->foreignUuid('customer_id')->constrained('tenant_customers')->cascadeOnDelete();

            $table->enum('status', ['registered', 'waitlisted', 'cancelled', 'checked_in', 'no_show'])
                ->default('registered');

            $table->enum('payment_method', ['membership', 'pack', 'per_class', 'cash']);

            $table->foreignUuid('membership_id')
                ->nullable()
                ->constrained('tenant_customer_memberships')
                ->nullOnDelete();

            $table->foreignUuid('pack_id')
                ->nullable()
                ->constrained('tenant_customer_packs')
                ->nullOnDelete();

            $table->unsignedInteger('paid_cents')->default(0);
            $table->string('stripe_payment_intent_id')->nullable();
            $table->unsignedSmallInteger('waitlist_position')->nullable();

            $table->timestamp('registered_at')->useCurrent();
            $table->timestamp('cancelled_at')->nullable();

            $table->json('metadata')->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->unique(['class_session_id', 'customer_id', 'status'], 'one_active_registration');
            $table->index(['tenant_id', 'class_session_id', 'status'], 'tcr_tenant_session_status');
            $table->index(['tenant_id', 'customer_id'], 'tcr_tenant_customer');
            $table->index(['class_session_id', 'waitlist_position'], 'tcr_session_waitlist');
            $table->index('stripe_payment_intent_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tenant_class_registrations');
    }
};
