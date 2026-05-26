<?php
// MARKER-PATCH-149

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tenant_funnel_events', function (Blueprint $table) {
            $table->id();

            $table->uuid('tenant_id');
            $table->foreign('tenant_id')->references('id')->on('tenants')->onDelete('cascade');

            // Anonymous session id (random 32-char string in a cookie).
            // Lets us join multiple events into a session without identifying users.
            $table->string('session_id', 64)->index();

            // page_view | booking_page_viewed | booking_started | booking_completed
            $table->string('event_type', 32);

            // The page where the event happened
            $table->string('path', 255)->nullable();

            // Where they came from (Referer header, before they hit us)
            $table->string('referrer_domain', 191)->nullable();
            $table->string('referrer_url', 2048)->nullable();

            // UTM params for campaign attribution
            $table->string('utm_source',   100)->nullable();
            $table->string('utm_medium',   100)->nullable();
            $table->string('utm_campaign', 191)->nullable();

            // Coarse device bucket (mobile/desktop/tablet/bot) — derived from UA, no fingerprint
            $table->string('device', 12)->nullable();

            // New vs returning (based on session cookie age)
            $table->boolean('is_new_session')->default(true);

            $table->timestamp('created_at')->useCurrent();

            // Composite index for the most common query pattern (tenant + time window)
            $table->index(['tenant_id', 'created_at']);
            $table->index(['tenant_id', 'event_type', 'created_at'], 'tfe_tenant_event_time');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tenant_funnel_events');
    }
};
