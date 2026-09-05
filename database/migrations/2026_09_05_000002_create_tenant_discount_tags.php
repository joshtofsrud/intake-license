<?php
// MARKER-PROMO-TAGS

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tenant_discount_tags', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id')->index();
            $table->uuid('discount_id');
            $table->uuid('tag_id');
            $table->timestamp('created_at')->useCurrent();

            $table->unique(['discount_id', 'tag_id']);
            $table->foreign('discount_id')->references('id')->on('tenant_discounts')->onDelete('cascade');
            $table->foreign('tag_id')->references('id')->on('tenant_customer_tags')->onDelete('cascade');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tenant_discount_tags');
    }
};
