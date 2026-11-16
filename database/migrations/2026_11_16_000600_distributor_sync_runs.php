<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * MARKER-SYNC-RUNS: one row per shop + distributor per nightly sync, with how
 * long the distributor calls and the writes took and how far along it is.
 * The scheduler throws the command's output away, so this is the record.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('distributor_sync_runs')) return;
        Schema::create('distributor_sync_runs', function (Blueprint $t) {
            $t->id();
            $t->uuid('tenant_id')->index();
            $t->string('subscription_id', 64)->index();
            $t->string('distributor_code', 16);
            $t->string('status', 16)->default('running'); // running | done | failed
            $t->unsignedInteger('linked')->default(0);
            $t->unsignedInteger('processed')->default(0);
            $t->unsignedInteger('fetch_seconds')->nullable();
            $t->unsignedInteger('write_seconds')->nullable();
            $t->string('error', 300)->nullable();
            $t->timestamp('started_at')->nullable()->index();
            $t->timestamp('finished_at')->nullable();
            $t->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('distributor_sync_runs');
    }
};
