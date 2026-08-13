<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// MARKER-GIFTCARDS — one row per card. balance_cents is a cache; the
// transactions ledger is the source of truth and can rebuild it.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tenant_gift_cards', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained()->cascadeOnDelete();

            $table->string('code', 40);                  // normalized, stored uppercase
            $table->string('type', 12);                  // physical | egift
            $table->string('status', 16)->default('active'); // pending | active | used | deactivated

            $table->integer('original_cents');
            $table->integer('balance_cents');

            $table->uuid('purchaser_customer_id')->nullable();
            $table->string('purchaser_name')->nullable();   // online buys with no customer row
            $table->string('purchaser_email')->nullable();
            $table->string('recipient_name')->nullable();
            $table->string('recipient_email')->nullable();
            $table->text('gift_message')->nullable();
            $table->date('deliver_on')->nullable();         // tenant-local; null = immediately
            $table->timestamp('delivered_at')->nullable();

            $table->uuid('issued_sale_id')->nullable();     // register sale that sold it
            $table->uuid('issued_by_user_id')->nullable();
            $table->string('stripe_payment_intent_id')->nullable(); // online purchase (patch 3)

            $table->timestamp('deactivated_at')->nullable();
            $table->string('deactivated_reason')->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'code']);
            $table->index(['tenant_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tenant_gift_cards');
    }
};
