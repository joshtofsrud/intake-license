<?php
// MARKER-CAMPAIGNS-CORE

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tenant_email_campaigns', function (Blueprint $table) {
            $table->id();

            $table->uuid('tenant_id')->index();
            $table->foreign('tenant_id')->references('id')->on('tenants')->onDelete('cascade');

            $table->string('name', 160);
            // 'draft', 'scheduled', 'sending', 'sent', 'canceled'
            $table->string('status', 16)->default('draft')->index();

            $table->string('subject', 200)->nullable();
            // Builder blocks (next patch) — kept as data from day one so a
            // draft made today survives the builder landing.
            $table->json('body_blocks')->nullable();
            // Audience definition (segment patch) — same reasoning.
            $table->json('segment')->nullable();

            $table->timestamp('scheduled_for')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->unsignedInteger('recipients_count')->default(0);

            $table->unsignedBigInteger('created_by')->nullable();

            $table->timestamps();

            $table->index(['tenant_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tenant_email_campaigns');
    }
};
