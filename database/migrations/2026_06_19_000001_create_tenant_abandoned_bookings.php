<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tenant_abandoned_bookings', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id')->index();
            $table->string('session_id', 64)->index();

            // Partial contact info — only captured once a complete value is entered
            $table->string('name', 191)->nullable();
            $table->string('email', 191)->nullable();
            $table->string('phone', 32)->nullable();

            // Where they were when they left
            $table->string('step_reached', 64)->nullable();
            $table->json('partial')->nullable();   // any other fields entered

            // Follow-up worklist state
            $table->string('status', 16)->default('open'); // open | contacted | converted | dismissed
            $table->text('notes')->nullable();
            $table->timestamp('contacted_at')->nullable();

            $table->timestamp('created_at')->nullable()->index();
            $table->timestamp('updated_at')->nullable();

            // One live abandoned record per session — upsert as they progress
            $table->unique(['tenant_id', 'session_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tenant_abandoned_bookings');
    }
};
