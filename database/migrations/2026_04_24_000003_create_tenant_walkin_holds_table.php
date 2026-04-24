<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tenant_walkin_holds', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained()->cascadeOnDelete();

            // resource_id NOT nullable — a walk-in hold is always for a specific resource
            // (a bench, a stylist). Shop-wide "don't take online bookings" is a different
            // concept (business-hours or temporary closure), not a walk-in hold.
            $table->foreignUuid('resource_id')
                  ->constrained('tenant_resources')->cascadeOnDelete();

            // Time window
            $table->dateTime('starts_at');
            $table->dateTime('ends_at');

            // If set: hold auto-converts to bookable (online) at this time.
            // Use case: hold 2:30-3:30 for walk-ins, release at 2:45 if no one walked in.
            $table->dateTime('auto_release_at')->nullable();

            $table->string('notes')->nullable();

            // Recurrence — same shape as breaks for consistency
            $table->boolean('is_recurring')->default(false);
            $table->enum('recurrence_type', ['daily', 'weekly', 'monthly'])->nullable();
            $table->json('recurrence_config')->nullable();
            $table->date('recurrence_until')->nullable();

            // Tracking — did this hold convert into a real appointment?
            // When admin books a walk-in customer into the held window,
            // the hold is marked converted and the appointment_id stored.
            $table->foreignUuid('converted_to_appointment_id')->nullable()
                  ->constrained('tenant_appointments')->nullOnDelete();
            $table->dateTime('converted_at')->nullable();

            $table->foreignUuid('created_by_user_id')->nullable();

            $table->timestamps();

            // Core query: "active holds for this resource, this date range"
            $table->index(['tenant_id', 'resource_id', 'starts_at']);

            // Auto-release sweep query: "holds past their auto_release_at still not converted"
            $table->index(['auto_release_at', 'converted_at']);

            // Recurrence expansion
            $table->index(['tenant_id', 'is_recurring', 'recurrence_until']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tenant_walkin_holds');
    }
};
