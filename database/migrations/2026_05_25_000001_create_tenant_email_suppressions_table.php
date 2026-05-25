<?php
// MARKER-PATCH-146

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tenant_email_suppressions', function (Blueprint $table) {
            $table->id();

            // tenant_id NULL = platform-wide suppression (complaints,
            // and addresses that bounced from 3+ different tenants).
            $table->uuid('tenant_id')->nullable()->index();
            $table->foreign('tenant_id')->references('id')->on('tenants')->onDelete('cascade');

            // Normalised lowercase email
            $table->string('email', 255)->index();

            // 'bounce' (permanent), 'transient_bounce', 'complaint', 'manual', 'unsubscribe'
            $table->string('reason', 32);

            // 'permanent' or 'transient' from SES bounce subType, or null
            $table->string('subtype', 64)->nullable();

            // The SES message ID that triggered this suppression (for audit)
            $table->string('source_message_id', 128)->nullable()->index();

            // SES diagnostic code (e.g. "5.1.1 user unknown")
            $table->text('diagnostic')->nullable();

            // Free-form notes (manual suppressions)
            $table->text('notes')->nullable();

            // Who suppressed this (NULL for system-driven)
            $table->uuid('suppressed_by_user_id')->nullable();

            $table->timestamp('suppressed_at')->useCurrent();
            $table->timestamps();

            // One row per (tenant, email) pair. Platform-wide rows have tenant_id NULL.
            $table->unique(['tenant_id', 'email'], 'tenant_email_suppression_unique');
        });

        Schema::create('tenant_email_bounce_events', function (Blueprint $table) {
            // Raw event log — every bounce/complaint we receive, regardless of
            // whether it triggered a suppression. Used for trend analysis and
            // to enforce "3+ tenants bounced → platform suppression" rule.
            $table->id();
            $table->uuid('tenant_id')->nullable()->index();
            $table->foreign('tenant_id')->references('id')->on('tenants')->onDelete('cascade');
            $table->string('email', 255)->index();
            $table->string('event_type', 24);   // 'bounce' or 'complaint'
            $table->string('bounce_type', 24)->nullable();   // 'Permanent' / 'Transient' / 'Undetermined'
            $table->string('bounce_subtype', 64)->nullable(); // 'General' / 'NoEmail' / 'Suppressed' / ...
            $table->string('source_message_id', 128)->nullable()->index();
            $table->json('payload')->nullable();   // Full SNS message for forensics
            $table->timestamp('received_at')->useCurrent();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tenant_email_bounce_events');
        Schema::dropIfExists('tenant_email_suppressions');
    }
};
