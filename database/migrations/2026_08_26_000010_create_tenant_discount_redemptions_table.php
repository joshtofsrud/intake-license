<?php
// MARKER-DISCOUNTS

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tenant_discount_redemptions', function (Blueprint $table) {
            $table->uuid('id')->primary();

            $table->uuid('tenant_id')->index();
            $table->foreign('tenant_id')->references('id')->on('tenants')->onDelete('cascade');

            $table->uuid('discount_id')->index();
            $table->foreign('discount_id')->references('id')->on('tenant_discounts')->onDelete('cascade');

            // The sale it was applied to. Nullable so a redemption survives a
            // sale being deleted — the money given away still happened.
            $table->uuid('sale_id')->nullable()->index();
            $table->uuid('customer_id')->nullable()->index();

            // What was ACTUALLY taken off, after caps and clamping. Never
            // recompute this from the rule: rules change, history doesn't.
            $table->unsignedInteger('amount_cents');
            $table->unsignedInteger('subtotal_cents')->default(0);

            // Snapshot of the code as typed/stored at the time.
            $table->string('code', 40);

            $table->uuid('redeemed_by_user_id')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'created_at']);
            $table->index(['discount_id', 'customer_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tenant_discount_redemptions');
    }
};
