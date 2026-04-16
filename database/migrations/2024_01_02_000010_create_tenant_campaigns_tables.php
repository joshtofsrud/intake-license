<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // ----------------------------------------------------------------
        // Campaigns
        // ----------------------------------------------------------------
        Schema::create('tenant_campaigns', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained()->cascadeOnDelete();

            $table->string('name');  // internal name for the admin

            /*
             * Campaign types:
             *   bulk        — one-off send to full customer list or segment
             *   targeted    — automated: lapsed customers (configurable window)
             *   follow_up   — automated: post-service (configurable delay)
             */
            $table->enum('type', ['bulk', 'targeted', 'follow_up']);

            /*
             * Status:
             *   draft     — being set up
             *   scheduled — set to send at a future time
             *   sending   — actively being sent
             *   sent      — completed
             *   paused    — automated campaign paused
             *   active    — automated campaign running
             */
            $table->enum('status', ['draft', 'scheduled', 'sending', 'sent', 'paused', 'active'])
                  ->default('draft');

            // Email content
            $table->string('subject');
            $table->text('body_html');
            $table->text('body_text')->nullable();

            // Targeting config (JSON)
            // bulk:      { "segment": "all" | "has_appointment" }
            // targeted:  { "lapsed_days": 90 }
            // follow_up: { "delay_days": 3, "trigger": "completed" | "closed" }
            $table->json('targeting')->nullable();

            // Scheduling
            $table->timestamp('scheduled_at')->nullable();
            $table->timestamp('sent_at')->nullable();

            // Stats (updated as sends complete)
            $table->unsignedInteger('total_recipients')->default(0);
            $table->unsignedInteger('total_sent')->default(0);
            $table->unsignedInteger('total_opened')->default(0);
            $table->unsignedInteger('total_clicked')->default(0);

            $table->foreignUuid('created_by')->nullable()->constrained('tenant_users')->nullOnDelete();
            $table->timestamps();

            $table->index(['tenant_id', 'status']);
            $table->index(['tenant_id', 'type']);
        });

        // ----------------------------------------------------------------
        // Campaign sends — one row per recipient per campaign
        // ----------------------------------------------------------------
        Schema::create('tenant_campaign_sends', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('campaign_id')->constrained('tenant_campaigns')->cascadeOnDelete();
            $table->foreignUuid('customer_id')->nullable()->constrained('tenant_customers')->nullOnDelete();
            $table->string('email');  // snapshot of address at send time

            $table->enum('status', ['pending', 'sent', 'failed', 'bounced'])->default('pending');

            // Open / click tracking
            $table->string('tracking_token', 64)->unique()->nullable();
            $table->unsignedSmallInteger('open_count')->default(0);
            $table->unsignedSmallInteger('click_count')->default(0);

            $table->timestamp('sent_at')->nullable();
            $table->timestamp('opened_at')->nullable();
            $table->timestamp('clicked_at')->nullable();
            $table->text('error_message')->nullable();

            $table->timestamp('created_at')->useCurrent();

            $table->index(['campaign_id', 'status']);
            $table->index('tracking_token');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tenant_campaign_sends');
        Schema::dropIfExists('tenant_campaigns');
    }
};
