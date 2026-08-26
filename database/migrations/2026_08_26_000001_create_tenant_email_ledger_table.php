<?php
// MARKER-EMAIL-LEDGER

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tenant_email_ledger', function (Blueprint $table) {
            $table->id();

            $table->uuid('tenant_id')->index();
            $table->foreign('tenant_id')->references('id')->on('tenants')->onDelete('cascade');

            // 'campaign', 'receipt', 'reminder', 'reply', 'staff', 'other'
            $table->string('kind', 24)->index();

            // The EmailService template key that produced it (null for campaigns).
            $table->string('template_key', 64)->nullable();

            $table->string('to_email', 255);

            // Dollars per email AT SEND TIME. A price change never re-prices
            // rows already written — each row keeps the rate it was charged at.
            $table->decimal('rate', 8, 5);

            // Postmark message stream. 'outbound' = transactional default;
            // campaigns will carry the configured broadcast stream.
            $table->string('stream', 32)->default('outbound');

            // 'pending' (written before the send), 'sent', 'voided'.
            // A row stuck at pending means the process died mid-send —
            // reconciliation surfaces those; they are never billed.
            $table->string('status', 12)->default('pending')->index();

            // Future FK to campaigns (phase 3); no constraint yet.
            $table->unsignedBigInteger('campaign_id')->nullable()->index();

            $table->timestamps();

            // The billing query: this tenant, this month, by kind.
            $table->index(['tenant_id', 'created_at']);
            $table->index(['tenant_id', 'kind', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tenant_email_ledger');
    }
};
