<?php
// MARKER-PATCH-635 — cached Stripe payouts for reconciliation. Each row is one
// payout with its charge breakdown (from balance transactions) and how many
// charges couldn't be matched to a ledger payment by PaymentIntent id.

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tenant_stripe_payouts', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->string('payout_id', 64);
            $table->date('arrived_on');
            $table->integer('gross_cents')->default(0);
            $table->integer('fee_cents')->default(0);
            $table->integer('net_cents')->default(0);
            $table->unsignedSmallInteger('charge_count')->default(0);
            $table->unsignedSmallInteger('unmatched_count')->default(0);
            $table->json('items')->nullable(); // [{charge, pi, amount, fee, created, matched}]
            $table->timestamp('fetched_at');
            $table->timestamps();

            $table->unique(['tenant_id', 'payout_id'], 'tsp_tenant_payout_unique');
            $table->index(['tenant_id', 'arrived_on'], 'tsp_tenant_arrived_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tenant_stripe_payouts');
    }
};

