<?php
// MARKER-PATCH-221 — unified inbox (threads + messages) + SMS opt-out.

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tenant_threads', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('tenant_id')->constrained('tenants')->onDelete('restrict');
            // Rail 3: indexed customer link — the timeline reads this.
            $t->foreignUuid('customer_id')->constrained('tenant_customers')->onDelete('restrict');
            $t->string('channel', 12)->default('sms');      // sms | email (phase 2) | mixed
            $t->string('status', 16)->default('open');      // open | needs_reply | closed
            $t->string('subject')->nullable();
            $t->foreignUuid('assigned_user_id')->nullable()->constrained('tenant_users')->nullOnDelete();
            $t->dateTime('last_message_at')->nullable();
            $t->dateTime('last_inbound_at')->nullable();
            $t->unsignedInteger('unread_count')->default(0);
            $t->timestamps();
            $t->index(['tenant_id', 'status', 'last_message_at'], 'tt_tenant_status_recent');
            $t->index(['tenant_id', 'customer_id', 'channel'], 'tt_tenant_customer_channel');
        });

        Schema::create('tenant_messages', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('thread_id')->constrained('tenant_threads')->onDelete('cascade');
            $t->string('direction', 8);                     // in | out | system
            $t->string('kind', 16)->default('message');     // message | offer_card | system_event | internal_note
            $t->text('body');
            $t->json('meta')->nullable();                   // offer_id, rental_id, appointment_id, …
            $t->string('channel', 12)->default('sms');
            $t->foreignUuid('sent_by_user_id')->nullable()->constrained('tenant_users')->nullOnDelete();
            $t->string('external_id', 64)->nullable();      // Twilio MessageSid (inbound dedupe)
            $t->dateTime('delivered_at')->nullable();
            $t->dateTime('read_at')->nullable();
            $t->timestamps();
            $t->index('thread_id', 'tm_thread');
            $t->index('external_id', 'tm_external');
        });

        Schema::table('tenant_customers', function (Blueprint $t) {
            // STOP compliance — regulatory, not a feature flag. Every SMS
            // send path must respect sms_opt_out_at.
            $t->timestamp('sms_opt_out_at')->nullable()->after('phone');
            $t->string('sms_consent_source', 40)->nullable()->after('sms_opt_out_at');
        });
    }

    public function down(): void
    {
        Schema::table('tenant_customers', function (Blueprint $t) {
            $t->dropColumn(['sms_opt_out_at', 'sms_consent_source']);
        });
        Schema::dropIfExists('tenant_messages');
        Schema::dropIfExists('tenant_threads');
    }
};
