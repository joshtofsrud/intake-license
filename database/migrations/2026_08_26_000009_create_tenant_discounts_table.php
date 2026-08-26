<?php
// MARKER-DISCOUNTS

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tenant_discounts', function (Blueprint $table) {
            $table->uuid('id')->primary();

            $table->uuid('tenant_id')->index();
            $table->foreign('tenant_id')->references('id')->on('tenants')->onDelete('cascade');

            // Stored uppercase, unique per tenant. Two shops can both use SPRING20.
            $table->string('code', 40);
            $table->string('label', 120)->nullable(); // internal description

            // 'percent' (value = 0-100) or 'fixed' (value = cents off)
            $table->string('type', 10);
            $table->unsignedInteger('value');

            // Guardrails
            $table->unsignedInteger('min_subtotal_cents')->default(0);
            // Ceiling on a percent discount, e.g. "20% off, max $50". 0 = none.
            $table->unsignedInteger('max_discount_cents')->default(0);

            $table->timestamp('starts_at')->nullable();
            $table->timestamp('ends_at')->nullable();

            // 0 = unlimited
            $table->unsignedInteger('max_redemptions')->default(0);
            $table->unsignedInteger('max_per_customer')->default(0);

            // Denormalised counter for fast listing. The redemption rows are
            // the source of truth; this is recomputed on redeem, never trusted
            // for limit checks (those count rows under a lock).
            $table->unsignedInteger('redemption_count')->default(0);

            $table->boolean('is_active')->default(true);

            // Set when a discount belongs to a campaign (phase 5). No FK yet.
            $table->uuid('campaign_id')->nullable()->index();

            $table->uuid('created_by')->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'code']);
            $table->index(['tenant_id', 'is_active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tenant_discounts');
    }
};
