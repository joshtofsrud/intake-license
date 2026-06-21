<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// MARKER-PATCH-382 — holds a booking slot while a deposit is being charged.
// The appointment is materialized from this row only after payment succeeds.
return new class extends Migration {
    public function up(): void
    {
        Schema::create('tenant_pending_bookings', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->onDelete('restrict');

            // public handle returned to the browser for the finalize call.
            $table->string('token', 48);

            // pending -> materialized (appointment written) | expired (reaped).
            $table->enum('status', ['pending', 'materialized', 'expired'])->default('pending');

            // slot coordinates — let availableSlotsForDate count this as a held slot
            // without re-parsing the payload.
            $table->foreignUuid('resource_id')->nullable()->constrained('tenant_resources')->onDelete('set null');
            $table->date('booking_date');
            $table->string('appointment_time')->nullable();   // HH:MM:SS, null for day-mode
            $table->unsignedInteger('slot_weight')->default(1);

            $table->unsignedInteger('total_cents')->default(0);

            // payment linkage — the webhook backstop finds the hold by this id.
            $table->string('stripe_payment_intent_id')->nullable();

            // everything needed to build the appointment on success.
            $table->json('payload');

            // light contact snapshot for visibility / recovery.
            $table->string('contact_email')->nullable();
            $table->string('contact_name')->nullable();

            // the materialized appointment, once written.
            $table->foreignUuid('appointment_id')->nullable()->constrained('tenant_appointments')->onDelete('set null');

            $table->timestamp('expires_at');
            $table->timestamps();

            $table->index(['tenant_id', 'status']);
            $table->index(['tenant_id', 'booking_date']);
            $table->index(['tenant_id', 'token']);
            $table->index(['stripe_payment_intent_id']);
            $table->index(['expires_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tenant_pending_bookings');
    }
};
