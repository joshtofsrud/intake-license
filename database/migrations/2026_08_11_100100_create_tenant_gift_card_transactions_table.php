<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// MARKER-GIFTCARDS — the ledger. amount_cents is signed (+credit / −debit);
// balance_after_cents lets the detail screen render running balances without
// re-summing on every row.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tenant_gift_card_transactions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('gift_card_id')->constrained('tenant_gift_cards')->cascadeOnDelete();

            $table->string('kind', 16);                 // issue | redeem | adjust | deactivate
            $table->integer('amount_cents');            // signed
            $table->integer('balance_after_cents');
            $table->uuid('sale_id')->nullable();
            $table->string('note')->nullable();
            $table->uuid('user_id')->nullable();
            $table->timestamps();

            $table->index(['gift_card_id', 'created_at']);
            $table->index(['tenant_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tenant_gift_card_transactions');
    }
};
