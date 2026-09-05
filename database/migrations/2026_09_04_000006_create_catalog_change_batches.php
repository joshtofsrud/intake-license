<?php
// MARKER-CATALOG-HISTORY

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('catalog_change_batches', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id')->index();
            $table->string('action', 32);
            $table->json('filter')->nullable();     // what was selected, in words
            $table->unsignedInteger('item_count')->default(0);
            $table->string('run_by', 191)->nullable();
            $table->timestamp('undone_at')->nullable();
            $table->string('undone_by', 191)->nullable();
            $table->unsignedInteger('restored_count')->default(0);
            $table->unsignedInteger('kept_count')->default(0);   // changed since
            $table->timestamps();

            $table->index(['tenant_id', 'created_at']);
        });

        Schema::create('catalog_change_items', function (Blueprint $table) {
            $table->id();
            $table->uuid('batch_id')->index();
            $table->uuid('tenant_id')->index();
            $table->uuid('item_id')->index();
            // The fields as they were. Only what the action touches is stored.
            $table->json('before');
            // What we wrote, so "changed since" can be detected: if the current
            // value is neither before nor after, someone edited it by hand.
            $table->json('after')->nullable();
            $table->timestamp('restored_at')->nullable();
            $table->timestamps();

            $table->foreign('batch_id')->references('id')->on('catalog_change_batches')->onDelete('cascade');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('catalog_change_items');
        Schema::dropIfExists('catalog_change_batches');
    }
};
