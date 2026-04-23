<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Stripe webhook idempotency log.
 *
 * Stripe will retry deliveries on any non-2xx response, and may redeliver
 * the same event multiple times even on success (network blips, etc.). We
 * record the event ID on first successful processing, and ignore any
 * subsequent deliveries with the same ID. Stripe event IDs are globally
 * unique (format: evt_1ABC...).
 *
 * Retention: we prune rows older than 30 days via scheduled task (future).
 * Stripe only retries for up to 3 days, so 30d is ample.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stripe_webhook_events', function (Blueprint $t) {
            $t->id();
            $t->string('event_id', 255)->unique();
            $t->string('type', 100);
            $t->timestamp('received_at')->useCurrent();
            $t->timestamp('processed_at')->nullable();
            $t->text('error')->nullable();
            $t->index('type');
            $t->index('received_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stripe_webhook_events');
    }
};
