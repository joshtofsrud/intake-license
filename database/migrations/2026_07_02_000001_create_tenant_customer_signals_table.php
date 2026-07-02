<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// MARKER-PATCH-482 — structured quality signals for recovery (late_completion, and
// later late_delivery / reschedule / special_order_delay). Queryable per customer
// so the at-risk detector can ask "any recent issues on their last visit?".
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tenant_customer_signals', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('customer_id');
            $table->uuid('appointment_id')->nullable();
            $table->string('type', 40);          // late_completion, late_delivery, ...
            $table->timestamp('occurred_at');
            $table->json('meta')->nullable();
            $table->timestamps();

            // One signal of a given type per appointment (idempotent detection).
            $table->unique(['tenant_id', 'appointment_id', 'type'], 'tcs_dedupe');
            $table->index(['tenant_id', 'customer_id', 'occurred_at'], 'tcs_customer_lookup');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tenant_customer_signals');
    }
};
