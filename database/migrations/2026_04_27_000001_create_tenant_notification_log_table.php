<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Audit log for transactional notifications sent on behalf of a tenant.
 * Spans booking confirmations, status updates, reminders, cancellations,
 * and any future automated comms. One row per (event, channel) attempt.
 *
 * Why a single table vs columns on each domain row:
 *  - Notifications are a cross-cutting concern (booking, appointment status,
 *    waitlist, etc.) — adding columns to each domain table doesn't scale.
 *  - Support tickets ("I didn't get the email") need a single place to look.
 *  - Reminders and digest emails will reuse this same audit shape.
 *
 * At 10K+ tenants this table is high-write, mostly-read-by-tenant. Indexed by
 * (tenant_id, related_type, related_id, created_at) for fast per-row lookups,
 * plus (tenant_id, created_at) for tenant-wide audit trails.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('tenant_notification_log', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('tenant_id')->constrained()->cascadeOnDelete();

            // What kind of notification this is — booking_confirmation,
            // status_update, reminder, cancellation, etc. Free-form string
            // so new notification types don't need a schema change.
            $t->string('event_type', 64);

            // Which channel — email or sms. Future: 'push', 'webhook'.
            $t->string('channel', 16);

            // Who it was sent to (raw address so we have the audit trail
            // even if the customer record gets edited later).
            $t->string('recipient', 191);

            // Polymorphic-lite reference to the originating row.
            // related_type = 'appointment' | 'waitlist_offer' | etc.
            // related_id   = the UUID of the source row.
            // Not a real FK because the source can be any of several tables.
            $t->string('related_type', 32)->nullable();
            $t->uuid('related_id')->nullable();

            // Outcome.
            $t->enum('status', ['sent', 'failed', 'skipped'])->default('sent');
            $t->text('error_message')->nullable();

            // Optional template key used (helps debug "did the right template fire?").
            $t->string('template_key', 64)->nullable();

            $t->timestamps();

            $t->index(['tenant_id', 'created_at']);
            $t->index(['tenant_id', 'related_type', 'related_id']);
            $t->index(['tenant_id', 'event_type', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tenant_notification_log');
    }
};
