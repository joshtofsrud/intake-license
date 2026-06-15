<?php
// MARKER-PATCH-HLC13

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('distributor_brand_sync_status', function (Blueprint $table) {
            $table->id();
            $table->string('distributor_code', 32);
            $table->string('brand_name', 128);
            $table->unsignedInteger('total')->default(0);
            $table->unsignedInteger('written')->default(0);
            $table->string('status', 16)->default('pending'); // pending|syncing|done
            $table->timestamps();
            $table->unique(['distributor_code', 'brand_name']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('distributor_brand_sync_status');
    }
};
