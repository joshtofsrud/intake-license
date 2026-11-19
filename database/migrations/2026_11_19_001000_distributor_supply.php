<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * MARKER-SUPPLY-RUN: one distributor's cost, stock and warehouse stock per part
 * number, fetched once per run for every shop. Shops copy from here; nothing
 * is fetched per shop except part numbers no run has seen yet.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('distributor_supply', function (Blueprint $t) {
            $t->id();
            $t->string('distributor_code', 32);
            $t->string('variant_no', 64);
            $t->integer('cost_cents')->nullable();
            $t->integer('avail')->nullable();
            $t->json('warehouses')->nullable();
            $t->timestamp('fetched_at')->nullable();
            $t->unique(['distributor_code', 'variant_no'], 'dsup_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('distributor_supply');
    }
};
