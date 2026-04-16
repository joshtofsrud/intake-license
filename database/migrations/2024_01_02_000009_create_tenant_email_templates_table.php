<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tenant_email_templates', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained()->cascadeOnDelete();

            /*
             * Template types (one per tenant per type):
             *   booking_confirmation    — sent when booking is created
             *   status_confirmed        — booking confirmed by staff
             *   status_in_progress      — work has started
             *   status_completed        — ready for pickup
             *   status_shipped          — shipped back to customer
             *   status_closed           — job closed
             *   status_cancelled        — booking cancelled
             *   reminder_day_before     — 24hr reminder
             *   reminder_day_of         — morning-of reminder
             *   follow_up               — post-service follow-up / review request
             */
            $table->string('template_type', 60);

            $table->string('subject');
            $table->text('body_html');       // full HTML, supports {{variables}}
            $table->text('body_text')->nullable(); // plain text fallback

            $table->boolean('is_enabled')->default(true);

            // Delay in minutes after the trigger event (for follow-ups / reminders)
            $table->unsignedInteger('send_delay_minutes')->default(0);

            $table->timestamps();

            $table->unique(['tenant_id', 'template_type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tenant_email_templates');
    }
};
