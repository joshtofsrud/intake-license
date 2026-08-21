<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// MARKER-RENTAL-EXT — last-minute extension offers. One row per offer
// episode; the magic-link token is the customer's whole identity here.
return new class extends Migration {
    public function up(): void
    {
        Schema::create('tenant_rental_extension_offers', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id')->index();
            $table->uuid('rental_id');
            $table->string('token', 48)->unique();
            $table->string('status', 20)->default('sent'); // sent|paid|declined|expired|cancelled
            $table->string('channel', 20)->default('auto'); // auto|manual
            $table->dateTime('offer_from');
            $table->dateTime('extend_to');
            $table->unsignedInteger('discount_pct')->default(0);
            $table->integer('subtotal_cents')->default(0);
            $table->integer('tax_cents')->default(0);
            $table->integer('total_cents')->default(0);
            $table->dateTime('sent_at')->nullable();
            $table->dateTime('responded_at')->nullable();
            $table->dateTime('expires_at')->nullable();
            $table->string('stripe_payment_intent_id')->nullable();
            $table->uuid('sale_id')->nullable();
            $table->json('meta')->nullable();
            $table->timestamps();
            $table->index(['tenant_id', 'rental_id']);
        });
    }
    public function down(): void
    {
        Schema::dropIfExists('tenant_rental_extension_offers');
    }
};
