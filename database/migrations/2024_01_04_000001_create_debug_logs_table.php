<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * debug_logs — unified logging table for the master admin debug panel.
 *
 * NOTE on id types:
 *   - tenants uses UUIDs  → tenant_id is foreignUuid
 *   - users uses bigint   → resolved_by is unsignedBigInteger
 * Keep these separate so FK constraints match.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('debug_logs', function (Blueprint $table) {
            $table->uuid('id')->primary();

            $table->foreignUuid('tenant_id')->nullable()->constrained()->nullOnDelete();

            $table->enum('channel', [
                'request', 'error', 'job', 'mail', 'sms', 'auth',
                'impersonation', 'audit', 'webhook', 'api', 'system',
            ]);

            $table->enum('severity', [
                'debug', 'info', 'notice', 'warning', 'error', 'critical',
            ])->default('info');

            $table->string('event', 64)->index();
            $table->string('message', 500);

            // Actor — polymorphic ID (either user UUID or tenant_user UUID),
            // stored as string to accommodate both.
            $table->string('actor_type', 64)->nullable();
            $table->string('actor_id', 64)->nullable();
            $table->string('actor_label', 255)->nullable();

            $table->string('subject_type', 64)->nullable();
            $table->string('subject_id', 64)->nullable();
            $table->string('subject_label', 255)->nullable();

            $table->string('method', 10)->nullable();
            $table->string('route', 255)->nullable();
            $table->string('path', 500)->nullable();
            $table->string('host', 255)->nullable();
            $table->unsignedSmallInteger('status_code')->nullable();
            $table->unsignedInteger('duration_ms')->nullable();
            $table->string('ip', 45)->nullable();
            $table->string('user_agent', 500)->nullable();

            $table->string('recipient', 255)->nullable();
            $table->string('template', 128)->nullable();

            $table->string('job_class', 255)->nullable();
            $table->string('queue', 64)->nullable();
            $table->unsignedSmallInteger('attempts')->nullable();

            $table->json('context')->nullable();

            $table->boolean('is_resolved')->default(false);
            $table->timestamp('resolved_at')->nullable();

            // users.id is bigint, not UUID — so this FK is unsignedBigInteger
            $table->unsignedBigInteger('resolved_by')->nullable();
            $table->foreign('resolved_by')->references('id')->on('users')->nullOnDelete();

            $table->text('resolution_note')->nullable();

            $table->string('fingerprint', 64)->nullable()->index();
            $table->uuid('correlation_id')->nullable();

            $table->timestamp('created_at')->useCurrent();

            $table->index(['channel', 'created_at']);
            $table->index(['tenant_id', 'channel', 'created_at']);
            $table->index(['severity', 'created_at']);
            $table->index(['is_resolved', 'severity']);
            $table->index(['actor_type', 'actor_id']);
            $table->index(['subject_type', 'subject_id']);
            $table->index('correlation_id');
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('debug_logs');
    }
};
