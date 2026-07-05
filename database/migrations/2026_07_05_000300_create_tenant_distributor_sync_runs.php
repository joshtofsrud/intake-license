<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// MARKER-PATCH-555 — audit trail for tenant distributor syncs (manual +
// scheduled) so "did it run, what changed" is a glance not a DB query.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tenant_distributor_sync_runs', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('trigger', 20)->default('manual'); // manual|schedule
            $table->boolean('dry_run')->default(false);
            $table->timestamp('started_at');
            $table->timestamp('finished_at')->nullable();
            $table->json('stats')->nullable();
            $table->text('error')->nullable();
            $table->timestamps();
            $table->index(['tenant_id', 'started_at'], 'tdsr_tenant_started');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tenant_distributor_sync_runs');
    }
};
