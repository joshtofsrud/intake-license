<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('support_conversations', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('customer_id')->nullable()->constrained('tenant_customers')->nullOnDelete();

            // Who initiated: customer (from booking form) or staff (from shop admin)
            $table->enum('initiated_by', ['customer', 'staff'])->default('customer');

            // Channel: widget (embedded chat), email, or admin (internal)
            $table->enum('channel', ['widget', 'email', 'admin'])->default('widget');

            $table->enum('status', ['open', 'pending_staff', 'resolved'])->default('open');

            // Customer contact info snapshot (in case customer_id is null)
            $table->string('customer_email')->nullable();
            $table->string('customer_name')->nullable();

            /*
             * Messages stored as JSON array:
             * [
             *   { "role": "customer", "content": "...", "at": "2024-01-01T10:00:00Z" },
             *   { "role": "assistant", "content": "...", "at": "2024-01-01T10:00:05Z" },
             *   { "role": "staff", "content": "...", "at": "2024-01-01T10:05:00Z" }
             * ]
             */
            $table->json('messages')->nullable();

            /*
             * Snapshot of tenant context at conversation start — injected into
             * Claude's system prompt so it has relevant business info.
             * {
             *   "shop_name": "...",
             *   "services": [...],
             *   "recent_appointment": {...},
             *   "customer_history": {...}
             * }
             */
            $table->json('context_snapshot')->nullable();

            // Set when Claude flags it needs human help or staff takes over
            $table->boolean('needs_staff')->default(false);
            $table->foreignUuid('assigned_to')->nullable()->constrained('tenant_users')->nullOnDelete();

            // For tracking Claude API usage per conversation
            $table->unsignedInteger('total_tokens_used')->default(0);

            $table->timestamp('last_message_at')->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'status']);
            $table->index(['tenant_id', 'needs_staff']);
            $table->index('last_message_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('support_conversations');
    }
};
