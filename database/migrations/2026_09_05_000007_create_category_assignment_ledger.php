<?php
// MARKER-CAT-UNDO

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tenant_category_assignments', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id')->index();
            $table->string('bucket_key', 160)->nullable();
            $table->uuid('category_id');
            $table->string('category_name', 160);
            $table->unsignedInteger('item_count')->default(0);
            $table->string('source', 12)->default('hand');   // hand | rule | model
            $table->uuid('created_by')->nullable();
            $table->timestamp('undone_at')->nullable();
            $table->unsignedInteger('kept_count')->default(0);
            $table->timestamps();
            $table->index(['tenant_id', 'created_at']);
        });

        Schema::create('tenant_category_assignment_items', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('assignment_id')->index();
            $table->uuid('item_id')->index();
            $table->uuid('prior_category_id')->nullable();
            $table->timestamp('restored_at')->nullable();
            $table->foreign('assignment_id')->references('id')->on('tenant_category_assignments')->onDelete('cascade');
        });

        Schema::create('tenant_bucket_rules', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->string('bucket_key', 160);
            $table->uuid('category_id');
            $table->unsignedInteger('hits')->default(1);
            $table->timestamp('last_used_at')->nullable();
            $table->timestamps();
            $table->unique(['tenant_id', 'bucket_key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tenant_bucket_rules');
        Schema::dropIfExists('tenant_category_assignment_items');
        Schema::dropIfExists('tenant_category_assignments');
    }
};
