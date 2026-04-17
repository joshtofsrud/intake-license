<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * debug_logs — unified logging table for the master admin debug panel.
 *
 * This is the one place every interesting event across the platform lands:
 *   - HTTP requests (master admin + tenant admin + public)
 *   - Exceptions / errors
 *   - Outgoing emails (transactional + campaigns)
 *   - Outgoing SMS (once Twilio is wired)
 *   - Queue jobs (processed, failed, retried)
 *   - Auth events (login success/failure, password resets)
 *   - Impersonation start/stop
 *   - Tenant admin audit events (settings changed, user created, etc.)
 *   - Webhook calls (incoming Stripe/PayPal, outbound once we add them)
 *
 * Partitioning strategy: queries always filter on (channel, created_at)
 * or (tenant_id, channel, created_at), so those are the indexes.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('debug_logs', function (Blueprint $table) {
            $table->uuid('id')->primary();

            // Which tenant this event belongs to. Null = platform-level event
            // (master admin action, marketing site request, unhandled platform exception).
            $table->foreignUuid('tenant_id')->nullable()->constrained()->nullOnDelete();

            /*
             * Channel — the bucket this event goes into. Maps 1:1 to the
             * tabs/filters in the debug panel.
             */
            $table->enum('channel', [
                'request',        // HTTP request lifecycle
                'error',          // Unhandled exception
                'job',            // Queue job
                'mail',           // Outgoing email
                'sms',            // Outgoing SMS
                'auth',           // Login / logout / password reset / failed login
                'impersonation',  // Master admin impersonating a tenant
                'audit',          // Tenant admin changed something important
                'webhook',        // Incoming webhook (Stripe, PayPal, etc)
                'api',            // API call (from WP plugin or tenant API once shipped)
                'system',         // Catch-all for anything that doesn't fit
            ]);

            /*
             * Severity — for filtering and coloring in the UI.
             * 'debug'    = verbose, only kept briefly
             * 'info'     = normal operation (request, mail sent, job done)
             * 'notice'   = worth noticing but not a problem (slow request, retry)
             * 'warning'  = degraded but not broken (webhook retry, mail soft bounce)
             * 'error'    = request failed, job failed, hard bounce
             * 'critical' = needs attention now (payment failed, auth breach, DB down)
             */
            $table->enum('severity', [
                'debug', 'info', 'notice', 'warning', 'error', 'critical',
            ])->default('info');

            // Short machine-readable event name. Examples:
            //   'request.completed', 'request.failed',
            //   'mail.sent', 'mail.bounced',
            //   'auth.login', 'auth.login_failed', 'auth.password_reset',
            //   'job.completed', 'job.failed',
            //   'audit.settings_updated', 'audit.user_invited'
            $table->string('event', 64)->index();

            // Human-readable one-line summary. Shown in the table row.
            $table->string('message', 500);

            // Who did this (if applicable). Polymorphic because it can be
            // either App\Models\User (master admin) or App\Models\Tenant\TenantUser.
            $table->string('actor_type', 64)->nullable();
            $table->uuid('actor_id')->nullable();
            $table->string('actor_label', 255)->nullable(); // cached "Name <email>" for display

            // Target of the event (if applicable) — same polymorphic pattern.
            // e.g. an audit event acting on a TenantServiceItem, or a mail
            // event targeting a TenantCustomer.
            $table->string('subject_type', 64)->nullable();
            $table->uuid('subject_id')->nullable();
            $table->string('subject_label', 255)->nullable();

            // Request context (filled for channel=request, and copied onto
            // errors/audits that occurred during a request).
            $table->string('method', 10)->nullable();
            $table->string('route', 255)->nullable();       // route name if matched
            $table->string('path', 500)->nullable();         // actual URI path
            $table->string('host', 255)->nullable();         // tenant subdomain comes through here
            $table->unsignedSmallInteger('status_code')->nullable();
            $table->unsignedInteger('duration_ms')->nullable();
            $table->string('ip', 45)->nullable();
            $table->string('user_agent', 500)->nullable();

            // For mail/sms: the recipient and which template was used.
            $table->string('recipient', 255)->nullable();
            $table->string('template', 128)->nullable();

            // For job channel: job class + queue + attempts.
            $table->string('job_class', 255)->nullable();
            $table->string('queue', 64)->nullable();
            $table->unsignedSmallInteger('attempts')->nullable();

            // Everything else goes here: stack trace, exception class, full
            // payload, diff of changes, webhook body, etc. This is where
            // errors store the trace and audits store old/new values.
            $table->json('context')->nullable();

            // For errors — mark as handled so they disappear from "needs
            // attention" views without being deleted.
            $table->boolean('is_resolved')->default(false);
            $table->timestamp('resolved_at')->nullable();
            $table->foreignUuid('resolved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('resolution_note')->nullable();

            // For grouping recurring errors — hash of (exception_class, file, line).
            // Lets us show "this error has happened 47 times" without scanning.
            $table->string('fingerprint', 64)->nullable()->index();

            // Correlation ID — the same value across every log line produced
            // during a single request. Clicking a request row filters to
            // everything that happened in that request.
            $table->uuid('correlation_id')->nullable();

            $table->timestamp('created_at')->useCurrent();

            // -------- Indexes -------------------------------------------------
            // These cover the query patterns the Filament table will issue.
            $table->index(['channel', 'created_at']);
            $table->index(['tenant_id', 'channel', 'created_at']);
            $table->index(['severity', 'created_at']);
            $table->index(['is_resolved', 'severity']);
            $table->index(['actor_type', 'actor_id']);
            $table->index(['subject_type', 'subject_id']);
            $table->index('correlation_id');
            $table->index('created_at'); // for retention pruning
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('debug_logs');
    }
};
