<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// MARKER-PATCH-509 — route windows: the capacity unit for pickup & delivery.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tenant_route_windows', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('label', 40);            // "8–10 am"
            $table->time('starts_at');              // tenant-local wall clock
            $table->time('ends_at');                //   (naive, per time standard)
            $table->json('days');                   // ISO weekday ints [1..7], 1=Mon
            $table->unsignedSmallInteger('max_stops')->default(3);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['tenant_id', 'is_active', 'sort_order'], 'trw_tenant_active');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tenant_route_windows');
    }
};
