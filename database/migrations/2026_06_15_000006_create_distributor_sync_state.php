<?php
// MARKER-PATCH-HLC3

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-distributor sync cursor + last-run audit. last_synced_at is the delta
 * watermark: the next --delta run only re-writes variants modified after it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('distributor_sync_state', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('distributor_code', 32);
            $table->string('source_ref', 32)->default('catalog');
            $table->timestamp('last_synced_at')->nullable();
            $table->timestamp('last_run_at')->nullable();
            $table->string('last_status', 16)->nullable();
            $table->integer('last_count')->default(0);
            $table->text('last_error')->nullable();
            $table->timestamps();

            $table->unique(['distributor_code', 'source_ref'], 'dss_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('distributor_sync_state');
    }
};
