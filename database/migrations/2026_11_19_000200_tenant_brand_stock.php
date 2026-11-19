<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * MARKER-BRAND-STOCK: a shop's dealer stock file for a brand-direct supplier
 * (Kona): one row per part number with the shop's own cost and quantity, and
 * one row per shop describing the file those came from.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('tenant_brand_stock', function (Blueprint $t) {
            $t->id();
            $t->char('tenant_id', 36);
            $t->string('distributor_code', 32);
            $t->string('variant_no', 64);
            $t->string('upc', 32)->nullable();
            $t->string('description', 255)->nullable();
            $t->unsignedSmallInteger('model_year')->nullable();
            $t->integer('cost_cents')->nullable();
            $t->integer('retail_cents')->nullable();
            $t->integer('qty')->nullable();
            $t->timestamps();
            $t->unique(['tenant_id', 'distributor_code', 'variant_no'], 'tbs_unique');
            $t->index(['tenant_id', 'distributor_code'], 'tbs_tenant');
        });

        Schema::create('tenant_brand_stock_files', function (Blueprint $t) {
            $t->id();
            $t->char('tenant_id', 36);
            $t->string('distributor_code', 32);
            $t->string('file_name', 255)->nullable();
            $t->string('source', 16)->default('upload'); // upload | ftp
            $t->unsignedInteger('row_count')->default(0);
            $t->json('check')->nullable();
            $t->timestamp('loaded_at')->nullable();
            $t->timestamp('file_modified_at')->nullable();
            $t->string('last_error', 500)->nullable();
            $t->timestamp('last_attempt_at')->nullable();
            $t->timestamps();
            $t->unique(['tenant_id', 'distributor_code'], 'tbsf_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tenant_brand_stock_files');
        Schema::dropIfExists('tenant_brand_stock');
    }
};
