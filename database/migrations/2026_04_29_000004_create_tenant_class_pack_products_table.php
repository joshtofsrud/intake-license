<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tenant_class_pack_products', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained()->cascadeOnDelete();

            $table->string('name');
            $table->text('description')->nullable();

            $table->unsignedSmallInteger('credit_count');
            $table->unsignedSmallInteger('expiry_days');
            $table->unsignedInteger('price_cents');
            $table->boolean('is_active')->default(true);
            $table->json('metadata')->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->index(['tenant_id', 'is_active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tenant_class_pack_products');
    }
};
