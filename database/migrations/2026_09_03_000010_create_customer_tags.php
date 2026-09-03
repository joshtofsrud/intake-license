<?php
// MARKER-CUSTOMER-TAGS

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tenant_customer_tags', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id')->index();
            $table->string('name', 60);
            $table->string('description', 255)->nullable();
            $table->uuid('created_by')->nullable();
            $table->timestamps();

            // One "Bike Hub list" per shop: the unique index is what stops the
            // same segment existing three times under slightly different names.
            $table->unique(['tenant_id', 'name']);
        });

        Schema::create('tenant_customer_tag_pivot', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id')->index();
            $table->uuid('tag_id');
            $table->uuid('customer_id');
            $table->timestamp('created_at')->useCurrent();

            $table->unique(['tag_id', 'customer_id']);
            $table->index(['tenant_id', 'customer_id']);
            $table->foreign('tag_id')->references('id')->on('tenant_customer_tags')->onDelete('cascade');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tenant_customer_tag_pivot');
        Schema::dropIfExists('tenant_customer_tags');
    }
};
